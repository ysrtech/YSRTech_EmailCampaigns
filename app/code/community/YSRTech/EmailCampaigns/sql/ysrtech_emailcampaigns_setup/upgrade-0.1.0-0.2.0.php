<?php
/**
 * Adds per-recipient open/click tracking columns to the queue, populated from
 * Mailgun's "opened"/"clicked" webhooks (see WebhookController::mailgunAction()
 * and Sender.php's v:tracking_token custom variable).
 */
/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$queueTable = $installer->getTable('ysrtech_emailcampaigns/queue');
$connection = $installer->getConnection();

$connection->addColumn($queueTable, 'opened_at', [
    'type'    => Varien_Db_Ddl_Table::TYPE_DATETIME,
    'nullable' => true,
    'comment' => 'First Opened At',
]);
$connection->addColumn($queueTable, 'open_count', [
    'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
    'unsigned' => true,
    'nullable' => false,
    'default'  => 0,
    'comment'  => 'Open Count',
]);
$connection->addColumn($queueTable, 'clicked_at', [
    'type'    => Varien_Db_Ddl_Table::TYPE_DATETIME,
    'nullable' => true,
    'comment' => 'First Clicked At',
]);
$connection->addColumn($queueTable, 'click_count', [
    'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
    'unsigned' => true,
    'nullable' => false,
    'default'  => 0,
    'comment'  => 'Click Count',
]);

$installer->endSetup();
