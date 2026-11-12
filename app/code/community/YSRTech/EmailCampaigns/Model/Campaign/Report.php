<?php
/**
 * What happened to one campaign, for the top of its own page once it has
 * started: how many went out, what the provider has said about them since,
 * and which have had nothing said about them yet.
 */
class YSRTech_EmailCampaigns_Model_Campaign_Report
{
    /** How many of the addresses still waiting on a report are listed */
    public const WAITING_SHOWN = 50;

    /**
     * @param  YSRTech_EmailCampaigns_Model_Campaign $campaign
     * @return array{
     *     queued:int, sent:int, pending:int, failed:int, cancelled:int,
     *     delivered:int, opened:int, clicked:int, bounced:int, complained:int,
     *     waiting:int, waiting_emails:string[], purged:bool, tracks_opens:bool, last_sent_at:?string
     * }
     */
    public function compute($campaign): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');
        $queue    = $resource->getTableName('ysrtech_emailcampaigns/queue');
        $id       = (int) $campaign->getId();

        /*
         * Nothing said yet: accepted by the provider, and since then no
         * delivery, no bounce, no complaint, no open and no click. Within a
         * few minutes of a send that is most of them; a day later it is the
         * ones worth a look - or it means the provider's "delivered" webhook
         * is not pointed here.
         */
        $silent = "q.status = 'sent' AND q.delivered_at IS NULL AND q.bounced_at IS NULL AND q.complained_at IS NULL"
            . ' AND q.open_count = 0 AND q.click_count = 0';

        $row = $adapter->fetchRow(
            $adapter->select()
                ->from(['q' => $queue], [
                    'queued'     => new Zend_Db_Expr('COUNT(*)'),
                    'sent'       => new Zend_Db_Expr("COALESCE(SUM(q.status = 'sent'), 0)"),
                    'pending'    => new Zend_Db_Expr("COALESCE(SUM(q.status = 'pending'), 0)"),
                    'failed'     => new Zend_Db_Expr("COALESCE(SUM(q.status = 'failed'), 0)"),
                    'cancelled'  => new Zend_Db_Expr("COALESCE(SUM(q.status = 'cancelled'), 0)"),
                    // An open or a click is also proof of arrival, for a
                    // provider that reported the one and not the other
                    'delivered'  => new Zend_Db_Expr("COALESCE(SUM(q.status = 'sent' AND q.bounced_at IS NULL AND (q.delivered_at IS NOT NULL OR q.open_count > 0 OR q.click_count > 0)), 0)"),
                    'opened'     => new Zend_Db_Expr('COALESCE(SUM(q.open_count > q.machine_open_count), 0)'),
                    'clicked'    => new Zend_Db_Expr('COALESCE(SUM(q.click_count > 0), 0)'),
                    'bounced'    => new Zend_Db_Expr('COALESCE(SUM(q.bounced_at IS NOT NULL), 0)'),
                    'complained' => new Zend_Db_Expr('COALESCE(SUM(q.complained_at IS NOT NULL), 0)'),
                    'waiting'    => new Zend_Db_Expr("COALESCE(SUM({$silent}), 0)"),
                    'last_sent'  => new Zend_Db_Expr('MAX(q.sent_at)'),
                ])
                ->where('q.campaign_id = ?', $id)
        );

        $report = array_map('intval', array_diff_key($row, ['last_sent' => 1]));

        // The nightly sweep rolls an old campaign's totals onto it and
        // removes the rows; what is left is the totals
        $report['purged'] = $report['queued'] === 0 && $campaign->getData('stat_sent') !== null;

        if ($report['purged']) {
            $report['queued']    = (int) $campaign->getData('stat_queued');
            $report['sent']      = (int) $campaign->getData('stat_sent');
            $report['failed']    = (int) $campaign->getData('stat_failed');
            $report['cancelled'] = (int) $campaign->getData('stat_cancelled');
        }

        $report['waiting_emails'] = $report['waiting'] > 0
            ? $adapter->fetchCol(
                $adapter->select()
                    ->from(['q' => $queue], ['email'])
                    ->where('q.campaign_id = ?', $id)
                    ->where($silent)
                    ->order('q.sent_at ASC')
                    ->limit(self::WAITING_SHOWN)
            )
            : [];

        // A text-only letter has no pixel and no rewritten links: its opens
        // and clicks are not zero, they are unknown
        $report['tracks_opens'] = (bool) Mage::helper('ysrtech_emailcampaigns')->getConfig('tracking/enabled')
            && (!$campaign->isLetter() || (bool) $campaign->getLetterTrack());

        /** @var YSRTech_EmailCampaigns_Model_Conversions $conversions */
        $conversions = Mage::getSingleton('ysrtech_emailcampaigns/conversions');
        $earned      = $conversions->forCampaign($id);

        // What it earned: shown where order tracking is on, or was when it went
        $report['tracks_orders']     = $conversions->isEnabled($campaign->getStoreId()) || $earned['orders'] > 0;
        $report['orders']            = $earned['orders'];
        $report['orders_by_address'] = $earned['by_address'];
        $report['revenue']           = $conversions->money($earned['revenue'], $earned['currency']);
        $report['revenue_each']      = $report['sent'] > 0 ? $conversions->money($earned['revenue'] / $report['sent'], $earned['currency']) : null;
        $report['conversion_days']   = $conversions->windowDays($campaign->getStoreId());

        $report['links'] = $conversions->linksForCampaign($id);

        $report['last_sent_at'] = $row['last_sent']
            ? Mage::helper('core')->formatDate($row['last_sent'], 'medium', true)
            : null;

        return $report;
    }
}
