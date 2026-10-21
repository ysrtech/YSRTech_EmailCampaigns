<?php
/**
 * Create the subscriber name columns this module reads.
 *
 * Segment::_streamCandidates() and Sender::_loadSubscriberNames() both select
 * newsletter_subscriber.subscriber_firstname and subscriber_lastname, and
 * nothing ever created them. Stock OpenMage's newsletter_subscriber has seven
 * columns and neither of these is among them: this store has them from
 * somewhere else, so the module worked here and would fatal on a plain
 * install with
 *
 *   Unknown column 'ns.subscriber_firstname' in 'SELECT'
 *
 * the first time a segment reindexed. Which is exactly what it did the first
 * time it was stood up on a clean instance.
 *
 * Added rather than removed from the queries, because a subscriber who never
 * created an account has their name nowhere else - the customer collection
 * the sender uses for everybody else has no row for them. Guarded, so on an
 * install that already has the columns this changes nothing and whatever
 * fills them keeps filling them.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table      = $installer->getTable('newsletter/subscriber');

foreach ([
    'subscriber_firstname' => 'Subscriber First Name',
    'subscriber_lastname'  => 'Subscriber Last Name',
] as $column => $comment) {
    if (!$connection->tableColumnExists($table, $column)) {
        $connection->addColumn($table, $column, [
            'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
            'length'   => 255,
            'nullable' => true,
            'comment'  => $comment,
        ]);
    }
}

$installer->endSetup();
