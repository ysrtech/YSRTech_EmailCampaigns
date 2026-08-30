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

    /** Event (sales_order_place_after): enroll the buyer into matching order_placed flows. */
    public function onOrderPlaced(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId()) {
            return;
        }

        // store_id = 0 means "All Store Views" (enrolls regardless of which
        // store the order was placed on); a flow scoped to a specific store
        // view only enrolls orders placed on that one.
        $flows = Mage::getResourceModel('ysrtech_emailcampaigns/flow_collection')
            ->addFieldToFilter('status', YSRTech_EmailCampaigns_Model_Flow::STATUS_ACTIVE)
            ->addFieldToFilter('trigger_type', 'order_placed')
            ->addFieldToFilter('store_id', ['in' => [0, (int) $order->getStoreId()]]);

        $engine = Mage::getSingleton('ysrtech_emailcampaigns/flow_engine');
        foreach ($flows as $flow) {
            try {
                $engine->enroll($flow, (int) $order->getCustomerId(), (string) $order->getCustomerEmail(), [
                    'order_id' => (int) $order->getId(),
                ]);
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }
    }

    /** Cron: advance every due flow enrollment one node. */
    public function processFlowEnrollments()
    {
        Mage::getSingleton('ysrtech_emailcampaigns/flow_engine')->processEnrollments();
    }
}
