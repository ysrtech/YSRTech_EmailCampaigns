<?php
/**
 * Segment model built on Magento's rule engine (same as catalog/sales promo rules).
 * Conditions support the full customer attribute set plus order-history aggregates
 * via the salesrule condition combine.
 */
class YSRTech_EmailCampaigns_Model_Segment extends Mage_Rule_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/segment');
    }

    /**
     * Mage_Rule integration: where the condition tree lives.
     */
    public function getConditionsInstance()
    {
        return Mage::getModel('salesrule/rule_condition_combine');
    }

    /**
     * Mage_Rule_Model_Abstract declares this abstract alongside
     * getConditionsInstance(), so the class does not load without it. A segment
     * only ever selects customers - there is nothing for a rule action to do -
     * so this is the empty collection the rule engine expects.
     */
    public function getActionsInstance()
    {
        return Mage::getModel('rule/action_collection');
    }

    /*
     * conditions_serialized is Mage_Rule_Model_Abstract's own column and it
     * handles both ends: _beforeSave() writes serialize($conditions->asArray())
     * and getConditions() reads it back through core/unserializeArray. Writing
     * JSON into it instead - as this model and the save controller both used to
     * - meant the very next getConditions() fed JSON to unserialize and the
     * save died with "Error unserializing data." Nothing to override here.
     */

    /**
     * Build the SELECT of matching customer IDs by evaluating the rule
     * against every active customer.
     */
    public function getMatchingCustomerIds(): array
    {
        /** @var Mage_Customer_Model_Resource_Customer_Collection $collection */
        $collection = Mage::getResourceModel('customer/customer_collection')
            ->addAttributeToSelect('*');

        $ids = [];
        foreach ($collection as $customer) {
            if ($this->_matchesCustomer($customer)) {
                $ids[] = (int) $customer->getId();
            }
        }
        return $ids;
    }

    private function _matchesCustomer(Mage_Customer_Model_Customer $customer): bool
    {
        // Enrich with order history aggregates used by order conditions.
        $agg = $this->_getOrderAggregates((int) $customer->getId());
        $data = array_merge($customer->getData(), [
            'order_count'         => $agg['count'],
            'total_spent'         => $agg['total'],
            'last_order_days_ago' => $agg['days_since_last'],
            'average_order_value' => $agg['count'] > 0 ? $agg['total'] / $agg['count'] : 0,
        ]);

        $conditions = $this->getConditions();
        if (!$conditions instanceof Mage_Rule_Model_Condition_Interface) {
            return true; // empty rule matches everyone
        }
        // Mage_Rule conditions read their operands off a Varien_Object with
        // getData(); handed a bare array they raise a TypeError.
        return (bool) $conditions->validate(new Varien_Object($data));
    }

    private function _getOrderAggregates(int $customerId): array
    {
        static $cache = [];
        if (isset($cache[$customerId])) {
            return $cache[$customerId];
        }
        /** @var Mage_Core_Model_Resource $res */
        $res = Mage::getSingleton('core/resource');
        $conn = $res->getConnection('core_read');
        $row = $conn->fetchRow(
            $conn->select()
                ->from(['o' => $res->getTableName('sales/order')], [
                    'count'           => new Zend_Db_Expr('COUNT(entity_id)'),
                    'total'           => new Zend_Db_Expr('COALESCE(SUM(base_grand_total),0)'),
                    'days_since_last' => new Zend_Db_Expr('COALESCE(DATEDIFF(NOW(), MAX(created_at)), 99999)'),
                ])
                ->where('customer_id = ?', $customerId)
                ->where("state NOT IN ('canceled')")
        );
        return $cache[$customerId] = [
            'count'           => (int) $row['count'],
            'total'           => (float) $row['total'],
            'days_since_last' => (int) $row['days_since_last'],
        ];
    }

    /**
     * Recalculate membership: replace segment_customer rows and cache count.
     */
    public function reindex(): int
    {
        /** @var Mage_Core_Model_Resource $res */
        $res = Mage::getSingleton('core/resource');
        $conn = $res->getConnection('core_write');
        $linkTable = $res->getTableName('ysrtech_emailcampaigns/segment_customer');

        // Read once and close over the value: the mapping closure below is
        // static, so it has no $this to ask.
        $segmentId = (int) $this->getId();

        $conn->beginTransaction();
        try {
            $conn->delete($linkTable, ['segment_id = ?' => $segmentId]);
            $ids = $this->getMatchingCustomerIds();
            if ($ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $conn->insertArray($linkTable, ['segment_id', 'customer_id'], array_map(
                        static fn ($id) => [$segmentId, (int) $id],
                        $chunk
                    ));
                }
            }
            /*
             * addData, not setData: setData given an array replaces the object's
             * data outright, which would drop segment_id and turn this save into
             * an insert of a second, nameless segment.
             */
            $this->addData([
                'customer_count'    => count($ids),
                'last_reindexed_at' => Varien_Date::now(),
            ]);
            $this->save();
            $conn->commit();
            return count($ids);
        } catch (Throwable $e) {
            /*
             * Throwable, not Exception: a TypeError from a condition is an
             * Error, which an Exception catch lets past - leaving the
             * transaction open until the connection is destroyed and the
             * adapter throws over it, burying the real cause.
             */
            $conn->rollBack();
            throw $e;
        }
    }
}
