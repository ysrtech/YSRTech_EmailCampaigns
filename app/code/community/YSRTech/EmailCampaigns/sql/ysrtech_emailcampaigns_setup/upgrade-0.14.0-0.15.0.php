<?php
/**
 * Whether a template or a campaign wears the store's header and footer.
 *
 * The header, the footer and the colours are now set once per store view
 * and put around an email when it is rendered. Everything created from here
 * on has that switched on. Everything that already exists has it switched
 * off: those emails were designed with a footer of their own, and turning
 * the store's on underneath them would send two.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

foreach (['ysrtech_emailcampaigns/template', 'ysrtech_emailcampaigns/campaign'] as $entity) {
    $table = $installer->getTable($entity);

    if (!$connection->tableColumnExists($table, 'use_brand')) {
        $connection->addColumn($table, 'use_brand', [
            'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
            'unsigned' => true,
            'nullable' => false,
            'default'  => 1,
            'comment'  => 'Wrap in the store view\'s header and footer',
        ]);

        // The column's default is for what comes next, not what is here
        $connection->update($table, ['use_brand' => 0]);
    }
}

$installer->endSetup();
