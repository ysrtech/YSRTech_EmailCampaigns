<?php
/**
 * Keep a campaign's totals after its queue rows are gone.
 *
 * The queue grows by one row per recipient per campaign and nothing ever
 * removed them: at this store's list size that is a few thousand rows every
 * send, kept for ever, and the sender scans that table every five minutes.
 *
 * Deleting them on its own would be destructive, because the analytics page
 * counts recipients, sent, failed and unsubscribed straight off those rows -
 * so a purged campaign would read as having gone to nobody. The totals are
 * rolled onto the campaign first and the page prefers them when they are set.
 *
 * Null means "not rolled up yet, count the rows", which is every campaign
 * until the first sweep runs.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection    = $installer->getConnection();
$campaignTable = $installer->getTable('ysrtech_emailcampaigns/campaign');

$columns = [
    'stat_queued'    => 'Recipients queued, kept after the rows are purged',
    'stat_sent'      => 'Sent count, kept after the rows are purged',
    'stat_failed'    => 'Failed count, kept after the rows are purged',
    'stat_cancelled' => 'Cancelled count, kept after the rows are purged',
];

foreach ($columns as $column => $comment) {
    if (!$connection->tableColumnExists($campaignTable, $column)) {
        $connection->addColumn($campaignTable, $column, [
            'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
            'unsigned' => true,
            'nullable' => true,
            'comment'  => $comment,
        ]);
    }
}

if (!$connection->tableColumnExists($campaignTable, 'purged_at')) {
    $connection->addColumn($campaignTable, 'purged_at', [
        'type'     => Varien_Db_Ddl_Table::TYPE_DATETIME,
        'nullable' => true,
        'comment'  => 'When this campaign\'s queue rows were rolled up and removed',
    ]);
}

/*
 * No new index. The campaign sweep narrows on campaign_id + status, which
 * idx_campaign_status already covers, and the triggered sweep filters on
 * COALESCE(sent_at, created_at), which no index on either column can serve.
 * An index nothing can use would still be written on every insert, and a
 * send inserts in blocks of five hundred.
 */

$installer->endSetup();
