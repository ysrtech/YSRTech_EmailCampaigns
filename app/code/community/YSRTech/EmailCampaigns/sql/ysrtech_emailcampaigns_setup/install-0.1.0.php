<?php
/**
 * YSRTech_EmailCampaigns install schema 0.1.0
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

/* ---------- Templates ---------- */
$templateTable = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_emailcampaigns/template'))
    ->addColumn('template_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Template ID')
    ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Name')
    ->addColumn('subject', Varien_Db_Ddl_Table::TYPE_TEXT, 500, [], 'Default Subject')
    ->addColumn('design_json', Varien_Db_Ddl_Table::TYPE_TEXT, '2M', [], 'Drag & drop editor design (JSON)')
    ->addColumn('html', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', [], 'Rendered HTML')
    ->addColumn('is_active', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['default' => 1], 'Is Active')
    ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT], 'Created At')
    ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE], 'Updated At')
    ->setComment('Email templates');

/* ---------- Segments ---------- */
$segmentTable = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_emailcampaigns/segment'))
    ->addColumn('segment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Segment ID')
    ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Name')
    ->addColumn('conditions_serialized', Varien_Db_Ddl_Table::TYPE_TEXT, '2M', [], 'Rule conditions (serialized, Mage_Rule format)')
    ->addColumn('is_active', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['default' => 1], 'Is Active')
    ->addColumn('customer_count', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'default' => 0], 'Cached member count')
    ->addColumn('last_reindexed_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Last Reindexed At')
    ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT], 'Created At')
    ->setComment('Customer segments');

$segmentCustomerTable = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_emailcampaigns/segment_customer'))
    ->addColumn('segment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false], 'Segment ID')
    ->addColumn('customer_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false], 'Customer ID')
    ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/segment_customer', ['segment_id', 'customer_id']), ['segment_id', 'customer_id'], ['type' => 'primary'])
    ->addForeignKey(
        $installer->getFkName('ysrtech_emailcampaigns/segment_customer', 'segment_id', 'ysrtech_emailcampaigns/segment', 'segment_id'),
        'segment_id', $installer->getTable('ysrtech_emailcampaigns/segment'), 'segment_id',
        Varien_Db_Adapter_Interface::FK_ACTION_CASCADE, Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
    )
    ->setComment('Segment membership');

/* ---------- Campaigns ---------- */
$campaignTable = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_emailcampaigns/campaign'))
    ->addColumn('campaign_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Campaign ID')
    ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Campaign Name')
    ->addColumn('subject', Varien_Db_Ddl_Table::TYPE_TEXT, 500, [], 'Subject')
    ->addColumn('template_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true], 'Template ID')
    ->addColumn('segment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true], 'Target Segment ID')
    ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['default' => 'draft'], 'Status: draft/scheduled/sending/sent/paused/cancelled')
    ->addColumn('scheduled_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Scheduled At')
    ->addColumn('sent_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Sent At')
    ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'default' => 0], 'Store ID')
    ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT], 'Created At')
    ->addIndex('idx_status_scheduled', ['status', 'scheduled_at'])
    ->addForeignKey(
        $installer->getFkName('ysrtech_emailcampaigns/campaign', 'template_id', 'ysrtech_emailcampaigns/template', 'template_id'),
        'template_id', $installer->getTable('ysrtech_emailcampaigns/template'), 'template_id',
        Varien_Db_Adapter_Interface::FK_ACTION_SET_NULL, Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
    )
    ->setComment('Campaigns');

/* ---------- Send Queue ---------- */
$queueTable = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_emailcampaigns/queue'))
    ->addColumn('queue_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Queue ID')
    ->addColumn('campaign_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false], 'Campaign ID')
    ->addColumn('email', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Recipient Email')
    ->addColumn('customer_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true], 'Customer ID')
    ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['default' => 'pending'], 'pending/sending/sent/failed/skipped')
    ->addColumn('attempts', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'default' => 0], 'Attempts')
    ->addColumn('error_message', Varien_Db_Ddl_Table::TYPE_TEXT, '64K', [], 'Last Error')
    ->addColumn('sent_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Sent At')
    ->addColumn('next_attempt_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Next Attempt At')
    ->addColumn('tracking_token', Varien_Db_Ddl_Table::TYPE_TEXT, 64, [], 'Unique tracking token')
    ->addIndex('idx_campaign_status', ['campaign_id', 'status'])
    ->addIndex('idx_next_attempt', ['status', 'next_attempt_at'])
    // buildQueue() writes with insertOnDuplicate, which needs a unique key to
    // detect a duplicate against. Without one a campaign queued twice - which
    // is what happens whenever the scheduler picks up a campaign whose status
    // has not moved on yet - puts every recipient in the queue a second time
    // and mails them all twice.
    ->addIndex(
        'unq_campaign_email',
        ['campaign_id', 'email'],
        ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE]
    )
    ->setComment('Send queue');

/* ---------- Subscriber preferences / unsubscribe ---------- */
$prefTable = $installer->getConnection()
    ->newTable($installer->getTable('ysrtech_emailcampaigns/subscriber_pref'))
    ->addColumn('pref_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Preference ID')
    ->addColumn('email', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Email')
    ->addColumn('unsubscribed_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Unsubscribed At')
    ->addColumn('preferences_json', Varien_Db_Ddl_Table::TYPE_TEXT, '16K', [], 'Category preferences (JSON)')
    ->addColumn('token', Varien_Db_Ddl_Table::TYPE_TEXT, 64, [], 'Secure preference-center token')
    ->addIndex('uniq_email', ['email'], ['type' => 'unique'])
    ->setComment('Subscriber preferences');

foreach ([$templateTable, $segmentTable, $segmentCustomerTable, $campaignTable, $queueTable, $prefTable] as $table) {
    $installer->getConnection()->createTable($table);
}

$installer->endSetup();
