<?php
/**
 * Per-website and per-store-view brand, checked for real: a second website
 * with its own store view, different values at each scope, and an email
 * rendered for each. Creates the website "brandtest" on the install it runs
 * against, so staging only.
 *
 *   cd <openmage-core> && php <module>/dev/tests/brand.php
 */
require_once 'app/Mage.php';
Mage::app('admin');
Mage::register('isSecureArea', true);

$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };

// ---- a second website, store group and two store views under it
$website = Mage::getModel('core/website')->load('brandtest', 'code');
if (!$website->getId()) {
    $website->setData(['code' => 'brandtest', 'name' => 'Brand Test Site', 'sort_order' => 9])->save();
}
$group = Mage::getModel('core/store_group')->getCollection()->addFieldToFilter('website_id', $website->getId())->getFirstItem();
if (!$group->getId()) {
    $group = Mage::getModel('core/store_group')->setData([
        'website_id' => $website->getId(), 'name' => 'Brand Test Store',
        'root_category_id' => Mage::app()->getDefaultStoreView()->getRootCategoryId(),
    ])->save();
    $website->setDefaultGroupId($group->getId())->save();
}
$views = [];
foreach (['brand_en' => 'Brand English', 'brand_fr' => 'Brand French'] as $code => $name) {
    $view = Mage::getModel('core/store')->load($code, 'code');
    if (!$view->getId()) {
        $view->setData(['code' => $code, 'website_id' => $website->getId(), 'group_id' => $group->getId(), 'name' => $name, 'is_active' => 1])->save();
    }
    $views[$code] = (int) $view->getId();
}
if (!$group->getDefaultStoreId()) { $group->setDefaultStoreId($views['brand_en'])->save(); }

// ---- different values at each scope
$config = Mage::getModel('core/config');
$p      = 'ysrtech_emailcampaigns/brand/';
$config->saveConfig($p . 'button_color', '#111111', 'default', 0);
$config->saveConfig($p . 'footer_text', 'DEFAULT SMALL PRINT', 'default', 0);
$config->saveConfig($p . 'header_links', "Home | https://default.example.invalid/", 'default', 0);
$config->saveConfig($p . 'button_color', '#aa0000', 'websites', $website->getId());
$config->saveConfig($p . 'footer_text', 'WEBSITE SMALL PRINT', 'websites', $website->getId());
$config->saveConfig($p . 'button_color', '#0000bb', 'stores', $views['brand_fr']);
$config->saveConfig($p . 'header_links', "Accueil | https://fr.example.invalid/", 'stores', $views['brand_fr']);
$config->saveConfig('general/store_information/name', 'Boutique Française', 'stores', $views['brand_fr']);
$config->saveConfig($p . 'enabled', 0, 'stores', $views['brand_en']);
Mage::app()->getCacheInstance()->flush();
Mage::app()->reinitStores();
Mage::getConfig()->reinit();

$brand = Mage::getModel('ysrtech_emailcampaigns/brand');
$default = $brand->get(null);
$en      = $brand->get($views['brand_en']);
$fr      = $brand->get($views['brand_fr']);
$main    = $brand->get((int) Mage::app()->getDefaultStoreView()->getId());

$ok('default scope has the default value', $default['button_color'] === '#111111' && $default['footer_text'] === 'DEFAULT SMALL PRINT');
$ok('a store view of the first website inherits the default', $main['button_color'] === '#111111' && $main['footer_text'] === 'DEFAULT SMALL PRINT');
$ok('a store view of the second website inherits its website', $en['button_color'] === '#aa0000' && $en['footer_text'] === 'WEBSITE SMALL PRINT');
$ok('a store view with its own value overrides its website', $fr['button_color'] === '#0000bb');
$ok('...and still inherits what it did not set', $fr['footer_text'] === 'WEBSITE SMALL PRINT');
$ok('header links are per store view', $fr['header_links'] === [['Accueil', 'https://fr.example.invalid/']] && $en['header_links'] === [['Home', 'https://default.example.invalid/']]);
$ok('the store name is the store view\'s own', $fr['store_name'] === 'Boutique Française');
$ok('switching the header and footer off for one store view leaves the others on', !$brand->isEnabled($views['brand_en']) && $brand->isEnabled($views['brand_fr']) && $brand->isEnabled(null));

// ---- an email rendered for each store view
$template = Mage::getModel('ysrtech_emailcampaigns/template')->setData(['html' => '<p>Body</p>', 'subject' => 'S', 'use_brand' => 1]);
$sender   = Mage::getSingleton('ysrtech_emailcampaigns/sender');
$htmlDefault = $sender->renderPreview($template, null);
$htmlFr      = $sender->renderPreview($template, $views['brand_fr']);
$htmlEn      = $sender->renderPreview($template, $views['brand_en']);

$ok('rendered for the French view: its header link, its name, its website\'s small print', strpos($htmlFr, 'Accueil') !== false && strpos($htmlFr, 'Boutique Française') !== false && strpos($htmlFr, 'WEBSITE SMALL PRINT') !== false && strpos($htmlFr, 'Home</a>') === false);
$ok('rendered for the default: the default\'s, and none of the French', strpos($htmlDefault, 'DEFAULT SMALL PRINT') !== false && strpos($htmlDefault, 'Accueil') === false);
$ok('rendered for the view with it off: no store header or footer, the plain frame instead', strpos($htmlEn, 'SMALL PRINT') === false && strpos($htmlEn, '<p>Body</p>') !== false);

// a starter built for a store view takes that view's colours
$starter = Mage::getSingleton('ysrtech_emailcampaigns/template_starter');
$ok('a ready-made design built for the French view uses its button colour', strpos($starter->build('sale', $views['brand_fr']), '#0000bb') !== false && strpos($starter->build('sale', null), '#111111') !== false);

// a campaign carries its store view to the send
$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData(['name' => '[test] brand scope', 'store_id' => $views['brand_fr'], 'html' => '<p>Body</p>', 'use_brand' => 1, 'subject' => 'S', 'status' => 'draft']);
$campaign->save();
$state = Mage::getSingleton('ysrtech_emailcampaigns/campaign_editor')->state(Mage::getModel('ysrtech_emailcampaigns/campaign')->load($campaign->getId()));
$ok('a campaign set to the French view previews with the French brand', strpos($state['content']['html'], 'Accueil') !== false && $state['store_name'] === 'Boutique Française');
$campaign->delete();

// ---- put the staging config back as it was
foreach ([['default', 0], ['websites', $website->getId()], ['stores', $views['brand_fr']], ['stores', $views['brand_en']]] as [$scope, $id]) {
    foreach (['button_color', 'footer_text', 'header_links', 'enabled'] as $field) {
        $config->deleteConfig($p . $field, $scope, $id);
    }
}
$config->deleteConfig('general/store_information/name', 'stores', $views['brand_fr']);
Mage::app()->getCacheInstance()->flush();

echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
