<?php
/**
 * A record of every call made to the AI provider: which feature asked, how
 * many tokens it took and, where the provider says, what it cost. It is what
 * the hourly cap counts, and the answer to "what is this spending".
 *
 * Nothing that was sent or received is kept, only the size of it.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$name       = $installer->getTable('ysrtech_emailcampaigns/ai_usage');

if (!$connection->isTableExists($name)) {
    $table = $connection->newTable($name)
        ->addColumn('usage_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('feature', Varien_Db_Ddl_Table::TYPE_TEXT, 40, ['nullable' => false])
        ->addColumn('provider', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['nullable' => false])
        ->addColumn('model', Varien_Db_Ddl_Table::TYPE_TEXT, 120, ['nullable' => false])
        ->addColumn('prompt_tokens', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('completion_tokens', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('cost_usd', Varien_Db_Ddl_Table::TYPE_DECIMAL, '12,6', ['nullable' => true])
        ->addColumn('duration_ms', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('ok', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => false, 'default' => 0])
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => true])
        ->addColumn('admin_user_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/ai_usage', ['created_at']), ['created_at'])
        ->setComment('Email Campaigns: calls made to the AI provider');

    $connection->createTable($table);
}

$installer->endSetup();
