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
     * Process one batch of pending queue rows.
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

        $helper = Mage::helper('ysrtech_emailcampaigns');
        $batchSize = max(1, (int) $helper->getConfig('sending/batch_size') ?: 100);
        $maxAttempts = max(1, (int) $helper->getConfig('sending/max_attempts') ?: 3);

        /** @var YSRTech_EmailCampaigns_Model_Resource_Queue_Collection $queue */
        $queue = Mage::getResourceModel('ysrtech_emailcampaigns/queue_collection')
            ->addFieldToFilter('status', 'pending')
            // A row waiting out its backoff is not due yet. Without this the
            // delay _markFailure() sets is ignored and every retry fires on
            // the next cron tick.
            ->addFieldToFilter('next_attempt_at', [
                ['null' => true],
                ['lteq' => Varien_Date::now()],
            ])
            ->setPageSize($batchSize)
            ->setCurPage(1)
            ->load();

        if (!count($queue)) {
            return;
        }

        // Group by campaign so each batch shares subject/template/transport.
        $byCampaign = [];
        foreach ($queue as $item) {
            $byCampaign[(int) $item->getCampaignId()][] = $item;
        }

        foreach ($byCampaign as $campaignId => $items) {
            try {
                // The method takes the items and reads the campaign off the
                // first of them; passing the id as well made the int land in
                // the array parameter, which is a TypeError.
                $this->_sendCampaignBatch($items, $maxAttempts);
            } catch (Exception $e) {
                Mage::logException($e);
                // Mark all items in this failed batch for retry.
                foreach ($items as $item) {
                    $this->_markFailure($item, $e->getMessage(), $maxAttempts);
                }
            }
        }
    }

    private function _sendCampaignBatch(array $items, int $maxAttempts): void
    {
        /** @var YSRTech_EmailCampaigns_Model_Campaign $campaign */
        $campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->load($items[0]->getCampaignId());
        if (!$campaign->getId()) {
            return;
        }

        /** @var YSRTech_EmailCampaigns_Model_Template $template */
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load($campaign->getTemplateId());
        $renderer = Mage::getSingleton('ysrtech_emailcampaigns/renderer');
        $transport = Mage::getSingleton('ysrtech_emailcampaigns/transport_factory')->get();
        $now = Varien_Date::now();

        $recipients = [];
        foreach ($items as $item) {
            $customer = Mage::getModel('customer/customer')->load($item->getCustomerId());
            $vars = [
                'customer' => [
                    'firstname' => $customer->getFirstname(),
                    'lastname'  => $customer->getLastname(),
                    'email'     => $customer->getEmail(),
                ],
                'store' => ['name' => Mage::app()->getStore($campaign->getStoreId())->getName()],
                /*
                 * _nosid, because this runs from cron: without it getUrl()
                 * reaches for the frontend session to decide about a session id
                 * and dies with "Unable to start session" on the command line.
                 * _store so the link points at the campaign's own store, and
                 * the token as a plain parameter - Magento reads keys starting
                 * with an underscore as url options, so "_token" was being
                 * swallowed rather than put in the link.
                 */
                'unsubscribe_url' => Mage::getUrl('emailcampaigns/preferences/unsubscribe', [
                    'token'   => $item->getTrackingToken(),
                    '_store'  => $campaign->getStoreId(),
                    '_nosid'  => true,
                ]),
            ];
            $html = $renderer->render($template, $vars);

            $recipients[] = [
                'email' => $item->getEmail(),
                'name'  => trim((string) $customer->getFirstname()),
                'vars'  => $vars,
                '_item' => $item,
                '_html' => $html,
            ];
        }

        /*
         * Each recipient carries the copy rendered for them. A transport that
         * expands merge variables provider-side can ignore that and use the
         * shared body; one that cannot - anything sending real messages itself
         * - must not, or every recipient is posted the first person's email,
         * their name in the greeting included.
         */
        $transport->sendBatch(
            array_map(static fn ($r) => [
                'email' => $r['email'],
                'name'  => $r['name'],
                'vars'  => $r['vars'],
                'html'  => $r['_html'],
            ], $recipients),
            (string) $campaign->getSubject(),
            $recipients[0]['_html']
        );

        foreach ($recipients as $r) {
            /** @var YSRTech_EmailCampaigns_Model_Queue $item */
            $item = $r['_item'];
            // addData: setData given an array replaces the row's data
            // outright, dropping queue_id and turning the save into an insert.
            $item->addData([
                'status'   => 'sent',
                'sent_at'  => $now,
                'attempts' => (int) $item->getAttempts() + 1,
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

    private function _markFailure(YSRTech_EmailCampaigns_Model_Queue $item, string $error, int $maxAttempts): void
    {
        $attempts = (int) $item->getAttempts() + 1;
        $item->setAttempts($attempts)->setErrorMessage(substr($error, 0, 60000));

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
