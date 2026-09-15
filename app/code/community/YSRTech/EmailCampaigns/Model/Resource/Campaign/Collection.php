<?php
class YSRTech_EmailCampaigns_Model_Resource_Campaign_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/campaign');
    }

    /**
     * Aggregates each campaign's queue into per-campaign counters for the
     * Analytics grid: how many recipients, how many actually sent/failed, and
     * (from Mailgun's opened/clicked webhooks — see WebhookController) how many
     * opened or clicked at least once.
     */
    public function addQueueStats(): self
    {
        $queueTable = $this->getTable('ysrtech_emailcampaigns/queue');
        $this->getSelect()->joinLeft(
            ['q' => $queueTable],
            'q.campaign_id = main_table.campaign_id',
            [
                'recipients_count' => new Zend_Db_Expr('COUNT(q.queue_id)'),
                'sent_count'       => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END)"),
                'failed_count'     => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END)"),
                'opened_count'     => new Zend_Db_Expr('SUM(CASE WHEN q.open_count > 0 THEN 1 ELSE 0 END)'),
                'clicked_count'    => new Zend_Db_Expr('SUM(CASE WHEN q.click_count > 0 THEN 1 ELSE 0 END)'),
                'open_rate'        => new Zend_Db_Expr(
                    "ROUND(SUM(CASE WHEN q.open_count > 0 THEN 1 ELSE 0 END) * 100 "
                    . "/ NULLIF(SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END), 0), 1)"
                ),
                'click_rate'       => new Zend_Db_Expr(
                    "ROUND(SUM(CASE WHEN q.click_count > 0 THEN 1 ELSE 0 END) * 100 "
                    . "/ NULLIF(SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END), 0), 1)"
                ),
            ]
        )->group('main_table.campaign_id');

        return $this;
    }
}
