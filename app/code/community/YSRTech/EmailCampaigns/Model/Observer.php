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

    /**
     * An order was placed.
     *
     * sales_order_place_after rather than a save event: it fires once, when
     * the order is actually placed, rather than on every later save of the
     * same order.
     *
     * @param Varien_Event_Observer $observer
     */
    public function orderPlaced($observer)
    {
        $this->_guard(function () use ($observer) {
            /** @var Mage_Sales_Model_Order $order */
            $order  = $observer->getEvent()->getOrder();
            $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;

            if (!$order || !$order->getId()) {
                return;
            }

            foreach ($this->_automations($source::NEW_ORDER, $order->getStoreId()) as $automation) {
                $automation->queue(
                    (string) $order->getCustomerEmail(),
                    'order',
                    (int) $order->getId(),
                    $order->getCustomerId(),
                    $order->getStoreId()
                );
            }

            // Bought a particular product
            $productIds = [];

            foreach ($order->getAllVisibleItems() as $item) {
                $productIds[] = (int) $item->getProductId();
            }

            if (!$productIds) {
                return;
            }

            foreach ($this->_automations($source::PRODUCT_PURCHASED, $order->getStoreId()) as $automation) {
                if (in_array((int) $automation->getProductId(), $productIds, true)) {
                    $automation->queue(
                        (string) $order->getCustomerEmail(),
                        'order',
                        (int) $order->getId(),
                        $order->getCustomerId(),
                        $order->getStoreId()
                    );
                }
            }
        });
    }

    /**
     * An order reached a status.
     *
     * @param Varien_Event_Observer $observer
     */
    public function orderStatusChanged($observer)
    {
        $this->_guard(function () use ($observer) {
            /** @var Mage_Sales_Model_Order $order */
            $order = $observer->getEvent()->getOrder();

            if (!$order || !$order->getId()) {
                return;
            }

            $status = (string) $order->getStatus();

            // Only when it actually changed, or every subsequent save of the
            // order would queue the same message again
            if ($status === '' || $status === (string) $order->getOrigData('status')) {
                return;
            }

            $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;

            foreach ($this->_automations($source::ORDER_STATUS, $order->getStoreId()) as $automation) {
                if ((string) $automation->getOrderStatus() !== $status) {
                    continue;
                }

                /*
                 * Keyed on the status as well as the order: one order can
                 * legitimately trigger a processing email and later a complete
                 * one, and they are different messages about different events.
                 */
                $automation->queue(
                    (string) $order->getCustomerEmail(),
                    'order_status_' . $status,
                    (int) $order->getId(),
                    $order->getCustomerId(),
                    $order->getStoreId()
                );
            }
        });
    }

    /**
     * A shipment, invoice or credit memo was created.
     *
     * One handler for the three because the shape is identical: a document
     * hanging off an order, whose customer gets told about it.
     *
     * @param Varien_Event_Observer $observer
     */
    public function orderDocumentCreated($observer)
    {
        $this->_guard(function () use ($observer) {
            $event  = $observer->getEvent();
            $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;

            $map = [
                'shipment'   => [$event->getShipment(), $source::SHIPMENT],
                'invoice'    => [$event->getInvoice(), $source::INVOICE],
                'creditmemo' => [$event->getCreditmemo(), $source::CREDITMEMO],
            ];

            foreach ($map as $type => $pair) {
                [$document, $trigger] = $pair;

                if (!$document || !$document->getId()) {
                    continue;
                }

                $order = $document->getOrder();

                if (!$order || !$order->getId()) {
                    continue;
                }

                foreach ($this->_automations($trigger, $order->getStoreId()) as $automation) {
                    $automation->queue(
                        (string) $order->getCustomerEmail(),
                        $type,
                        (int) $document->getId(),
                        $order->getCustomerId(),
                        $order->getStoreId()
                    );
                }
            }
        });
    }

    /**
     * Cron: find carts left behind and queue the nudge.
     */
    public function processAbandonedCarts()
    {
        $this->_guard(function () {
            Mage::getSingleton('ysrtech_emailcampaigns/automation_abandonedcart')->process();
        });
    }

    /**
     * @param  string $event
     * @param  int    $storeId
     * @return YSRTech_EmailCampaigns_Model_Resource_Automation_Collection
     */
    protected function _automations(string $event, $storeId)
    {
        return Mage::getResourceModel('ysrtech_emailcampaigns/automation_collection')
            ->addEventFilter($event, $storeId);
    }

    /**
     * Run something without letting it take the page down with it.
     *
     * These observers fire inside a customer's checkout. A newsletter rule
     * that cannot find its template is not a reason to fail somebody's order,
     * so the failure is logged and the order proceeds.
     *
     * @param  callable $work
     * @return void
     */
    protected function _guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Mage::logException($e);
        }
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
