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
        $helper = Mage::helper('ysrtech_emailcampaigns');
        $batchSize = max(1, (int) $helper->getConfig('sending/batch_size') ?: 100);
        $maxAttempts = max(1, (int) $helper->getConfig('sending/max_attempts') ?: 3);

        /** @var YSRTech_EmailCampaigns_Model_Resource_Queue_Collection $queue */
        $queue = Mage::getResourceModel('ysrtech_emailcampaigns/queue_collection')
            ->addFieldToFilter('status', 'pending')
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
                'unsubscribe_url' => Mage::getUrl('emailcampaigns/preferences/unsubscribe', [
                    '_token' => $item->getTrackingToken(),
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

        // Send using first rendered HTML (merge vars are provider-side per recipient).
        $transport->sendBatch(
            array_map(static fn ($r) => ['email' => $r['email'], 'name' => $r['name'], 'vars' => $r['vars']], $recipients),
            (string) $campaign->getSubject(),
            $recipients[0]['_html']
        );

        foreach ($recipients as $r) {
            /** @var YSRTech_EmailCampaigns_Model_Queue $item */
            $item = $r['_item'];
            // setData() with an array replaces the whole record (id included), which
            // would turn this save into an INSERT of a duplicate row; addData() merges.
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
