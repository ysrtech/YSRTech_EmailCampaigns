<?php
/**
 * Which orders an email earned: one row per credited order.
 *
 * What the order was credited to is copied onto the row rather than read
 * through the queue, because the nightly sweep removes old queue rows and a
 * campaign's sales should outlive them.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$name = $installer->getTable('ysrtech_emailcampaigns/conversion');

if (!$installer->getConnection()->isTableExists($name)) {
    $table = $installer->getConnection()->newTable($name)
        ->addColumn('conversion_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('queue_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('campaign_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('automation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('step_order', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('email', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false])
        ->addColumn('via', Varien_Db_Ddl_Table::TYPE_TEXT, 10, ['nullable' => false, 'default' => 'link'], 'link: same browser. address: same email address')
        ->addColumn('revenue', Varien_Db_Ddl_Table::TYPE_DECIMAL, '12,4', ['nullable' => false, 'default' => 0], 'Subtotal less discounts when placed, base currency')
        ->addColumn('currency', Varien_Db_Ddl_Table::TYPE_TEXT, 3, ['nullable' => true])
        ->addColumn('clicked_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => true])
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/conversion', ['order_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['order_id'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/conversion', ['campaign_id']), ['campaign_id'])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/conversion', ['automation_id', 'step_order']), ['automation_id', 'step_order'])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/conversion', ['email']), ['email'])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/conversion', ['created_at']), ['created_at'])
        ->setComment('Orders credited to an email');

    $installer->getConnection()->createTable($table);
}

/*
 * Which link of an email each person clicked: one row per person per link,
 * so "how many people clicked this link" is a count of rows. Kept by campaign
 * and automation as well as by message, for the same reason as above.
 */
$name = $installer->getTable('ysrtech_emailcampaigns/link_click');

if (!$installer->getConnection()->isTableExists($name)) {
    $table = $installer->getConnection()->newTable($name)
        ->addColumn('click_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true])
        ->addColumn('queue_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, ['unsigned' => true, 'nullable' => false])
        ->addColumn('campaign_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('automation_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true])
        ->addColumn('url_hash', Varien_Db_Ddl_Table::TYPE_TEXT, 40, ['nullable' => false])
        ->addColumn('url', Varien_Db_Ddl_Table::TYPE_TEXT, 2000, ['nullable' => false])
        ->addColumn('clicks', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false, 'default' => 1])
        ->addColumn('first_clicked_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/link_click', ['queue_id', 'url_hash'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['queue_id', 'url_hash'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/link_click', ['campaign_id', 'url_hash']), ['campaign_id', 'url_hash'])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/link_click', ['automation_id']), ['automation_id'])
        ->setComment('Links clicked, per person');

    $installer->getConnection()->createTable($table);
}

$installer->endSetup();
