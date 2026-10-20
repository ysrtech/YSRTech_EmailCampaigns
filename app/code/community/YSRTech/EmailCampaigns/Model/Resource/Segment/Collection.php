<?php
class YSRTech_EmailCampaigns_Model_Resource_Segment_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/segment');
    }

    /**
     * Bring each segment's campaign usage along with it.
     *
     * One grouped join rather than a lookup per row: the grid renders every
     * segment, and a query each would be a query per row for a column nobody
     * can sort on either.
     *
     * @return $this
     */
    public function addCampaignUsage()
    {
        $link     = $this->getTable('ysrtech_emailcampaigns/campaign_segment');
        $campaign = $this->getTable('ysrtech_emailcampaigns/campaign');

        $usage = $this->getConnection()->select()
            ->from(['cs' => $link], [
                'segment_id',
                'campaign_count' => new Zend_Db_Expr('COUNT(DISTINCT cs.campaign_id)'),
                /*
                 * Rendered as "Name (included)" so the column says how the
                 * segment is used, not merely that it is - removing an
                 * exclusion and removing a target are different edits.
                 */
                'campaign_names' => new Zend_Db_Expr(
                    "GROUP_CONCAT(CONCAT(c.name, IF(cs.is_excluded, ' (excluded)', ' (included)'))"
                    . " ORDER BY c.name SEPARATOR '\\n')"
                ),
            ])
            ->join(['c' => $campaign], 'c.campaign_id = cs.campaign_id', [])
            ->group('cs.segment_id');

        $this->getSelect()->joinLeft(
            ['usage' => new Zend_Db_Expr('(' . $usage . ')')],
            'usage.segment_id = main_table.segment_id',
            ['campaign_count' => new Zend_Db_Expr('COALESCE(usage.campaign_count, 0)'), 'campaign_names']
        );

        return $this;
    }
}
