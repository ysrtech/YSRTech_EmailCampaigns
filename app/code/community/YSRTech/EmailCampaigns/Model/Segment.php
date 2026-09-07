<?php
/**
 * Segment model built on Magento's rule engine (same as catalog/sales promo rules).
 *
 * The condition tree is the module's own rather than salesrule's. Salesrule's
 * conditions describe a basket at checkout - Subtotal, Shipping Method - and a
 * subscriber has none of those, so a segment built from them matched nobody and
 * threw on validate() as soon as a reindex ran. Segment_Condition_Subscriber
 * offers the fields _matches() actually supplies.
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
        return Mage::getModel('ysrtech_emailcampaigns/segment_condition_combine');
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
     * Drop the serialized conditions once they are safely in the row.
     *
     * _beforeSave() writes conditions_serialized back onto the model, and
     * Mage_Rule's getConditions() loads that string into the condition tree it
     * is already holding - appending to it rather than replacing it. So saving
     * the same instance twice doubles every condition, and reindex() saves the
     * instance the controller just saved. A segment edited twice ended up with
     * each of its rules listed four times.
     *
     * @return $this
     */
    protected function _afterSave()
    {
        $this->unsConditionsSerialized();

        return parent::_afterSave();
    }

    /**
     * Campaigns that name this segment, described the way the admin will read
     * them: the campaign's name and whether it includes or excludes it.
     *
     * @return string[]
     */
    public function getCampaignUsage(): array
    {
        if (!$this->getId()) {
            return [];
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $rows = $adapter->fetchAll(
            $adapter->select()
                ->from(['cs' => $resource->getTableName('ysrtech_emailcampaigns/campaign_segment')], ['is_excluded'])
                ->join(
                    ['c' => $resource->getTableName('ysrtech_emailcampaigns/campaign')],
                    'c.campaign_id = cs.campaign_id',
                    ['name']
                )
                ->where('cs.segment_id = ?', (int) $this->getId())
                ->order('c.name')
        );

        $helper = Mage::helper('ysrtech_emailcampaigns');

        return array_map(
            static fn($row) => sprintf(
                '%s (%s)',
                $row['name'],
                $row['is_excluded'] ? $helper->__('excluded') : $helper->__('included')
            ),
            $rows
        );
    }

    /**
     * The subscribers this segment's rule matches.
     *
     * The audience is the newsletter, not the customer table: customers are
     * subscribed automatically, so subscribers are the superset, and on this
     * store most of them have no customer account at all. Keying this on
     * customers made every one of those unreachable.
     *
     * @return int[] Subscriber IDs
     */
    public function getMatchingSubscriberIds(): array
    {
        $ids = [];

        foreach ($this->_streamCandidates() as $row) {
            if ($this->_matches($row)) {
                $ids[] = (int) $row['subscriber_id'];
            }
        }

        return $ids;
    }

    /**
     * Walk every subscribed address with its order history attached.
     *
     * One query, read a row at a time. The previous version loaded every
     * customer as a full EAV model and then ran a separate aggregate query per
     * customer - 12,001 queries and ~92 MB at this store's size. The order
     * figures are now a single grouped join, and nothing is held but the row
     * in hand.
     *
     * @return Generator
     */
    protected function _streamCandidates()
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $orders = $adapter->select()
            ->from(
                ['o' => $resource->getTableName('sales/order')],
                [
                    'customer_id',
                    'order_count'   => new Zend_Db_Expr('COUNT(o.entity_id)'),
                    'total_spent'   => new Zend_Db_Expr('COALESCE(SUM(o.base_grand_total), 0)'),
                    'last_order_at' => new Zend_Db_Expr('MAX(o.created_at)'),
                ]
            )
            ->where('o.customer_id IS NOT NULL')
            ->where("o.state NOT IN ('canceled')")
            ->group('o.customer_id');

        $select = $adapter->select()
            ->from(
                ['ns' => $resource->getTableName('newsletter/subscriber')],
                [
                    'subscriber_id',
                    'email'     => 'subscriber_email',
                    'firstname' => 'subscriber_firstname',
                    'lastname'  => 'subscriber_lastname',
                    'customer_id',
                    'store_id',
                ]
            )
            ->joinLeft(
                ['agg' => new Zend_Db_Expr('(' . $orders . ')')],
                'agg.customer_id = ns.customer_id',
                [
                    'order_count'   => new Zend_Db_Expr('COALESCE(agg.order_count, 0)'),
                    'total_spent'   => new Zend_Db_Expr('COALESCE(agg.total_spent, 0)'),
                    'last_order_at' => 'last_order_at',
                ]
            )
            ->where('ns.subscriber_status = ?', Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED);

        $statement = $adapter->query($select);

        while ($row = $statement->fetch()) {
            yield $row;
        }
    }

    /**
     * @param  array $row
     * @return bool
     */
    protected function _matches(array $row): bool
    {
        $conditions = $this->getConditions();

        if (!$conditions instanceof Mage_Rule_Model_Condition_Interface) {
            return true; // an empty rule matches everyone
        }

        $lastOrderAt = $row['last_order_at'] ?: null;

        $data = [
            'subscriber_id'       => (int) $row['subscriber_id'],
            'email'               => $row['email'],
            'firstname'           => $row['firstname'],
            'lastname'            => $row['lastname'],
            'customer_id'         => $row['customer_id'] ? (int) $row['customer_id'] : null,
            'is_customer'         => $row['customer_id'] ? 1 : 0,
            'store_id'            => (int) $row['store_id'],
            'order_count'         => (int) $row['order_count'],
            'total_spent'         => (float) $row['total_spent'],
            'average_order_value' => $row['order_count'] > 0
                ? $row['total_spent'] / $row['order_count']
                : 0,
            'last_order_at'       => $lastOrderAt,
            /*
             * Kept as a number because that is what a rule can compare.
             * Somebody who has never ordered is not "0 days ago" - a large
             * sentinel keeps them out of "ordered recently" and inside
             * "has not ordered in a while", which is how a reader expects
             * both rules to behave.
             */
            'last_order_days_ago' => $lastOrderAt
                ? (int) floor((time() - strtotime($lastOrderAt)) / 86400)
                : 99999,
        ];

        // Mage_Rule reads its operands off a Varien_Object via getData()
        return (bool) $conditions->validate(new Varien_Object($data));
    }

    /**
     * Recalculate membership and cache the count.
     *
     * @return int Subscribers matched
     */
    public function reindex(): int
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/segment_subscriber');

        // Read once and close over it: the mapping closure below is static,
        // so it has no $this to ask.
        $segmentId = (int) $this->getId();

        if (!$segmentId) {
            Mage::throwException('Save the segment before reindexing it.');
        }

        $ids = $this->getMatchingSubscriberIds();

        $adapter->beginTransaction();

        try {
            $adapter->delete($table, ['segment_id = ?' => $segmentId]);

            foreach (array_chunk($ids, 500) as $chunk) {
                $adapter->insertArray(
                    $table,
                    ['segment_id', 'subscriber_id'],
                    array_map(static fn ($id) => [$segmentId, (int) $id], $chunk)
                );
            }

            /*
             * addData, not setData: setData given an array replaces the
             * object's data outright, which would drop segment_id and turn
             * this save into an insert of a second, nameless segment.
             */
            $this->addData([
                'customer_count'    => count($ids),
                'last_reindexed_at' => Varien_Date::now(),
            ]);
            $this->save();

            $adapter->commit();

            return count($ids);
        } catch (Throwable $e) {
            /*
             * Throwable, not Exception: a TypeError from a condition is an
             * Error, which an Exception catch lets past - leaving the
             * transaction open until the connection is destroyed and the
             * adapter throws over it, burying the real cause.
             */
            $adapter->rollBack();
            throw $e;
        }
    }
}
