<?php
/**
 * Preview text for a template, and somewhere to keep sections saved from the
 * designer.
 *
 * Preview text is the line an inbox shows after the subject. Without one of
 * its own a message shows whatever its first words happen to be, which for
 * most templates is "View in browser" or the alt text of a logo.
 *
 * A saved section is a piece of a design - a header, a footer with the
 * address, a product row - kept by name so it can be dropped into any other
 * template instead of being rebuilt in each.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$templates  = $installer->getTable('ysrtech_emailcampaigns/template');
$sections   = $installer->getTable('ysrtech_emailcampaigns/section');

if (!$connection->tableColumnExists($templates, 'preheader')) {
    $connection->addColumn($templates, 'preheader', [
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 255,
        'nullable' => true,
        'after'    => 'subject',
        'comment'  => 'Preview text shown after the subject in an inbox',
    ]);
}

if (!$connection->isTableExists($sections)) {
    $table = $connection->newTable($sections)
        ->addColumn('section_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ], 'Section ID')
        ->addColumn('name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Name')
        ->addColumn('html', Varien_Db_Ddl_Table::TYPE_TEXT, '1M', [], 'Inlined HTML of the section')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, ['nullable' => false], 'Created At')
        ->setComment('Reusable pieces of email design, saved from the designer');

    $connection->createTable($table);
}

$installer->endSetup();
