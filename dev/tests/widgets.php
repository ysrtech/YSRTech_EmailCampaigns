<?php
/**
 * Product blocks in an email, against the staging catalog.
 *
 *   cd <openmage-core> && php <module>/dev/staging/seed-catalog.php   # once
 *   cd <openmage-core> && php <module>/dev/tests/widgets.php
 */
require_once 'app/Mage.php';
Mage::app('admin');

$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$W    = YSRTech_EmailCampaigns_Model_Widget_Products::class;
$w    = Mage::getSingleton('ysrtech_emailcampaigns/widget_products');
$load = fn (array $o) => $w->load($o + $W::defaults($o['layout'] ?? 'grid'));
$cat  = (int) Mage::getResourceModel('catalog/category_collection')->addAttributeToFilter('url_key', 'seed-childrens')->getFirstItem()->getId();

if (!$cat) {
    echo "Run dev/staging/seed-catalog.php first.\n";
    exit(1);
}

$rows = $load(['source' => 'category', 'category' => 'category/' . $cat, 'limit' => 3]);
$ok('a category gives its products, newest first, up to the limit', count($rows) === 3 && $rows[0]['name'] === 'Good Night, Rebbe');
$ok('each has a storefront address and a resized image', strpos($rows[0]['url'], 'seed-6-good-night-rebbe') !== false && strpos($rows[0]['image'], '/cache/') !== false);

$rows = $load(['source' => 'skus', 'skus' => "SEED-012, SEED-003\nSEED-999,SEED-009"]);
$ok('chosen SKUs come back in the order typed, unknown ones skipped', array_column($rows, 'name') === ['Daily Dose', 'Cobra', 'אור אביגדור - חובת הלבבות - שער הבטחון']);
$ok('a Hebrew title survives', mb_strlen($rows[2]['name']) > 10 && preg_match('/\p{Hebrew}/u', $rows[2]['name']) === 1);

$rows = $load(['layout' => 'deals', 'source' => 'sale', 'limit' => 24]);
$ok('"on sale" is only what is really marked down', count($rows) === 5 && !array_filter($rows, fn ($r) => !$r['on_sale']));
$ok('prices come from the catalog, formatted for the store', $rows[0]['price_html'] === '$29.99' && $rows[0]['final_html'] === '$19.99');

$html = '<p>top</p>'
    . '<div data-ec-widget="products" data-layout="deals" data-source="sale" data-heading="This Week&#39;s Deals"></div>'
    . '<div data-ec-widget="products" data-layout="feature" data-skus="SEED-012" data-label="Staff Pick" data-blurb="A few &lt;words&gt;."></div>'
    . '<div data-ec-widget="products" data-source="category" data-category="999999" data-heading="Nothing here"></div>'
    . '<p>bottom</p>';
$out = $w->process($html);
$ok('every block is replaced', strpos($out, 'data-ec-widget') === false && strpos($out, '<p>top</p>') === 0 && substr($out, -13) === '<p>bottom</p>');
$ok('a block with no products leaves nothing, heading included', strpos($out, 'Nothing here') === false);
$ok('option text is escaped on the way out', strpos($out, 'A few &lt;words&gt;.') !== false && strpos($out, '<words>') === false);
$ok('the deals row crosses out the old price', substr_count($out, 'line-through') >= 3);
$ok('the first names are kept for the preview text', $w->getFirstNames() === 'Daily Dose, Eight People We Met On the Way Home, The Quiet Hour');
$ok('"hide below" hides', $w->render(['layout' => 'deals', 'source' => 'sale', 'min_products' => 9]) === '');

$template = Mage::getModel('ysrtech_emailcampaigns/template')->setData([
    'html' => '<p>{{ date.hebrew }} | {{ date.long }}</p>' . $html, 'preheader' => 'New: {{ products.names }} and more.', 'use_brand' => 0,
]);
$email = Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template);
$ok('the email carries the phone rules for the blocks', strpos($email, '.ecw-col') !== false);
$ok('the dateline has today in both calendars', preg_match('#<p>[A-Z][a-z]+ \d{1,2} 57\d\d \| [A-Z][a-z]{2} \d{1,2}, 20\d\d</p>#', $email) === 1);
$ok('the preview text names the first products', strpos($email, 'New: Daily Dose, Eight People We Met On the Way Home, The Quiet Hour and more.') !== false);

echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
