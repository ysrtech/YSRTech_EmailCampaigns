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
