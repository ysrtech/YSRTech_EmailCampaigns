<?php
/**
 * A suppression list with a reason, a detail and a date for every address,
 * and somewhere on the queue to record that a particular message bounced or
 * was reported.
 *
 * Until now "do not mail this address" was one nullable timestamp on the
 * preference table, with the reason tucked into a JSON blob beside it. That
 * was enough to stop the mail and not enough to show anybody why, to tell a
 * hard bounce from a spam report, or to take one off again without a query.
 *
 * And a bounce or complaint was recorded against the address only. Nothing
 * said which message it was, so there was no bounce rate or complaint rate to
 * show for a period or a campaign - the two numbers a mailbox provider
 * actually judges a sender on.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection  = $installer->getConnection();
$suppression = $installer->getTable('ysrtech_emailcampaigns/suppression');
$prefTable   = $installer->getTable('ysrtech_emailcampaigns/subscriber_pref');
$queueTable  = $installer->getTable('ysrtech_emailcampaigns/queue');
$subscribers = $installer->getTable('newsletter/subscriber');

if (!$connection->isTableExists($suppression)) {
    $table = $connection->newTable($suppression)
        ->addColumn('suppression_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Suppression ID')
        ->addColumn('email', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Address')
        ->addColumn('reason', Varien_Db_Ddl_Table::TYPE_TEXT, 20, ['nullable' => false], 'hard_bounce/complaint/unsubscribe/manual')
        ->addColumn('detail', Varien_Db_Ddl_Table::TYPE_TEXT, 500, [], 'What the provider or the admin said about it')
        ->addColumn('source', Varien_Db_Ddl_Table::TYPE_TEXT, 32, [], 'Where it came from: webhook, mailgun_sync, admin, import...')
        /*
         * What the newsletter status was before this suppression set it to
         * unsubscribed. Without it, lifting a bounce could not put somebody
         * back as subscribed without also guessing that they had been.
         */
        ->addColumn('prev_subscriber_status', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true, 'nullable' => true,
        ], 'newsletter_subscriber.subscriber_status before suppression')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false], 'Added At')
        ->addIndex(
            $installer->getIdxName('ysrtech_emailcampaigns/suppression', ['email'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['email'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE]
        )
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/suppression', ['reason']), ['reason'])
        ->addIndex($installer->getIdxName('ysrtech_emailcampaigns/suppression', ['created_at']), ['created_at'])
        ->setComment('Addresses never to be mailed, and why');

    $connection->createTable($table);

    /*
     * Carry over what the preference table knows. The reason was stored as
     * whatever event name the provider used, so it is mapped here the same
     * way Model_Suppression::reasonFor() maps a live one.
     */
    $rows = $connection->fetchAll(
        $connection->select()
            ->from($prefTable, ['email', 'unsubscribed_at', 'preferences_json'])
            ->where('unsubscribed_at IS NOT NULL')
    );

    $map = [
        'complained'       => 'complaint',
        'email.complained' => 'complaint',
        'failed'           => 'hard_bounce',
        'email.bounced'    => 'hard_bounce',
    ];

    $batch = [];

    foreach ($rows as $row) {
        $prefs = json_decode((string) $row['preferences_json'], true);
        $event = is_array($prefs) && isset($prefs['suppression_reason']) ? (string) $prefs['suppression_reason'] : '';

        $batch[] = [
            'email'      => strtolower(trim((string) $row['email'])),
            'reason'     => $map[$event] ?? 'unsubscribe',
            'detail'     => null,
            'source'     => 'migrated',
            'created_at' => $row['unsubscribed_at'],
        ];

        if (count($batch) >= 500) {
            $connection->insertOnDuplicate($suppression, $batch, ['source']);
            $batch = [];
        }
    }

    if ($batch) {
        $connection->insertOnDuplicate($suppression, $batch, ['source']);
    }
}

/*
 * Per-message outcomes. The open and click columns are already here; these
 * are the two a sender is judged on, and the count of opens that were a
 * mail client fetching images on its own rather than a person reading.
 */
foreach ([
    'bounced_at' => [
        'type' => Varien_Db_Ddl_Table::TYPE_DATETIME, 'nullable' => true,
        'comment' => 'When the provider reported a permanent failure for this message',
    ],
    'complained_at' => [
        'type' => Varien_Db_Ddl_Table::TYPE_DATETIME, 'nullable' => true,
        'comment' => 'When the recipient reported this message as spam',
    ],
    'machine_open_count' => [
        'type' => Varien_Db_Ddl_Table::TYPE_INTEGER, 'unsigned' => true, 'nullable' => false, 'default' => 0,
        'comment' => 'Opens made by a mail client prefetching, e.g. Apple Mail Privacy Protection',
    ],
] as $column => $definition) {
    if (!$connection->tableColumnExists($queueTable, $column)) {
        $connection->addColumn($queueTable, $column, $definition);
    }
}

// The dashboard reads the queue by when things were sent, which nothing
// indexed: every other reader comes in by campaign or by status.
$sentIndex = $installer->getIdxName('ysrtech_emailcampaigns/queue', ['sent_at']);

if (!array_key_exists(strtoupper($sentIndex), $connection->getIndexList($queueTable))) {
    $connection->addIndex($queueTable, $sentIndex, ['sent_at']);
}

$installer->endSetup();
