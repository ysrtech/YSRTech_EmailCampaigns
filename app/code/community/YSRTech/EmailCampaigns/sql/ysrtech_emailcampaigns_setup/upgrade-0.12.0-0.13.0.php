<?php
/**
 * Lets a queued message outlive an edit to the automation that queued it.
 *
 * Every step of a chain is queued when the trigger fires, each with its own
 * due time, so at any moment there are messages waiting that were promised
 * under the flow as it stood days ago. Three things made editing a flow
 * unsafe for them:
 *
 *  - the queue row only pointed at its step, so changing a step's template
 *    changed what somebody already halfway through the chain would receive;
 *  - the foreign key on step_id cascaded, so removing a step deleted every
 *    message waiting on it, and the history of every one it had sent;
 *  - the unique key that stops a trigger being queued twice included step_id,
 *    so a step that was removed and added back got a new id and the same
 *    order could be queued a second time.
 *
 * So the row now carries what it was queued with - the template, the
 * published version of the flow, and the step's position in the chain - the
 * key is on the position rather than the step's id, and losing a step only
 * clears the pointer.
 *
 * The automation gets a version that counts its publications, and somewhere
 * to keep the drawing: the canvas has node positions and half-finished
 * drafts that the step rows cannot hold.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection  = $installer->getConnection();
$automations = $installer->getTable('ysrtech_emailcampaigns/automation');
$steps       = $installer->getTable('ysrtech_emailcampaigns/automation_step');
$queue       = $installer->getTable('ysrtech_emailcampaigns/queue');

if (!$connection->tableColumnExists($automations, 'version')) {
    $connection->addColumn($automations, 'version', [
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => false,
        'default'  => 1,
        'comment'  => 'Counts publications: bumped each time it goes live',
    ]);
}

if (!$connection->tableColumnExists($automations, 'published_at')) {
    $connection->addColumn($automations, 'published_at', [
        'type'     => Varien_Db_Ddl_Table::TYPE_DATETIME,
        'nullable' => true,
        'comment'  => 'When it last went live',
    ]);

    // Whatever is live now was published at some point; the last save is the
    // nearest thing on record to when.
    $connection->query(
        "UPDATE {$automations}
            SET published_at = COALESCE(updated_at, created_at)
          WHERE is_active = 1 AND published_at IS NULL"
    );
}

if (!$connection->tableColumnExists($automations, 'graph_json')) {
    $connection->addColumn($automations, 'graph_json', [
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => '1M',
        'nullable' => true,
        'comment'  => 'The canvas drawing, when it was last saved from the canvas',
    ]);
}

$added = false;

if (!$connection->tableColumnExists($queue, 'template_id')) {
    // No foreign key, on purpose. Restricting would stop a template ever
    // being deleted once it had been sent, and setting null would throw away
    // the one fact this column is here to keep.
    $connection->addColumn($queue, 'template_id', [
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => true,
        'comment'  => 'Template this triggered message was queued with',
    ]);
    $added = true;
}

if (!$connection->tableColumnExists($queue, 'automation_version')) {
    $connection->addColumn($queue, 'automation_version', [
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => true,
        'comment'  => 'Version of the automation that queued it',
    ]);
    $added = true;
}

if (!$connection->tableColumnExists($queue, 'step_order')) {
    $connection->addColumn($queue, 'step_order', [
        'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
        'unsigned' => true,
        'nullable' => true,
        'comment'  => 'Position in the chain of the step that queued it',
    ]);
    $added = true;
}

if ($added) {
    // Rows queued before this existed take what their step says today, which
    // is what they would have been sent with anyway.
    $connection->query(
        "UPDATE {$queue} q
           JOIN {$steps} s ON s.step_id = q.step_id
            SET q.template_id        = COALESCE(q.template_id, s.template_id),
                q.step_order         = COALESCE(q.step_order, s.sort_order),
                q.automation_version = COALESCE(q.automation_version, 1)
          WHERE q.automation_id IS NOT NULL"
    );
}

/*
 * The step pointer: from cascade to set null. Found by what it references
 * rather than by name, since the name is a hash of the definition.
 */
$stepFkName = $installer->getFkName(
    'ysrtech_emailcampaigns/queue',
    'step_id',
    'ysrtech_emailcampaigns/automation_step',
    'step_id'
);

foreach ($connection->getForeignKeys($queue) as $foreignKey) {
    if ($foreignKey['COLUMN_NAME'] === 'step_id'
        && $foreignKey['ON_DELETE'] !== Varien_Db_Ddl_Table::ACTION_SET_NULL
    ) {
        $connection->dropForeignKey($queue, $foreignKey['FK_NAME']);
        $connection->addForeignKey(
            $stepFkName,
            $queue,
            'step_id',
            $steps,
            'step_id',
            Varien_Db_Ddl_Table::ACTION_SET_NULL,
            Varien_Db_Ddl_Table::ACTION_CASCADE
        );
    }
}

/*
 * The duplicate guard: from the step's id to its position.
 */
$newColumns = ['automation_id', 'object_type', 'object_id', 'step_order'];
$newName    = $installer->getIdxName(
    'ysrtech_emailcampaigns/queue',
    $newColumns,
    Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
);
$hasNew     = false;

foreach ($connection->getIndexList($queue) as $index) {
    $columns = array_map('strtolower', $index['COLUMNS_LIST']);

    if ($columns === $newColumns) {
        $hasNew = true;
    }

    if ($columns === ['automation_id', 'step_id', 'object_type', 'object_id']) {
        $connection->dropIndex($queue, $index['KEY_NAME']);
    }
}

if (!$hasNew) {
    /*
     * Two steps sharing a position should not exist, but nothing in the
     * schema ever said so, and a unique key that cannot be created fails the
     * whole upgrade. Where it has happened, the later rows give up their
     * position - they lose the guard, which is no worse than they had.
     */
    $connection->query(
        "UPDATE {$queue} q
           JOIN (SELECT automation_id, object_type, object_id, step_order, MIN(queue_id) AS keep_id
                   FROM {$queue}
                  WHERE automation_id IS NOT NULL AND step_order IS NOT NULL
                  GROUP BY automation_id, object_type, object_id, step_order
                 HAVING COUNT(*) > 1) d
             ON d.automation_id = q.automation_id
            AND d.object_type = q.object_type
            AND d.object_id = q.object_id
            AND d.step_order = q.step_order
            SET q.step_order = NULL
          WHERE q.queue_id <> d.keep_id"
    );

    $connection->addIndex($queue, $newName, $newColumns, Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE);
}

$installer->endSetup();
