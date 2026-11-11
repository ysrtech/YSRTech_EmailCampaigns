<?php
/**
 * Three things:
 *
 * A campaign can be a plain-text letter instead of a designed email: its
 * words, and whether opens and clicks are tracked for it.
 *
 * A message records when the provider said it was delivered, so "sent and
 * heard nothing since" can be told apart from "arrived".
 *
 * Webhooks are written down as they arrive and applied afterwards by cron,
 * so a busy moment costs a short delay rather than a lost notice.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// ---- letters
$campaign = $installer->getTable('ysrtech_emailcampaigns/campaign');

foreach ([
    'format' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 10, 'nullable' => false, 'default' => 'design',
        'comment' => 'design: a designed email. letter: plain text',
    ],
    'letter_text' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => '64k', 'nullable' => true,
        'comment' => 'The words of a plain-text letter',
    ],
    'letter_track' => [
        'type' => Varien_Db_Ddl_Table::TYPE_SMALLINT, 'unsigned' => true, 'nullable' => false, 'default' => 0,
        'comment' => 'Letter: track opens and clicks, which needs an HTML copy',
    ],
] as $column => $definition) {
    if (!$connection->tableColumnExists($campaign, $column)) {
        $connection->addColumn($campaign, $column, $definition);
    }
}

// ---- delivery reports
$queue = $installer->getTable('ysrtech_emailcampaigns/queue');

if (!$connection->tableColumnExists($queue, 'delivered_at')) {
    $connection->addColumn($queue, 'delivered_at', [
        'type' => Varien_Db_Ddl_Table::TYPE_DATETIME, 'nullable' => true,
        'comment' => 'When the provider reported it delivered',
    ]);
}

// ---- the webhook inbox
$name = $installer->getTable('ysrtech_emailcampaigns/webhook_event');

if (!$connection->isTableExists($name)) {
    $table = $connection->newTable($name)
        ->addColumn('event_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('provider', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['nullable' => false])
        // The provider's own id for the notice: a retry of one already
        // held is the same row, not a second one
        ->addColumn('event_key', Varien_Db_Ddl_Table::TYPE_TEXT, 120, ['nullable' => true])
        ->addColumn('payload', Varien_Db_Ddl_Table::TYPE_TEXT, '2M', ['nullable' => false])
        ->addColumn('received_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addColumn('attempts', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addIndex(
            $installer->getIdxName('ysrtech_emailcampaigns/webhook_event', ['provider', 'event_key'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['provider', 'event_key'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE]
        )
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/webhook_event', ['attempts', 'event_id']), ['attempts', 'event_id'])
        ->setComment('Email Campaigns: provider webhooks waiting to be applied');

    $connection->createTable($table);
}

$installer->endSetup();
