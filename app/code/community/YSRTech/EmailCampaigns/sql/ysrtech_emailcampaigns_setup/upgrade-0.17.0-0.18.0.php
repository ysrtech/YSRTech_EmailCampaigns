<?php
/**
 * The builder keeps an email as a document: a campaign's own copy needs
 * somewhere to hold one, as a template already has, and a saved section is
 * now a row of that document rather than a piece of HTML.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

$campaign = $installer->getTable('ysrtech_emailcampaigns/campaign');

if (!$connection->tableColumnExists($campaign, 'design_json')) {
    $connection->addColumn($campaign, 'design_json', [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => '2M', 'nullable' => true,
        'comment' => 'The builder document this campaign\'s own email is made from',
    ]);
}

$section = $installer->getTable('ysrtech_emailcampaigns/section');

if (!$connection->tableColumnExists($section, 'row_json')) {
    $connection->addColumn($section, 'row_json', [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => '2M', 'nullable' => true,
        'comment' => 'A builder row, saved to use again',
    ]);
    $connection->modifyColumn($section, 'html', 'MEDIUMTEXT NULL');
}

$installer->endSetup();
