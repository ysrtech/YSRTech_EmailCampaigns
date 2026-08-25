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

        /** @var Mage_Core_Model_Resource $res */
        $res = Mage::getSingleton('core/resource');
        $conn = $res->getConnection('core_write');
        $queueTable = $res->getTableName('ysrtech_emailcampaigns/queue');
        $linkTable  = $res->getTableName('ysrtech_emailcampaigns/segment_customer');
        $customerTable = $res->getTableName('customer/entity');
        $prefTable  = $res->getTableName('ysrtech_emailcampaigns/subscriber_pref');

        $segmentId = (int) $this->getSegmentId();

        $select = $conn->select()
            ->from(['c' => $customerTable], ['customer_id' => 'entity_id', 'email'])
            ->join(['l' => $linkTable], 'l.customer_id = c.entity_id', [])
            ->where('l.segment_id = ?', $segmentId)
            ->where('c.is_active = ?', 1)
            ->where("NOT EXISTS (
                SELECT 1 FROM {$prefTable} p
                WHERE p.email = c.email AND p.unsubscribed_at IS NOT NULL
            )");

        $rows = $conn->fetchAll($select);
        $now = Varien_Date::now();

        $inserted = 0;
        foreach ($rows as $row) {
            $conn->insertOnDuplicate($queueTable, [
                'campaign_id'    => (int) $this->getId(),
                'email'          => $row['email'],
                'customer_id'    => $row['customer_id'],
                'status'         => 'pending',
                'tracking_token' => Mage::helper('core')->getRandomString(32),
            ], ['status', 'tracking_token']);
            $inserted++;
        }
        unset($now);

        return $inserted;
    }
}
