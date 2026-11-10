<?php
/**
 * Imports: one row per CSV file or provider migration, carrying its own
 * progress so that it can be done a batch at a time from cron and picked up
 * where it stopped.
 *
 * Magento 1 has no job queue, and a list of any size cannot be read, checked
 * and written inside one web request. The row is the queue: where the file
 * pointer or the provider's paging cursor has got to, what has been counted
 * so far, and when to try again after a rate limit.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection  = $installer->getConnection();
$importTable = $installer->getTable('ysrtech_emailcampaigns/import');
$seenTable   = $installer->getTable('ysrtech_emailcampaigns/import_seen');
$subscribers = $installer->getTable('newsletter/subscriber');

if (!$connection->isTableExists($importTable)) {
    $table = $connection->newTable($importTable)
        ->addColumn('import_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Import ID')
        ->addColumn('type', Varien_Db_Ddl_Table::TYPE_TEXT, 16, ['nullable' => false], 'csv or provider')
        ->addColumn('provider', Varien_Db_Ddl_Table::TYPE_TEXT, 32, [], 'Provider code, for a migration')
        ->addColumn('source_name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, [], 'File name, or the provider account')
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 16, [
            'nullable' => false, 'default' => 'draft',
        ], 'draft/pending/processing/done/failed/cancelled')
        ->addColumn('stage', Varien_Db_Ddl_Table::TYPE_TEXT, 32, [], 'The stage a migration is on')
        ->addColumn('file_path', Varien_Db_Ddl_Table::TYPE_TEXT, 255, [], 'Uploaded file, relative to var/')
        ->addColumn('mapping_json', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', [], 'Column index => what it holds')
        ->addColumn('options_json', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', [], 'Choices made when it was started')
        /*
         * A provider's API key, encrypted with the store's key, for as long
         * as the migration runs. Cleared the moment it finishes, fails or is
         * cancelled: there is no reason for it to outlive the job.
         */
        ->addColumn('credentials_enc', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', [], 'Encrypted provider credentials')
        ->addColumn('cursor_json', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', [], 'Where the current stage has got to')
        ->addColumn('stats_json', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', [], 'Counts so far')
        ->addColumn('total_rows', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => true], 'Rows expected, when known')
        ->addColumn('processed_rows', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true, 'nullable' => false, 'default' => 0,
        ], 'Rows read so far')
        ->addColumn('retry_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Not before this, after a rate limit')
        ->addColumn('error', Varien_Db_Ddl_Table::TYPE_TEXT, 1000, [], 'Why it stopped, or what it is waiting on')
        ->addColumn('admin_user', Varien_Db_Ddl_Table::TYPE_TEXT, 64, [], 'Who started it')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false], 'Created At')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Last progress')
        ->addColumn('finished_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [], 'Finished At')
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/import', ['status']), ['status'])
        ->setComment('CSV imports and provider migrations, with their progress');

    $connection->createTable($table);
}

/*
 * Addresses an import has already handled, so that one appearing twice in a
 * file - or on two of a provider's lists - is counted as a duplicate rather
 * than as a second contact. Emptied when the import finishes.
 */
if (!$connection->isTableExists($seenTable)) {
    $table = $connection->newTable($seenTable)
        ->addColumn('import_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false], 'Import ID')
        ->addColumn('email_hash', Varien_Db_Ddl_Table::TYPE_TEXT, 40, ['nullable' => false], 'sha1 of the address')
        ->addIndex(
            $installer->getIdxName('ysrtech_emailcampaigns/import_seen', ['import_id', 'email_hash'], Varien_Db_Adapter_Interface::INDEX_TYPE_PRIMARY),
            ['import_id', 'email_hash'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_PRIMARY]
        )
        ->addForeignKey(
            $installer->getFkName('ysrtech_emailcampaigns/import_seen', 'import_id', 'ysrtech_emailcampaigns/import', 'import_id'),
            'import_id',
            $importTable,
            'import_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
            Varien_Db_Ddl_Table::ACTION_CASCADE
        )
        ->setComment('Addresses an import has already handled');

    $connection->createTable($table);
}

/*
 * Stock newsletter_subscriber has no index on the address at all. Everything
 * here that works in sets - an import checking five hundred addresses at a
 * time, the suppression list turning their status, the Mailgun sync - looks
 * subscribers up by address, and without this each of those is a scan of the
 * whole table per batch.
 */
$emailIndex = $installer->getIdxName('newsletter/subscriber', ['subscriber_email']);
$hasIndex   = false;

foreach ($connection->getIndexList($subscribers) as $index) {
    if (($index['COLUMNS_LIST'][0] ?? '') === 'subscriber_email') {
        $hasIndex = true;
        break;
    }
}

if (!$hasIndex) {
    $connection->addIndex($subscribers, $emailIndex, ['subscriber_email']);
}

$installer->endSetup();
