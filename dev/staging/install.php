<?php
// Console install for a throwaway OpenMage instance used to test this module.
// Invoked by setup.sh with the OpenMage core directory as the working directory,
// after this module has been symlinked into it.

// Core's Eav install script (install-1.6.0.0.php) calls a #[Deprecated] method;
// the console installer's error handler otherwise treats that notice as fatal.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once 'app/Mage.php';

Mage::setIsDeveloperMode(true);
ini_set('display_errors', 1);
umask(0);

$app = Mage::app('default');
/** @var Mage_Install_Model_Installer_Console $installer */
$installer = Mage::getSingleton('install/installer_console');

$port = getenv('PORT') ?: '8080';
$dbName = getenv('DB_NAME') ?: 'ysrtech_emailcampaigns_staging';
$baseUrl = "http://127.0.0.1:{$port}/";

$args = [
    'license_agreement_accepted' => 'yes',
    'locale'                     => 'en_US',
    'timezone'                   => 'America/New_York',
    'default_currency'           => 'USD',
    'db_host'                    => 'localhost',
    'db_name'                    => $dbName,
    'db_user'                    => 'root',
    'db_pass'                    => '',
    'url'                        => $baseUrl,
    'skip_url_validation'        => 'yes',
    'use_rewrites'               => 'no',
    'use_secure'                 => 'no',
    'secure_base_url'            => $baseUrl,
    'use_secure_admin'           => 'no',
    'admin_firstname'            => 'Staging',
    'admin_lastname'             => 'Admin',
    'admin_email'                => 'staging@example.com',
    'admin_username'             => 'admin',
    'admin_password'             => 'StagingAdmin12345',
    'session_save'               => 'files',
];

if ($installer->init($app) && $installer->setArgs($args) && !$installer->hasErrors()) {
    $installer->install();
}

if ($installer->hasErrors()) {
    echo "INSTALL ERRORS:\n - " . implode("\n - ", $installer->getErrors()) . "\n";
    exit(1);
}
echo "INSTALL OK\n";
