<?php
/**
 * A campaign can go to every subscriber, rather than to the people in
 * chosen segments.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$table = $installer->getTable('ysrtech_emailcampaigns/campaign');

if (!$installer->getConnection()->tableColumnExists($table, 'send_to_all')) {
    $installer->getConnection()->addColumn($table, 'send_to_all', [
        'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
        'unsigned' => true,
        'nullable' => false,
        'default'  => 0,
        'comment'  => '1: every subscriber, less the excluded segments',
    ]);
}

$installer->endSetup();
