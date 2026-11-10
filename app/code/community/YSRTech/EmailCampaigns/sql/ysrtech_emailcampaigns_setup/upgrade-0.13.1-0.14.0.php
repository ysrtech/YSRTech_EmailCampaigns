<?php
/**
 * A campaign that owns its email.
 *
 * Until now a campaign held a pointer to a template and nothing else about
 * what it sends. Changing the design meant changing the template - and with
 * it every other campaign and automation that used it - and a campaign
 * could not have a sender, a reply address or preview text of its own.
 *
 * The content is now copied into the campaign when a template or starter is
 * chosen, and edited there. A campaign with nothing of its own still sends
 * its template, which is every campaign that existed before this.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$campaigns  = $installer->getTable('ysrtech_emailcampaigns/campaign');

foreach ([
    'html' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => '4M', 'nullable' => true,
        'comment' => 'The email itself, once the campaign has its own copy',
    ],
    'preheader' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 255, 'nullable' => true,
        'comment' => 'Preview text shown after the subject in an inbox',
    ],
    'from_name' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 255, 'nullable' => true,
        'comment' => 'Sender name, when not the configured default',
    ],
    /*
     * The part before the @ only. The domain is the sending domain, fixed in
     * the configuration: mail from any other fails the checks mailbox
     * providers run and is filed as spam, so it is not a per-campaign choice.
     */
    'from_local' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 64, 'nullable' => true,
        'comment' => 'Sender address local part; the domain is the configured one',
    ],
    'reply_to' => [
        'type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 255, 'nullable' => true,
        'comment' => 'Where replies go, when not the sender',
    ],
    'updated_at' => [
        'type' => Varien_Db_Ddl_Table::TYPE_DATETIME, 'nullable' => true,
        'comment' => 'Last edited',
    ],
] as $column => $definition) {
    if (!$connection->tableColumnExists($campaigns, $column)) {
        $connection->addColumn($campaigns, $column, $definition);
    }
}

$installer->endSetup();
