<?php
/**
 * Adds store scope to flows — the same "Store View" field campaigns already
 * have (see install-0.1.0.php's campaign table), extended here to also gate
 * enrollment: a flow scoped to a specific store view only enrolls orders
 * placed on that store view, rather than every store (0 = All Store Views).
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$installer->getConnection()->addColumn($installer->getTable('ysrtech_emailcampaigns/flow'), 'store_id', [
    'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
    'unsigned' => true,
    'nullable' => false,
    'default'  => 0,
    'comment'  => 'Store View ID (0 = All Store Views)',
]);

$installer->endSetup();
