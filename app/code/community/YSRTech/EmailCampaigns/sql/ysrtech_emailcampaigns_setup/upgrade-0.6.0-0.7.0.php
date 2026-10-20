<?php
/**
 * Record what recipients did with a campaign, not just that it was sent.
 *
 * The provider already tracks opens and clicks - Transport_Mailgun has asked
 * it to since the beginning, with o:tracking-opens and o:tracking-clicks -
 * but the results stayed in Mailgun's dashboard because nothing here received
 * the events. WebhookController does now, and these are where it puts them.
 *
 * A first timestamp and a count rather than a row per event: "did they open
 * it, and when did they first" is the question the analytics page asks, and a
 * log of every open of every message to every recipient is a table nobody
 * would thank us for.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$queueTable = $installer->getTable('ysrtech_emailcampaigns/queue');

$columns = [
    'opened_at' => [
        'type'     => Varien_Db_Ddl_Table::TYPE_DATETIME,
        'nullable' => true,
        'comment'  => 'First Opened At',
    ],
    'open_count' => [
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => false,
        'default'  => 0,
        'comment'  => 'Open Count',
    ],
    'clicked_at' => [
        'type'     => Varien_Db_Ddl_Table::TYPE_DATETIME,
        'nullable' => true,
        'comment'  => 'First Clicked At',
    ],
    'click_count' => [
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => false,
        'default'  => 0,
        'comment'  => 'Click Count',
    ],
];

foreach ($columns as $column => $definition) {
    if (!$connection->tableColumnExists($queueTable, $column)) {
        $connection->addColumn($queueTable, $column, $definition);
    }
}

/*
 * Both ways an engagement event is matched need an index, and neither had one.
 *
 * tracking_token was described as unique in its column comment but was never
 * indexed, so looking a token up read the whole queue - a table with a row per
 * recipient per campaign, against an event per open and per click. The index is
 * not declared unique: the token is 32 random characters so uniqueness is not
 * in question, and a UNIQUE on existing production data is a migration that can
 * fail on a duplicate for no benefit the lookup needs.
 *
 * The second covers the fallback, which finds the latest sent row for an
 * address.
 */
foreach ([
    ['tracking_token'],
    ['email', 'status', 'sent_at'],
] as $fields) {
    $indexName = $installer->getIdxName('ysrtech_emailcampaigns/queue', $fields);
    $existing  = array_change_key_case($connection->getIndexList($queueTable), CASE_UPPER);

    if (!array_key_exists(strtoupper($indexName), $existing)) {
        $connection->addIndex($queueTable, $indexName, $fields);
    }
}

$installer->endSetup();
