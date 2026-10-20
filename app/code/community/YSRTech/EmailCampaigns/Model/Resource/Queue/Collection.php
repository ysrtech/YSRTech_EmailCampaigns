<?php
class YSRTech_EmailCampaigns_Model_Resource_Queue_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/queue');
    }

    /**
     * How many of a campaign's queue rows sit in each status.
     *
     * One grouped query rather than a getSize() per status: this runs after
     * every batch, and a campaign's queue is as long as its audience.
     *
     * @param  int $campaignId
     * @return array<string, int> status => count, statuses with no rows absent
     */
    public function countByStatusForCampaign(int $campaignId): array
    {
        $select = $this->getConnection()->select()
            ->from($this->getMainTable(), ['status', 'total' => new Zend_Db_Expr('COUNT(*)')])
            ->where('campaign_id = ?', $campaignId)
            ->group('status');

        $counts = [];

        foreach ($this->getConnection()->fetchAll($select) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
