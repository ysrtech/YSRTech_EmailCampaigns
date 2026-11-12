<?php
/**
 * Three of this module's grids became card pages, and their grid classes
 * went. Enhanced Admin Grids (BL_CustomGrid), where it is installed, keeps a
 * record of every grid it has seen and goes on trying to extend the class on
 * that page: "The original class does not exist", at the foot of the
 * campaigns, templates and automations pages. Its records of those three
 * grids are removed; its own tables remove the columns, profiles and
 * permissions that hung off them.
 *
 * Nothing happens on a store without that module.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table      = $installer->getTable('customgrid_grid');

if ($connection->isTableExists($table) && $connection->tableColumnExists($table, 'block_type')) {
    $connection->delete($table, ['block_type IN (?)' => [
        'ysrtech_emailcampaigns/adminhtml_campaign_grid',
        'ysrtech_emailcampaigns/adminhtml_template_grid',
        'ysrtech_emailcampaigns/adminhtml_automation_grid',
    ]]);
}

$installer->endSetup();
