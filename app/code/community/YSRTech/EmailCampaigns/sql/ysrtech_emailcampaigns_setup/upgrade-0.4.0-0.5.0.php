<?php
/**
 * Chains: an automation becomes a sequence rather than a single message.
 *
 * "Thank you on day two, ask for a review on day seven, tempt them back on
 * day thirty" is one intent, and keeping it as three separate rules means
 * three things to switch off when somebody buys again. A chain is one rule
 * with several steps, and it can stop itself partway.
 *
 * The steps move out of the automation row rather than sitting beside it: a
 * rule whose first message lives in one shape and whose later ones live in
 * another is a rule nobody can read.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection      = $installer->getConnection();
$automationTable = $installer->getTable('ysrtech_emailcampaigns/automation');
$stepTable       = $installer->getTable('ysrtech_emailcampaigns/automation_step');
$queueTable      = $installer->getTable('ysrtech_emailcampaigns/queue');

if (!$connection->isTableExists($stepTable)) {
    $table = $connection->newTable($stepTable)
        ->addColumn('step_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Step ID')
        ->addColumn('automation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false,
        ], 'Automation ID')
        ->addColumn('sort_order', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Position in the chain')
        ->addColumn('template_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false,
        ], 'Template ID')
        /*
         * Measured from the moment the chain was triggered, not from the step
         * before. "Day 2, day 7, day 30" is how somebody describes a sequence,
         * and relative delays turn an edit to one step into a silent shift of
         * everything after it.
         */
        ->addColumn('after_days', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Days after the trigger')
        ->addColumn('after_hours', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Hours after the trigger')
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/automation_step', ['automation_id', 'sort_order']), ['automation_id', 'sort_order'])
        ->addForeignKey(
            $installer->getFkName('ysrtech_emailcampaigns/automation_step', 'automation_id', 'ysrtech_emailcampaigns/automation', 'automation_id'),
            'automation_id',
            $automationTable,
            'automation_id',
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->addForeignKey(
            $installer->getFkName('ysrtech_emailcampaigns/automation_step', 'template_id', 'ysrtech_emailcampaigns/template', 'template_id'),
            'template_id',
            $installer->getTable('ysrtech_emailcampaigns/template'),
            'template_id',
            Varien_Db_Adapter_Interface::FK_ACTION_RESTRICT,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->setComment('The messages a chain sends, in order');

    $connection->createTable($table);
}

// When the chain should give up on the rest of its steps
if (!$connection->tableColumnExists($automationTable, 'cancel_on')) {
    $connection->addColumn($automationTable, 'cancel_on', [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 32,
        'nullable' => false, 'default' => 'never',
        'comment' => 'never, or order_placed',
    ]);
}

// --- the queue learns which step it is carrying ---

if (!$connection->tableColumnExists($queueTable, 'step_id')) {
    $connection->addColumn($queueTable, 'step_id', [
        'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => true,
        'comment' => 'Which step of the chain, null for campaign mail',
    ]);

    /*
     * Without queued_at there is no way to ask "did they order after this was
     * queued", which is the whole basis for calling a chain off.
     */
    $connection->addColumn($queueTable, 'queued_at', [
        'type' => Varien_Db_Ddl_Table::TYPE_DATETIME, 'nullable' => true,
        'comment' => 'When the trigger fired',
    ]);

    /*
     * The old key was (automation_id, object_type, object_id), which was right
     * for a single message and wrong the moment one order queues three: every
     * step after the first would collide with it and be dropped.
     */
    foreach ($connection->getIndexList($queueTable) as $name => $index) {
        if ($index['COLUMNS_LIST'] === ['automation_id', 'object_type', 'object_id']) {
            $connection->dropIndex($queueTable, $name);
        }
    }

    $connection->addIndex(
        $queueTable,
        $installer->getIdxName(
            'ysrtech_emailcampaigns/queue',
            ['automation_id', 'step_id', 'object_type', 'object_id'],
            Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
        ),
        ['automation_id', 'step_id', 'object_type', 'object_id'],
        Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
    );

    $connection->addForeignKey(
        $installer->getFkName('ysrtech_emailcampaigns/queue', 'step_id', 'ysrtech_emailcampaigns/automation_step', 'step_id'),
        $queueTable,
        'step_id',
        $stepTable,
        'step_id',
        Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
        Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
    );
}

// --- existing single-message automations become one-step chains ---

if ($connection->tableColumnExists($automationTable, 'template_id')) {
    $connection->query(
        "INSERT INTO {$stepTable} (automation_id, sort_order, template_id, after_days, after_hours)
         SELECT a.automation_id, 0, a.template_id,
                CASE WHEN a.send_moment = 'after' THEN a.after_days ELSE 0 END,
                CASE WHEN a.send_moment = 'after' THEN a.after_hours ELSE 0 END
         FROM {$automationTable} a
         WHERE NOT EXISTS (SELECT 1 FROM {$stepTable} s WHERE s.automation_id = a.automation_id)"
    );

    foreach ($connection->getForeignKeys($automationTable) as $key) {
        if (($key['COLUMN_NAME'] ?? null) === 'template_id') {
            $connection->dropForeignKey($automationTable, $key['FK_NAME']);
        }
    }

    foreach (['template_id', 'send_moment', 'after_days', 'after_hours'] as $column) {
        if ($connection->tableColumnExists($automationTable, $column)) {
            $connection->dropColumn($automationTable, $column);
        }
    }
}

$installer->endSetup();
