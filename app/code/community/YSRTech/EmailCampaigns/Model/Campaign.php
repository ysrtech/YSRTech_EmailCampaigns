<?php
class YSRTech_EmailCampaigns_Model_Campaign extends Mage_Core_Model_Abstract
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING   = 'sending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_CANCELLED = 'cancelled';

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/campaign');
    }

    /**
     * Populate the send queue from the target segment.
     */
    public function buildQueue(): int
    {
        if (!$this->getId() || $this->getStatus() !== self::STATUS_SCHEDULED) {
            Mage::throwException('Campaign must be scheduled before building its queue.');
        }

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');

        $queueTable      = $resource->getTableName('ysrtech_emailcampaigns/queue');
        $membershipTable = $resource->getTableName('ysrtech_emailcampaigns/segment_subscriber');
        $subscriberTable = $resource->getTableName('newsletter/subscriber');
        $prefTable       = $resource->getTableName('ysrtech_emailcampaigns/subscriber_pref');

        $select = $adapter->select()
            ->from(
                ['ns' => $subscriberTable],
                ['subscriber_id', 'customer_id', 'email' => 'subscriber_email']
            )
            ->join(
                ['m' => $membershipTable],
                'm.subscriber_id = ns.subscriber_id',
                []
            )
            ->where('m.segment_id = ?', (int) $this->getSegmentId())
            /*
             * Magento's own newsletter status is the authority on who may be
             * mailed. Reading membership without it would send to the people
             * who unsubscribed through the storefront - the module used to
             * check only its own table, and missed every one of them.
             */
            ->where('ns.subscriber_status = ?', Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
            ->where(
                "NOT EXISTS (
                    SELECT 1 FROM {$prefTable} p
                    WHERE p.email = ns.subscriber_email AND p.unsubscribed_at IS NOT NULL
                )"
            );

        $campaignId = (int) $this->getId();
        $inserted   = 0;
        $batch      = [];

        $statement = $adapter->query($select);

        while ($row = $statement->fetch()) {
            $batch[] = [
                'campaign_id'    => $campaignId,
                'subscriber_id'  => (int) $row['subscriber_id'],
                'customer_id'    => $row['customer_id'] ? (int) $row['customer_id'] : null,
                'email'          => $row['email'],
                'status'         => 'pending',
                'tracking_token' => Mage::helper('core')->getRandomString(32),
            ];

            // Written in blocks rather than a statement per recipient: at this
            // store's size that is 12,000 round trips reduced to a couple of
            // dozen.
            if (count($batch) >= 500) {
                $inserted += $this->_insertQueueBatch($adapter, $queueTable, $batch);
                $batch = [];
            }
        }

        if ($batch) {
            $inserted += $this->_insertQueueBatch($adapter, $queueTable, $batch);
        }

        return $inserted;
    }

    /**
     * @param  Varien_Db_Adapter_Interface $adapter
     * @param  string $table
     * @param  array  $rows
     * @return int
     */
    protected function _insertQueueBatch($adapter, $table, array $rows): int
    {
        /*
         * insertOnDuplicate against the unique key on (campaign_id, email):
         * queueing a campaign twice - which happens whenever the scheduler
         * sees one whose status has not moved on yet - must not put every
         * recipient in the queue a second time.
         */
        $adapter->insertOnDuplicate($table, $rows, ['status', 'subscriber_id']);

        return count($rows);
    }
}
