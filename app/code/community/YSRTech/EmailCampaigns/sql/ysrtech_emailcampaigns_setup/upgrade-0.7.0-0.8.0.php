<?php
/**
 * Minutes, so the canvas cannot promise a delay the schema will not keep.
 *
 * A step's delay was days plus hours. The visual editor offers minutes as
 * well, and without somewhere to put them "wait 30 minutes" would round to
 * nothing and the message would go out immediately - a quiet wrong answer,
 * which is the worst kind. Abandoned-cart timing wants them anyway: an hour
 * is a long time to wait before reminding somebody about a cart.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$stepTable  = $installer->getTable('ysrtech_emailcampaigns/automation_step');

if (!$connection->tableColumnExists($stepTable, 'after_minutes')) {
    $connection->addColumn($stepTable, 'after_minutes', [
        'type'     => Varien_Db_Ddl_Table::TYPE_SMALLINT,
        'unsigned' => true,
        'nullable' => false,
        'default'  => 0,
        'comment'  => 'Minutes after the trigger, on top of the days and hours',
    ]);
}

$installer->endSetup();
