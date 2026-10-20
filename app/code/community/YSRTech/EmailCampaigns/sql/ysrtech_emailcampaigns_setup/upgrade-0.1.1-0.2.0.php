<?php
/**
 * YSRTech EmailCampaigns upgrade 0.1.1 -> 0.2.0.
 *
 * Moves the audience from customers to newsletter subscribers.
 *
 * The store's mailing list is the newsletter, and customers are subscribed to
 * it automatically - so the customer table was the wrong base to build on. On
 * this store 82% of subscribed addresses have no customer account at all, and
 * keying membership on customer_id made every one of them unreachable.
 *
 * The queue gains the columns a claim step needs, so that two overlapping cron
 * runs cannot pick up the same rows and mail everybody twice.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */

$installer  = $this;
$installer->startSetup();

$connection = $installer->getConnection();

/* ---------- Segment membership, keyed on the subscriber ---------- */

$membership = $installer->getTable('ysrtech_emailcampaigns/segment_subscriber');

if (!$connection->isTableExists($membership)) {
    $table = $connection->newTable($membership)
        ->addColumn('segment_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true, 'nullable' => false,
        ), 'Segment ID')
        ->addColumn('subscriber_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true, 'nullable' => false,
        ), 'Newsletter Subscriber ID')
        ->addIndex(
            $installer->getIdxName(
                'ysrtech_emailcampaigns/segment_subscriber',
                array('segment_id', 'subscriber_id'),
                Varien_Db_Adapter_Interface::INDEX_TYPE_PRIMARY
            ),
            array('segment_id', 'subscriber_id'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_PRIMARY)
        )
        ->addIndex(
            $installer->getIdxName('ysrtech_emailcampaigns/segment_subscriber', array('subscriber_id')),
            array('subscriber_id')
        )
        ->addForeignKey(
            $installer->getFkName(
                'ysrtech_emailcampaigns/segment_subscriber',
                'segment_id',
                'ysrtech_emailcampaigns/segment',
                'segment_id'
            ),
            'segment_id',
            $installer->getTable('ysrtech_emailcampaigns/segment'),
            'segment_id',
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->addForeignKey(
            $installer->getFkName(
                'ysrtech_emailcampaigns/segment_subscriber',
                'subscriber_id',
                'newsletter/subscriber',
                'subscriber_id'
            ),
            'subscriber_id',
            $installer->getTable('newsletter/subscriber'),
            'subscriber_id',
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE,
            Varien_Db_Adapter_Interface::FK_ACTION_CASCADE
        )
        ->setComment('Segment membership, by newsletter subscriber');

    $connection->createTable($table);
}

// The customer-keyed table it replaces. Membership is derived, so there is
// nothing here worth migrating - the next reindex rebuilds it.
$old = $installer->getTable('ysrtech_emailcampaigns/segment_customer');

if ($connection->isTableExists($old)) {
    $connection->dropTable($old);
}

/* ---------- Queue: subscriber identity, and the claim columns ---------- */

$queue = $installer->getTable('ysrtech_emailcampaigns/queue');

if (!$connection->tableColumnExists($queue, 'subscriber_id')) {
    $connection->addColumn($queue, 'subscriber_id', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'unsigned' => true,
        'nullable' => true,
        'comment'  => 'Newsletter Subscriber ID',
    ));
}

if (!$connection->tableColumnExists($queue, 'lock_token')) {
    // A run stamps its own token on the rows it claims, then sends only those.
    $connection->addColumn($queue, 'lock_token', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 32,
        'nullable' => true,
        'comment'  => 'Token of the run that claimed this row',
    ));
}

if (!$connection->tableColumnExists($queue, 'locked_at')) {
    // So a claim left behind by a crashed run can be released and retried.
    $connection->addColumn($queue, 'locked_at', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_DATETIME,
        'nullable' => true,
        'comment'  => 'When this row was claimed',
    ));
}

$connection->addIndex(
    $queue,
    $installer->getIdxName('ysrtech_emailcampaigns/queue', array('lock_token')),
    array('lock_token')
);

$installer->endSetup();
