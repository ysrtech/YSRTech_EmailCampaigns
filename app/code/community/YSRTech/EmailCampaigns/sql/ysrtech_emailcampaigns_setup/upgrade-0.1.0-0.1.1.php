<?php
/**
 * YSRTech EmailCampaigns upgrade 0.1.0 -> 0.1.1.
 *
 * Adds the unique key buildQueue() has always assumed. It writes with
 * insertOnDuplicate, which needs a unique key to detect a duplicate against;
 * with none, a campaign queued twice mails every recipient twice.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */

$installer  = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$table      = $installer->getTable('ysrtech_emailcampaigns/queue');

if ($connection->isTableExists($table)) {
    // Any duplicates already queued have to go first, or the key cannot be
    // added. Keep the earliest row for each recipient - it is the one that may
    // already have been sent and carries the token that went out with it.
    $connection->query(
        "DELETE q FROM {$table} q
         JOIN (
             SELECT campaign_id, email, MIN(queue_id) AS keep_id
             FROM {$table}
             GROUP BY campaign_id, email
             HAVING COUNT(*) > 1
         ) d ON d.campaign_id = q.campaign_id AND d.email = q.email
         WHERE q.queue_id > d.keep_id"
    );

    $indexName = $installer->getIdxName(
        'ysrtech_emailcampaigns/queue',
        array('campaign_id', 'email'),
        Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
    );

    $connection->addIndex(
        $table,
        $indexName,
        array('campaign_id', 'email'),
        Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE
    );
}

$installer->endSetup();
