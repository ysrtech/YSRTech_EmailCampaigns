<?php
/**
 * Provider webhooks, kept first and applied afterwards.
 *
 * A webhook used to be applied in the request that delivered it: find the
 * message, update it, perhaps suppress the address. On a busy server, or
 * while a campaign's worth of opens arrives at once, some of those requests
 * time out, and a provider only retries so many times. Now the request does
 * one insert and answers; cron applies what has been kept, a minute or so
 * later, and anything that fails to apply stays here to be tried again.
 *
 * The signature is still checked in the request, before anything is kept:
 * this table only ever holds notices the provider really sent.
 */
class YSRTech_EmailCampaigns_Model_Webhook_Inbox
{
    public const LOCK        = 'ysrtech_emailcampaigns_webhooks';
    public const BATCH       = 500;
    public const BUDGET      = 40;
    public const MAX_TRIES   = 5;
    /** Notices that could never be applied are kept this long to be looked at */
    public const KEEP_FAILED = 14;

    /**
     * Keep one notice. A provider's retry of one already held is dropped.
     *
     * @param string|null $key The provider's own id for the notice, when it gives one
     */
    public function store(string $provider, ?string $key, string $payload): void
    {
        $resource = Mage::getSingleton('core/resource');

        $resource->getConnection('core_write')->insertIgnore($resource->getTableName('ysrtech_emailcampaigns/webhook_event'), [
            'provider'    => $provider,
            'event_key'   => $key !== null && $key !== '' ? mb_substr($key, 0, 120) : null,
            'payload'     => $payload,
            'received_at' => Varien_Date::now(),
        ]);
    }

    /**
     * How many notices are waiting, and how many have been given up on.
     *
     * @return array{waiting:int, failed:int, oldest:?string}
     */
    public function counts(): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/webhook_event');

        $row = $adapter->fetchRow($adapter->select()->from($table, [
            'waiting' => new Zend_Db_Expr('COALESCE(SUM(attempts < ' . self::MAX_TRIES . '), 0)'),
            'failed'  => new Zend_Db_Expr('COALESCE(SUM(attempts >= ' . self::MAX_TRIES . '), 0)'),
            'oldest'  => new Zend_Db_Expr('MIN(CASE WHEN attempts < ' . self::MAX_TRIES . ' THEN received_at END)'),
        ]));

        return ['waiting' => (int) $row['waiting'], 'failed' => (int) $row['failed'], 'oldest' => $row['oldest'] ?: null];
    }

    /**
     * Apply what is waiting, oldest first, for as long as the budget allows.
     * Cron calls this every minute.
     *
     * @return int Notices applied
     */
    public function process(?int $budgetSeconds = null): int
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/webhook_event');

        // One run at a time: two applying the same open would count it twice
        if (!(int) $adapter->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK])) {
            return 0;
        }

        $deadline = microtime(true) + ($budgetSeconds ?? self::BUDGET);
        $applied  = 0;
        $after    = 0;

        /** @var YSRTech_EmailCampaigns_Model_Webhook_Processor $processor */
        $processor = Mage::getModel('ysrtech_emailcampaigns/webhook_processor');

        try {
            do {
                $rows = $adapter->fetchAll(
                    $adapter->select()
                        ->from($table, ['event_id', 'provider', 'payload', 'received_at', 'attempts'])
                        ->where('attempts < ?', self::MAX_TRIES)
                        ->where('event_id > ?', $after)
                        ->order('event_id ASC')
                        ->limit(self::BATCH)
                );

                foreach ($rows as $row) {
                    $after = (int) $row['event_id'];

                    try {
                        $processor->apply((string) $row['provider'], (string) $row['payload'], (string) $row['received_at']);
                        $adapter->delete($table, ['event_id = ?' => $after]);
                        $applied++;
                    } catch (Throwable $e) {
                        // Left in place for the next run; after a few it is
                        // set aside rather than tried for ever
                        Mage::logException($e);
                        $adapter->update($table, [
                            'attempts' => (int) $row['attempts'] + 1,
                            'error'    => mb_substr($e->getMessage(), 0, 255),
                        ], ['event_id = ?' => $after]);
                    }

                    if (microtime(true) >= $deadline) {
                        break 2;
                    }
                }
            } while (count($rows) === self::BATCH);

            $adapter->delete($table, [
                'attempts >= ?'   => self::MAX_TRIES,
                'received_at < ?' => gmdate('Y-m-d H:i:s', time() - self::KEEP_FAILED * 86400),
            ]);
        } finally {
            $adapter->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }

        return $applied;
    }
}
