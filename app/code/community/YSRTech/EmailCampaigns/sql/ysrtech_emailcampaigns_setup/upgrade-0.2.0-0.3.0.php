<?php
/**
 * A campaign targets any number of segments, and can exclude segments too.
 *
 * One segment per campaign forced a new segment for every combination anybody
 * wanted to mail - "past customers", then "past customers except lapsed", then
 * the same again minus staff - each one its own rule to keep in step with the
 * others. Included and excluded lists express that directly, and exclusion is
 * the half that cannot be built out of includes at all.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$linkTable  = $installer->getTable('ysrtech_emailcampaigns/campaign_segment');

if (!$connection->isTableExists($linkTable)) {
    $table = $connection->newTable($linkTable)
        ->addColumn('campaign_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false,
        ], 'Campaign ID')
        ->addColumn('segment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false,
        ], 'Segment ID')
        ->addColumn('is_excluded', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Excluded rather than included')
        /*
         * Campaign and segment alone are the key, so one segment cannot be
         * both included and excluded on the same campaign - a state with no
         * sensible reading, and one a form could otherwise post.
         */
        ->addIndex(
            $installer->getIdxName('ysrtech_emailcampaigns/campaign_segment', ['campaign_id', 'segment_id']),
            ['campaign_id', 'segment_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_PRIMARY]
        )
        ->addIndex(
            $installer->getIdxName('ysrtech_emailcampaigns/campaign_segment', ['segment_id']),
            ['segment_id']
        )
        ->addForeignKey(
            $installer->getFkName('ysrtech_emailcampaigns/campaign_segment', 'campaign_id', 'ysrtech_emailcampaigns/campaign', 'campaign_id'),
            'campaign_id',
            $installer->getTable('ysrtech_emailcampaigns/campaign'),
            'campaign_id',
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->addForeignKey(
            $installer->getFkName('ysrtech_emailcampaigns/campaign_segment', 'segment_id', 'ysrtech_emailcampaigns/segment', 'segment_id'),
            'segment_id',
            $installer->getTable('ysrtech_emailcampaigns/segment'),
            'segment_id',
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->setComment('Segments a campaign includes or excludes');

    $connection->createTable($table);
}

$campaignTable = $installer->getTable('ysrtech_emailcampaigns/campaign');

// Carry every existing campaign's single target over as an included segment,
// before the column it lived in goes away.
if ($connection->tableColumnExists($campaignTable, 'segment_id')) {
    $connection->query(
        "INSERT IGNORE INTO {$linkTable} (campaign_id, segment_id, is_excluded)
         SELECT c.campaign_id, c.segment_id, 0
         FROM {$campaignTable} c
         WHERE c.segment_id IS NOT NULL
           AND EXISTS (SELECT 1 FROM {$installer->getTable('ysrtech_emailcampaigns/segment')} s
                       WHERE s.segment_id = c.segment_id)"
    );

    foreach ($connection->getForeignKeys($campaignTable) as $key) {
        if (($key['COLUMN_NAME'] ?? null) === 'segment_id') {
            $connection->dropForeignKey($campaignTable, $key['FK_NAME']);
        }
    }

    // Dropped rather than left in place: two records of the same fact drift,
    // and code reading the stale one sends to the wrong list.
    $connection->dropColumn($campaignTable, 'segment_id');
}

$installer->endSetup();
