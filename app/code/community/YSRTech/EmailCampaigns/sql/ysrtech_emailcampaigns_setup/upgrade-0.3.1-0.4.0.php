<?php
/**
 * Triggered email: automations alongside campaigns.
 *
 * A campaign is one send to many people, decided by a person. An automation
 * is many sends of one message, each decided by something a customer did -
 * placing an order, leaving a cart, receiving a shipment.
 *
 * They share the queue rather than each having one. That is the whole point
 * of putting them in the same module: one claim-before-send, one sender loop,
 * one suppression check, one set of transports. Two queues would mean two of
 * everything, and two chances to send the same person the same mail twice.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection     = $installer->getConnection();
$automationTable = $installer->getTable('ysrtech_emailcampaigns/automation');
$queueTable      = $installer->getTable('ysrtech_emailcampaigns/queue');

if (!$connection->isTableExists($automationTable)) {
    $table = $connection->newTable($automationTable)
        ->addColumn('automation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Automation ID')
        ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Name')
        ->addColumn('event', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false], 'Trigger')
        ->addColumn('order_status', Varien_Db_Ddl_Table::TYPE_TEXT, 32, [], 'Order status, for the status trigger')
        ->addColumn('product_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
        ], 'Product, for the bought-a-product trigger')
        ->addColumn('template_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false,
        ], 'Template ID')
        ->addColumn('send_moment', Varien_Db_Ddl_Table::TYPE_TEXT, 16, [
            'nullable' => false, 'default' => 'immediate',
        ], 'immediate or after')
        ->addColumn('after_days', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Delay in days')
        ->addColumn('after_hours', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Delay in hours')
        /*
         * The distinction the old follow-up module never made. An order
         * confirmation is transactional and goes to somebody who unsubscribed
         * from the newsletter; an abandoned cart nudge is marketing and must
         * not.
         */
        ->addColumn('respect_subscription', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 1,
        ], 'Hold back if they are not subscribed')
        ->addColumn('is_active', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 1,
        ], 'Active')
        ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Store, 0 for all')
        ->addColumn('active_from', Varien_Db_Ddl_Table::TYPE_DATE, null, [], 'Runs from')
        ->addColumn('active_to', Varien_Db_Ddl_Table::TYPE_DATE, null, [], 'Runs until')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, [
            'nullable' => false, 'default' => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Created At')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, [], 'Updated At')
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/automation', ['event', 'is_active']), ['event', 'is_active'])
        ->addForeignKey(
            $installer->getFkName('ysrtech_emailcampaigns/automation', 'template_id', 'ysrtech_emailcampaigns/template', 'template_id'),
            'template_id',
            $installer->getTable('ysrtech_emailcampaigns/template'),
            'template_id',
            // Restrict, like segments: deleting a template out from under a
            // live automation would silently stop it sending
            Varien_Db_Adapter_Interface::FK_ACTION_RESTRICT,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->setComment('Triggered email rules');

    $connection->createTable($table);
}

// --- the queue learns to carry triggered mail too ---

if (!$connection->tableColumnExists($queueTable, 'automation_id')) {
    /*
     * Nullable both ways: a row belongs to a campaign or to an automation,
     * never to both and never to neither.
     */
    $connection->modifyColumn($queueTable, 'campaign_id', [
        'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => true,
        'comment' => 'Campaign ID, null for triggered mail',
    ]);

    $connection->addColumn($queueTable, 'automation_id', [
        'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => true,
        'comment' => 'Automation ID, null for campaign mail',
    ]);
    $connection->addColumn($queueTable, 'object_type', [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 32, 'nullable' => true,
        'comment' => 'What triggered it: order, quote, shipment, invoice, creditmemo',
    ]);
    $connection->addColumn($queueTable, 'object_id', [
        'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => true,
        'comment' => 'Id of that object',
    ]);
    $connection->addColumn($queueTable, 'send_at', [
        'type' => Varien_Db_Ddl_Table::TYPE_DATETIME, 'nullable' => true,
        'comment' => 'Not before this moment',
    ]);

    /*
     * A campaign dedupes on (campaign_id, email) - one person, one copy. An
     * automation cannot: the same address should get an email for every order
     * it places. What must not repeat is one automation firing twice for the
     * same order, which is what this key prevents.
     */
    $connection->addIndex(
        $queueTable,
        $installer->getIdxName(
            'ysrtech_emailcampaigns/queue',
            ['automation_id', 'object_type', 'object_id'],
            Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
        ),
        ['automation_id', 'object_type', 'object_id'],
        Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
    );

    $connection->addIndex(
        $queueTable,
        $installer->getIdxName('ysrtech_emailcampaigns/queue', ['status', 'send_at']),
        ['status', 'send_at']
    );

    $connection->addForeignKey(
        $installer->getFkName('ysrtech_emailcampaigns/queue', 'automation_id', 'ysrtech_emailcampaigns/automation', 'automation_id'),
        $queueTable,
        'automation_id',
        $automationTable,
        'automation_id',
        Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
        Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
    );
}

/*
 * The install left two identical unique keys on (campaign_id, email). Every
 * insert maintained both; one is enough.
 */
foreach ($connection->getIndexList($queueTable) as $name => $index) {
    if ($index['COLUMNS_LIST'] === ['campaign_id', 'email'] && $name === 'UNQ_CAMPAIGN_EMAIL') {
        $connection->dropIndex($queueTable, $name);
    }
}

$installer->endSetup();
