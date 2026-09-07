<?php
class YSRTech_EmailCampaigns_Model_Observer
{
    /** Cron: launch campaigns whose schedule is due. */
    public function launchScheduledCampaigns()
    {
        Mage::getSingleton('ysrtech_emailcampaigns/sender')->launchScheduledCampaigns();
    }

    /** Cron: process a batch of the send queue. */
    public function processQueue()
    {
        Mage::getSingleton('ysrtech_emailcampaigns/sender')->processQueue();
    }

    /**
     * Cron: bring Mailgun's unsubscribes, bounces and complaints back into the
     * store, so the queue stops enqueueing people who have opted out and the
     * subscriber grid shows what is actually true.
     *
     * @return $this
     */
    public function syncSuppressions()
    {
        $counts = Mage::getSingleton('ysrtech_emailcampaigns/suppressions')->sync();

        foreach ($counts as $list => $count) {
            if ($count > 0) {
                Mage::log("EmailCampaigns: {$count} address(es) unsubscribed from Mailgun's {$list} list.", Zend_Log::INFO);
            }
        }

        return $this;
    }

    /** Cron/event: reindex all active segments. */
    public function reindexSegments()
    {
        $segments = Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')
            ->addFieldToFilter('is_active', 1);
        foreach ($segments as $segment) {
            try {
                $segment->reindex();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
    }
}
