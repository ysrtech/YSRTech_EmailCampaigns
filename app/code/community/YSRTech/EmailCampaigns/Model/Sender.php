<?php
/**
 * Cron-driven sender: launches scheduled campaigns and processes the queue
 * in batches through the configured transport.
 */
class YSRTech_EmailCampaigns_Model_Sender
{
    /**
     * Find campaigns due for sending, build their queues, mark sending.
     */
    public function launchScheduledCampaigns(): void
    {
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/campaign_collection')
            ->addFieldToFilter('status', YSRTech_EmailCampaigns_Model_Campaign::STATUS_SCHEDULED)
            ->addFieldToFilter('scheduled_at', ['lteq' => Varien_Date::now()]);

        foreach ($collection as $campaign) {
            try {
                $count = $campaign->buildQueue();
                $campaign
                    ->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SENDING)
                    ->save();
                Mage::log("EmailCampaigns: campaign #{$campaign->getId()} queued ({$count} recipients).",
                    Zend_Log::INFO);
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
    }

    /**
     * How long a claim may sit before another run may take the rows back.
     * Long enough that a slow batch is never stolen mid-send.
     */
    private const CLAIM_TIMEOUT_MINUTES = 30;

    /**
     * Send one batch of queued messages.
     */
    public function processQueue(): void
    {
        /*
         * The store's own "Disable Email Communications" switch. A campaign is
         * email like any other, and a store with sending turned off - staging,
         * a restore, a migration in progress - must not have this cron blast
         * its whole customer list because it goes out through a transport of
         * its own rather than Mage_Core_Model_Email_Template.
         */
        if (Mage::getStoreConfigFlag('system/smtp/disable')) {
            return;
        }

        $helper      = Mage::helper('ysrtech_emailcampaigns');
        $batchSize   = max(1, (int) $helper->getConfig('sending/batch_size') ?: 100);
        $maxAttempts = max(1, (int) $helper->getConfig('sending/max_attempts') ?: 3);

        $this->_releaseStaleClaims();

        $token = $this->_claim($batchSize);

        if ($token === null) {
            return;
        }

        /** @var YSRTech_EmailCampaigns_Model_Resource_Queue_Collection $queue */
        $queue = Mage::getResourceModel('ysrtech_emailcampaigns/queue_collection')
            ->addFieldToFilter('lock_token', $token);

        $byCampaign = [];

        foreach ($queue as $item) {
            $byCampaign[(int) $item->getCampaignId()][] = $item;
        }

        foreach ($byCampaign as $items) {
            try {
                $this->_sendCampaignBatch($items, $maxAttempts);
            } catch (Throwable $e) {
                Mage::logException($e);

                foreach ($items as $item) {
                    $this->_markFailure($item, $e->getMessage(), $maxAttempts);
                }
            }
        }
    }

    /**
     * Take ownership of up to $batchSize due rows, and return the token they
     * were stamped with.
     *
     * This is what makes the queue safe to run quickly. Selecting pending rows
     * and sending them leaves nothing to stop a second cron run, started while
     * the first is still going, from selecting the very same rows and mailing
     * everybody twice. At the default hundred a tick that never happened
     * because a tick finished in a second; raising the batch to get 12,000 out
     * in an hour is exactly what would have exposed it.
     *
     * The UPDATE is the lock: whichever run gets there first stamps the rows,
     * and the other finds nothing left to claim.
     *
     * @param  int $batchSize
     * @return string|null Token, or null when nothing was due
     */
    protected function _claim(int $batchSize): ?string
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        $token = Mage::helper('core')->getRandomString(32);
        $now   = Varien_Date::now();

        $claimed = $adapter->query(
            "UPDATE {$table}
                SET status = 'sending', lock_token = ?, locked_at = ?
              WHERE status = 'pending'
                AND lock_token IS NULL
                AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
              ORDER BY queue_id
              LIMIT {$batchSize}",
            [$token, $now, $now]
        )->rowCount();

        return $claimed > 0 ? $token : null;
    }

    /**
     * Give back rows claimed by a run that never finished - a fatal, a deploy
     * mid-batch, a killed process - so they are not stranded in "sending"
     * forever.
     */
    protected function _releaseStaleClaims(): void
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::CLAIM_TIMEOUT_MINUTES . ' minutes'));

        $adapter->update(
            $table,
            ['status' => 'pending', 'lock_token' => null, 'locked_at' => null],
            ['status = ?' => 'sending', 'locked_at < ?' => $cutoff]
        );
    }

    private function _sendCampaignBatch(array $items, int $maxAttempts): void
    {
        /** @var YSRTech_EmailCampaigns_Model_Campaign $campaign */
        $campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->load($items[0]->getCampaignId());

        if (!$campaign->getId()) {
            return;
        }

        /** @var YSRTech_EmailCampaigns_Model_Template $template */
        $template  = Mage::getModel('ysrtech_emailcampaigns/template')->load($campaign->getTemplateId());
        $renderer  = Mage::getSingleton('ysrtech_emailcampaigns/renderer');
        $transport = Mage::getSingleton('ysrtech_emailcampaigns/transport_factory')->get();
        $now       = Varien_Date::now();

        $names     = $this->_loadRecipientNames($items);
        $storeName = Mage::app()->getStore($campaign->getStoreId())->getName();
        $delegates = $transport->supportsRecipientVariables();

        /*
         * When the provider expands variables itself, the template is rendered
         * once with its placeholders left in and every recipient shares that
         * body - which is what turns a twelve thousand person send into a
         * couple of dozen requests. When it does not, each person's copy is
         * rendered here instead.
         */
        $sharedHtml = $delegates
            ? $renderer->render($template, $this->_placeholderVars($storeName))
            : null;

        $recipients = [];

        foreach ($items as $item) {
            $id   = (int) $item->getSubscriberId();
            $name = $names[$id] ?? ['firstname' => '', 'lastname' => ''];

            $vars = [
                'customer' => [
                    'firstname' => $name['firstname'],
                    'lastname'  => $name['lastname'],
                    'email'     => $item->getEmail(),
                ],
                'store'           => ['name' => $storeName],
                'unsubscribe_url' => $this->_unsubscribeUrl($campaign, $item, $delegates),
            ];

            $recipients[] = [
                'email' => $item->getEmail(),
                'name'  => trim($name['firstname'] . ' ' . $name['lastname']),
                'vars'  => $vars,
                'html'  => $delegates ? $sharedHtml : $renderer->render($template, $vars),
                '_item' => $item,
            ];
        }

        $transport->sendBatch(
            array_map(static fn ($r) => [
                'email' => $r['email'],
                'name'  => $r['name'],
                'vars'  => $r['vars'],
                'html'  => $r['html'],
            ], $recipients),
            (string) $campaign->getSubject(),
            $delegates ? $sharedHtml : $recipients[0]['html']
        );

        foreach ($recipients as $r) {
            /** @var YSRTech_EmailCampaigns_Model_Queue $item */
            $item = $r['_item'];

            // addData: setData given an array replaces the row's data
            // outright, dropping queue_id and turning the save into an insert.
            $item->addData([
                'status'     => 'sent',
                'sent_at'    => $now,
                'attempts'   => (int) $item->getAttempts() + 1,
                'lock_token' => null,
                'locked_at'  => null,
            ])->save();
        }

        // If no pending rows remain for this campaign, mark it sent.
        $remaining = Mage::getResourceModel('ysrtech_emailcampaigns/queue_collection')
            ->addFieldToFilter('campaign_id', $campaign->getId())
            ->addFieldToFilter('status', ['in' => ['pending', 'sending']])
            ->getSize();

        if ($remaining === 0 && $campaign->getStatus() === YSRTech_EmailCampaigns_Model_Campaign::STATUS_SENDING) {
            $campaign
                ->setStatus(YSRTech_EmailCampaigns_Model_Campaign::STATUS_SENT)
                ->setSentAt($now)
                ->save();
        }
    }

    /**
     * The variable set for a body the provider will personalise.
     *
     * Every per-recipient value is left as the provider's own placeholder, so
     * one rendered body serves the whole batch and the provider fills in each
     * person's values as it delivers.
     *
     * @param  string $storeName
     * @return array
     */
    protected function _placeholderVars(string $storeName): array
    {
        return [
            'customer' => [
                'firstname' => '%recipient.firstname%',
                'lastname'  => '%recipient.lastname%',
                'email'     => '%recipient.email%',
            ],
            'store'           => ['name' => $storeName],
            'unsubscribe_url' => '%unsubscribe_url%',
        ];
    }

    /**
     * Where the unsubscribe link points.
     *
     * With a provider that handles unsubscribes, it is the provider's hosted
     * page: it records the opt-out on its own suppression list and will not
     * deliver to that address again, and the store plays no part in the
     * recipient's side of it. Otherwise the link comes back here.
     *
     * @param  YSRTech_EmailCampaigns_Model_Campaign $campaign
     * @param  YSRTech_EmailCampaigns_Model_Queue    $item
     * @param  bool                                  $providerHandlesIt
     * @return string
     */
    protected function _unsubscribeUrl($campaign, $item, bool $providerHandlesIt): string
    {
        if ($providerHandlesIt) {
            return '%unsubscribe_url%';
        }

        /*
         * _nosid, because this runs from cron: without it getUrl() reaches for
         * the frontend session to decide about a session id and dies with
         * "Unable to start session" on the command line. _store so the link
         * points at the campaign's own store, and the token as a plain
         * parameter - Magento reads keys starting with an underscore as url
         * options, so "_token" was being swallowed rather than put in the link.
         */
        return Mage::getUrl('emailcampaigns/preferences/unsubscribe', [
            'token'  => $item->getTrackingToken(),
            '_store' => $campaign->getStoreId(),
            '_nosid' => true,
        ]);
    }

    /**
     * Names for a batch, in one query.
     *
     * The previous version loaded a full customer EAV model per recipient
     * purely to read a first and last name - the single most expensive thing
     * in the send path. The newsletter row already carries both, for people
     * with an account and without one alike.
     *
     * @param  array $items
     * @return array Subscriber ID => ['firstname' => ..., 'lastname' => ...]
     */
    protected function _loadRecipientNames(array $items): array
    {
        $ids = array_filter(array_map(static fn ($i) => (int) $i->getSubscriberId(), $items));

        if (!$ids) {
            return [];
        }

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $select = $adapter->select()
            ->from(
                $resource->getTableName('newsletter/subscriber'),
                ['subscriber_id', 'customer_id', 'subscriber_firstname', 'subscriber_lastname']
            )
            ->where('subscriber_id IN (?)', $ids);

        $names       = [];
        $customerIds = [];

        foreach ($adapter->fetchAll($select) as $row) {
            $subscriberId = (int) $row['subscriber_id'];

            $names[$subscriberId] = [
                'firstname' => (string) $row['subscriber_firstname'],
                'lastname'  => (string) $row['subscriber_lastname'],
            ];

            /*
             * subscriber_firstname is only filled in when somebody subscribed
             * through a form that asked for a name - on this store it is empty
             * on every row, while the linked customer records do have names.
             * Collect those and fetch them together below.
             */
            if ($names[$subscriberId]['firstname'] === '' && !empty($row['customer_id'])) {
                $customerIds[(int) $row['customer_id']] = $subscriberId;
            }
        }

        if ($customerIds) {
            /** @var Mage_Customer_Model_Resource_Customer_Collection $customers */
            $customers = Mage::getResourceModel('customer/customer_collection')
                ->addAttributeToSelect(['firstname', 'lastname'])
                ->addFieldToFilter('entity_id', ['in' => array_keys($customerIds)]);

            // One collection for the batch, not a model load per recipient
            foreach ($customers as $customer) {
                $subscriberId = $customerIds[(int) $customer->getId()] ?? null;

                if ($subscriberId !== null) {
                    $names[$subscriberId] = [
                        'firstname' => (string) $customer->getFirstname(),
                        'lastname'  => (string) $customer->getLastname(),
                    ];
                }
            }
        }

        return $names;
    }

    private function _markFailure(YSRTech_EmailCampaigns_Model_Queue $item, string $error, int $maxAttempts): void
    {
        $attempts = (int) $item->getAttempts() + 1;
        $item->setAttempts($attempts)->setErrorMessage(substr($error, 0, 60000));

        // Release the claim either way, or the row sits in "sending" until the
        // stale-claim sweep picks it up half an hour later.
        $item->setLockToken(null)->setLockedAt(null);

        if ($attempts >= $maxAttempts) {
            $item->setStatus('failed');
        } else {
            // Exponential backoff: 5, 20, 45... minutes.
            $delay = 5 * $attempts * $attempts;
            $item->setStatus('pending')
                ->setNextAttemptAt(date('Y-m-d H:i:s', strtotime("+{$delay} minutes")));
        }
        $item->save();
    }
}
