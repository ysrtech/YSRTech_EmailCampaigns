<?php
/**
 * Adds the trigger-based automation ("drip flow") engine: flow definitions,
 * per-customer enrollment state, and a node-traversal log for debugging why
 * a given customer did or didn't get a step's email.
 *
 * Also loosens the send queue so flow-triggered sends can record themselves
 * there too (campaign_id becomes optional, flow_id/flow_enrollment_id added)
 * — reusing the queue as the one place unsubscribe links and open/click
 * tracking resolve a tracking_token back to a recipient, rather than
 * inventing a second tracking mechanism just for flows.
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$connection = $installer->getConnection();

$flowTable = $connection->newTable($installer->getTable('ysrtech_emailcampaigns/flow'))
    ->addColumn('flow_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Flow ID')
    ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Name')
    ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['default' => 'draft'], 'draft/active/paused')
    ->addColumn('trigger_type', Varien_Db_Ddl_Table::TYPE_TEXT, 40, [], 'order_placed/customer_registered/cart_abandoned/...')
    ->addColumn('graph_json', Varien_Db_Ddl_Table::TYPE_TEXT, '2M', [], 'React Flow nodes+edges')
    ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT], 'Created At')
    ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE], 'Updated At')
    ->addIndex('idx_status_trigger', ['status', 'trigger_type'])
    ->setComment('Automation flows');

$enrollmentTable = $connection->newTable($installer->getTable('ysrtech_emailcampaigns/flow_enrollment'))
    ->addColumn('enrollment_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Enrollment ID')
    ->addColumn('flow_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false], 'Flow ID')
    ->addColumn('customer_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true], 'Customer ID')
    ->addColumn('email', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Recipient Email')
    ->addColumn('current_node_id', Varien_Db_Ddl_Table::TYPE_TEXT, 64, [], 'Current graph node id')
    ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['default' => 'active'], 'active/waiting/completed/exited')
    ->addColumn('wait_until', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Due At (for waiting enrollments)')
    ->addColumn('enrolled_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Enrolled At')
    ->addColumn('context_json', Varien_Db_Ddl_Table::TYPE_TEXT, '16K', [], 'Trigger-time context (order id, etc.)')
    ->addIndex('idx_status_wait', ['status', 'wait_until'])
    ->addIndex('idx_flow', ['flow_id'])
    ->addForeignKey(
        $installer->getFkName('ysrtech_emailcampaigns/flow_enrollment', 'flow_id', 'ysrtech_emailcampaigns/flow', 'flow_id'),
        'flow_id', $installer->getTable('ysrtech_emailcampaigns/flow'), 'flow_id',
        Varien_Db_Ddl_Table::ACTION_CASCADE, Varien_Db_Ddl_Table::ACTION_CASCADE
    )
    ->setComment('Per-customer flow enrollment state');

$logTable = $connection->newTable($installer->getTable('ysrtech_emailcampaigns/flow_log'))
    ->addColumn('log_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, [
        'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
    ], 'Log ID')
    ->addColumn('enrollment_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['unsigned' => true, 'nullable' => false], 'Enrollment ID')
    ->addColumn('node_id', Varien_Db_Ddl_Table::TYPE_TEXT, 64, [], 'Graph node id')
    ->addColumn('action', Varien_Db_Ddl_Table::TYPE_TEXT, 20, [], 'entered/sent/skipped/exited')
    ->addColumn('message', Varien_Db_Ddl_Table::TYPE_TEXT, '64K', [], 'Detail (e.g. skip reason)')
    ->addColumn('entered_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT], 'Entered At')
    ->addIndex('idx_enrollment', ['enrollment_id'])
    ->addForeignKey(
        $installer->getFkName('ysrtech_emailcampaigns/flow_log', 'enrollment_id', 'ysrtech_emailcampaigns/flow_enrollment', 'enrollment_id'),
        'enrollment_id', $installer->getTable('ysrtech_emailcampaigns/flow_enrollment'), 'enrollment_id',
        Varien_Db_Ddl_Table::ACTION_CASCADE, Varien_Db_Ddl_Table::ACTION_CASCADE
    )
    ->setComment('Flow node traversal log');

foreach ([$flowTable, $enrollmentTable, $logTable] as $table) {
    $connection->createTable($table);
}

$queueTable = $installer->getTable('ysrtech_emailcampaigns/queue');
$connection->changeColumn($queueTable, 'campaign_id', 'campaign_id', [
    'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => true,
    'comment' => 'Campaign ID (null for flow-triggered sends)',
]);
$connection->addColumn($queueTable, 'flow_id', [
    'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => true,
    'comment' => 'Flow ID (for flow-triggered sends)',
]);
$connection->addColumn($queueTable, 'flow_enrollment_id', [
    'type' => Varien_Db_Ddl_Table::TYPE_BIGINT, 'unsigned' => true, 'nullable' => true,
    'comment' => 'Flow Enrollment ID (for flow-triggered sends)',
]);

$installer->endSetup();
