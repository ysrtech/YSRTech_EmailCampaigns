<?php
/**
 * Keeps the queue to the rows that still mean something.
 *
 * One row per recipient per campaign, and until now nothing removed them: a
 * send to this store's list leaves a few thousand behind, every send, for
 * ever. They are not free to keep - _claim() runs against that table every
 * five minutes - and they stop being useful the moment the campaign is done.
 *
 * What makes this safe to delete is the rollup. The analytics page counts
 * recipients, sent, failed and unsubscribed from the rows themselves, so
 * removing them without more would make a finished campaign read as having
 * gone to nobody. The totals are written onto the campaign first, and only
 * then do the rows go.
 */
class YSRTech_EmailCampaigns_Model_Retention
{
    /** Rows in these states will not change again. */
    private const TERMINAL = ['sent', 'failed', 'cancelled', 'skipped'];

    /** A campaign in these states has finished with its queue. */
    private const SETTLED = ['sent', 'failed', 'cancelled'];

    /** Rows per DELETE, so a long queue does not hold one lock for all of it. */
    private const CHUNK = 5000;

    /**
     * @return array{campaigns: int, campaign_rows: int, triggered_rows: int}
     */
    public function purge(): array
    {
        $result = ['campaigns' => 0, 'campaign_rows' => 0, 'triggered_rows' => 0];
        $helper = Mage::helper('ysrtech_emailcampaigns');

        if (!Mage::getStoreConfigFlag('ysrtech_emailcampaigns/cleanup/enabled')) {
            return $result;
        }

        $days = (int) $helper->getConfig('cleanup/keep_days');
        // A zero or negative retention would delete this morning's send, which
        // is never what an empty config field was meant to express.
        $days = $days > 0 ? $days : 90;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        foreach ($this->_settledCampaigns($cutoff) as $campaignId) {
            $rows = $this->_rollUpAndPurge((int) $campaignId);

            if ($rows > 0) {
                $result['campaigns']++;
                $result['campaign_rows'] += $rows;
            }
        }

        $result['triggered_rows'] = $this->_purgeTriggered($cutoff);

        if ($result['campaign_rows'] || $result['triggered_rows']) {
            Mage::log(sprintf(
                'EmailCampaigns: queue cleanup removed %d campaign row(s) across %d campaign(s) and %d triggered row(s), keeping %d days.',
                $result['campaign_rows'],
                $result['campaigns'],
                $result['triggered_rows'],
                $days
            ), Zend_Log::INFO);
        }

        return $result;
    }

    /**
     * Campaigns old enough to purge, that have not been purged already and
     * have nothing still in flight.
     *
     * @return int[]
     */
    private function _settledCampaigns(string $cutoff): array
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');
        $campaign = $resource->getTableName('ysrtech_emailcampaigns/campaign');
        $queue    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        $select = $adapter->select()
            ->from(['c' => $campaign], ['campaign_id'])
            ->where('c.purged_at IS NULL')
            ->where('c.status IN (?)', self::SETTLED)
            // created_at for a campaign that never sent, so a cancelled or
            // failed one is not kept for ever on a null sent_at.
            ->where('COALESCE(c.sent_at, c.created_at) < ?', $cutoff)
            /*
             * Nothing pending or sending, however old the campaign is. A row
             * waiting out its retry backoff is still work in hand, and the
             * sender would simply never see it again.
             */
            ->where(
                'NOT EXISTS (?)',
                new Zend_Db_Expr(
                    $adapter->select()
                        ->from(['q' => $queue], [new Zend_Db_Expr('1')])
                        ->where('q.campaign_id = c.campaign_id')
                        ->where('q.status NOT IN (?)', self::TERMINAL)
                        ->__toString()
                )
            );

        return array_map('intval', $adapter->fetchCol($select));
    }

    /**
     * Write one campaign's totals onto it, then drop its rows.
     *
     * @return int rows removed
     */
    private function _rollUpAndPurge(int $campaignId): int
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $campaign = $resource->getTableName('ysrtech_emailcampaigns/campaign');
        $queue    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        $counts = $adapter->fetchRow(
            $adapter->select()
                ->from($queue, [
                    'queued'    => new Zend_Db_Expr('COUNT(*)'),
                    'sent'      => new Zend_Db_Expr("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END)"),
                    'failed'    => new Zend_Db_Expr("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)"),
                    'cancelled' => new Zend_Db_Expr("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)"),
                ])
                ->where('campaign_id = ?', $campaignId)
        );

        if (!$counts || (int) $counts['queued'] === 0) {
            // Nothing to roll up. Stamped anyway so it is not reconsidered
            // every night for the rest of its life.
            $adapter->update($campaign, ['purged_at' => Varien_Date::now()], ['campaign_id = ?' => $campaignId]);

            return 0;
        }

        /*
         * Totals first and in their own statement: if the delete below fails
         * partway the campaign still reads correctly, where the reverse order
         * would leave a campaign that had lost its rows and never got its
         * numbers.
         */
        $adapter->update($campaign, [
            'stat_queued'    => (int) $counts['queued'],
            'stat_sent'      => (int) $counts['sent'],
            'stat_failed'    => (int) $counts['failed'],
            'stat_cancelled' => (int) $counts['cancelled'],
            'purged_at'      => Varien_Date::now(),
        ], ['campaign_id = ?' => $campaignId]);

        $removed = 0;

        do {
            $deleted = $adapter->query(
                sprintf('DELETE FROM %s WHERE campaign_id = ? LIMIT %d', $adapter->quoteIdentifier($queue), self::CHUNK),
                [$campaignId]
            )->rowCount();
            $removed += $deleted;
        } while ($deleted === self::CHUNK);

        return $removed;
    }

    /**
     * Triggered mail, which belongs to an automation rather than a campaign.
     *
     * Nothing aggregates these per automation, so there is nothing to roll up:
     * terminal rows past the cutoff simply go.
     */
    private function _purgeTriggered(string $cutoff): int
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $queue    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        $removed = 0;

        do {
            $deleted = $adapter->query(
                sprintf(
                    'DELETE FROM %s WHERE automation_id IS NOT NULL AND status IN (%s)'
                    . ' AND COALESCE(sent_at, created_at) < ? LIMIT %d',
                    $adapter->quoteIdentifier($queue),
                    implode(',', array_fill(0, count(self::TERMINAL), '?')),
                    self::CHUNK
                ),
                array_merge(self::TERMINAL, [$cutoff])
            )->rowCount();
            $removed += $deleted;
        } while ($deleted === self::CHUNK);

        return $removed;
    }
}
