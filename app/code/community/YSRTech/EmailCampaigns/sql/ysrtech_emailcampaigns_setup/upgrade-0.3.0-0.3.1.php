<?php
/**
 * Stop a segment deletion from quietly rewriting live campaigns.
 *
 * The link table's segment key cascaded, so deleting a segment removed it
 * from every campaign that named it - including one already scheduled, whose
 * audience then silently became something else. Restrict instead, and let the
 * controller explain which campaigns are in the way.
 *
 * The campaign key stays cascading: deleting a campaign should take its own
 * links with it.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$linkTable  = $installer->getTable('ysrtech_emailcampaigns/campaign_segment');

if ($connection->isTableExists($linkTable)) {
    $fkName = $installer->getFkName(
        'ysrtech_emailcampaigns/campaign_segment',
        'segment_id',
        'ysrtech_emailcampaigns/segment',
        'segment_id'
    );

    $connection->dropForeignKey($linkTable, $fkName);

    $connection->addForeignKey(
        $fkName,
        $linkTable,
        'segment_id',
        $installer->getTable('ysrtech_emailcampaigns/segment'),
        'segment_id',
        Varien_Db_Adapter_Interface::FK_ACTION_RESTRICT,
        Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
    );
}

$installer->endSetup();
