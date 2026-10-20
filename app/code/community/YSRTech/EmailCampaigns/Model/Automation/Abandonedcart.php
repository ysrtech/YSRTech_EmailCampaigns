<?php
/**
 * Finds carts that were left behind and queues the nudge.
 *
 * "Abandoned" is a guess dressed up as a fact: a cart is only abandoned in
 * the sense that nobody has touched it for a while and no order came of it.
 * The window has both ends for a reason - older than the threshold, so a
 * customer still shopping is left alone, and newer than a day beyond it, so
 * switching the feature on does not mail everybody who ever abandoned a cart.
 */
class YSRTech_EmailCampaigns_Model_Automation_Abandonedcart
{
    /** How far back the first run reaches, in hours beyond the threshold */
    private const LOOKBACK_HOURS = 24;

    /**
     * @return int Number of nudges queued
     */
    public function process(): int
    {
        $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;

        $automations = Mage::getResourceModel('ysrtech_emailcampaigns/automation_collection')
            ->addFieldToFilter('event', $source::ABANDONED_CART)
            ->addFieldToFilter('is_active', 1);

        if (!$automations->getSize()) {
            return 0;
        }

        $hours  = (int) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/abandoned_cart_hours');
        $hours  = $hours > 0 ? $hours : 1;
        $queued = 0;

        foreach ($this->_abandonedQuotes($hours) as $quote) {
            foreach ($automations as $automation) {
                $storeId = (int) $automation->getStoreId();

                if ($storeId && $storeId !== (int) $quote['store_id']) {
                    continue;
                }

                $queued += Mage::getModel('ysrtech_emailcampaigns/automation')
                    ->load($automation->getId())
                    ->queue(
                        (string) $quote['customer_email'],
                        'quote',
                        (int) $quote['entity_id'],
                        $quote['customer_id'] ?: null,
                        $quote['store_id']
                    ) ? 1 : 0;
            }
        }

        return $queued;
    }

    /**
     * @param  int $hours
     * @return array
     */
    protected function _abandonedQuotes(int $hours): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $now  = time();
        $from = gmdate('Y-m-d H:i:s', $now - (($hours + self::LOOKBACK_HOURS) * 3600));
        $to   = gmdate('Y-m-d H:i:s', $now - ($hours * 3600));

        $select = $adapter->select()
            ->from(
                ['q' => $resource->getTableName('sales/quote')],
                ['entity_id', 'store_id', 'customer_id', 'customer_email']
            )
            ->where('q.is_active = ?', 1)
            ->where('q.items_count > ?', 0)
            ->where('q.customer_email IS NOT NULL')
            ->where("q.customer_email != ''")
            ->where('q.updated_at >= ?', $from)
            ->where('q.updated_at <= ?', $to)
            /*
             * A quote that became an order is not abandoned. reserved_order_id
             * is set when checkout starts placing one, so it also excludes
             * carts caught mid-payment - which would otherwise be nudged
             * moments before the confirmation arrives.
             */
            ->where('q.reserved_order_id IS NULL OR q.reserved_order_id = ?', '');

        return $adapter->fetchAll($select);
    }
}
