<?php
/**
 * The builder's document: cleaning what comes back from the browser,
 * rendering it to the email, filling it in for a reader, the starters, and
 * emails from before the builder.
 *
 *   cd <openmage-core> && php <module>/dev/tests/builder.php
 */
require_once 'app/Mage.php';
Mage::app('admin');
Mage::getSingleton('customer/session'); // a product block asks it for the customer group; start it before any output

$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$throws = function (callable $work): string { try { $work(); } catch (Mage_Core_Exception $e) { return $e->getMessage(); } return ''; };

/** @var YSRTech_EmailCampaigns_Model_Design_Doc $docs */
$docs   = Mage::getModel('ysrtech_emailcampaigns/design_doc');
/** @var YSRTech_EmailCampaigns_Model_Design_Html $html */
$html   = Mage::getSingleton('ysrtech_emailcampaigns/design_html');
$sender = Mage::getSingleton('ysrtech_emailcampaigns/sender');
$R      = YSRTech_EmailCampaigns_Model_Renderer::class;
$H      = YSRTech_EmailCampaigns_Model_Design_Html::class;
$blocks = static fn (array $doc) => array_merge(...array_merge(...array_map(static fn ($r) => $r['cells'], $doc['rows'])));

// ------------------------------------------------------------- cleaning
$dirty = [
    'settings' => ['width' => 5000, 'bodyBg' => 'red;background:url(x)', 'btn' => '#ABCDEF', 'font' => 'Georgia, serif', 'radius' => '12', 'evil' => 'x'],
    'rows'     => [
        ['id' => 'r1', 'layout' => [1, 2], 'pad' => [999, -5], 'bg' => 'javascript:x', 'stack' => false, 'hide' => 'tablet', 'cells' => [
            [
                ['id' => 'b1', 'type' => 'heading', 'props' => ['html' => 'Hi <b>there</b><script>alert(1)</script> <a href="javascript:alert(2)" onclick="x()">link</a>', 'size' => 900, 'align' => 'justify', 'weight' => 950, 'color' => '#123']],
                ['type' => 'nonsense', 'props' => ['x' => 1]],
                'not a block',
            ],
            [
                ['id' => 'b2', 'type' => 'button', 'props' => ['label' => 'Go', 'href' => 'javascript:alert(3)', 'radius' => '', 'full' => 1, 'pad' => [1, 2, 3]]],
                ['id' => 'b3', 'type' => 'image', 'props' => ['src' => 'data:text/html,x', 'href' => '{{ cart.url }}', 'width' => 0, 'alt' => str_repeat('a', 900)]],
                ['id' => 'b4', 'type' => 'social', 'props' => ['nets' => ['facebook', 'myspace'], 'urls' => ['facebook' => 'https://fb.example/shop', 'myspace' => 'x']]],
                ['id' => 'b5', 'type' => 'products', 'props' => ['options' => ['source' => 'sale', 'limit' => 3, 'bogus' => 'x', 'heading' => ['array']]]],
            ],
            [['id' => 'b6', 'type' => 'text', 'props' => ['html' => 'in a third cell that the layout does not have']]],
        ]],
        'not a row',
        ['layout' => [7, 7, 7], 'cells' => [[['type' => 'storefooter']]]],
    ],
];

$clean = $docs->clean($dirty);
$b     = array_column($blocks($clean), null, 'id');

$ok('settings: numbers clamped, colours checked, unknown keys dropped', $clean['settings']['width'] === 700 && $clean['settings']['btn'] === '#ABCDEF'
    && preg_match('/^#[0-9a-f]{3,6}$/i', $clean['settings']['bodyBg']) && $clean['settings']['radius'] === 12 && !isset($clean['settings']['evil']));
$ok('rows: junk dropped, layout checked, extra cells dropped', count($clean['rows']) === 2 && $clean['rows'][0]['layout'] === [1, 2] && count($clean['rows'][0]['cells']) === 2 && $clean['rows'][1]['layout'] === [1]);
$ok('row: padding clamped, bad colour and bad device dropped', $clean['rows'][0]['pad'] === [120, 0] && $clean['rows'][0]['bg'] === '' && $clean['rows'][0]['hide'] === '' && $clean['rows'][0]['stack'] === false);
$ok('unknown blocks dropped', count($clean['rows'][0]['cells'][0]) === 1 && !isset($b['b6']));
$ok('heading: script, handler and script link removed, formatting kept', strpos($b['b1']['props']['html'], '<b>there</b>') !== false && stripos($b['b1']['props']['html'], 'script') === false
    && stripos($b['b1']['props']['html'], 'onclick') === false && strpos($b['b1']['props']['html'], '>link</a>') !== false);
$ok('heading: size, weight and alignment put right', $b['b1']['props']['size'] === 60 && $b['b1']['props']['weight'] === 700 && $b['b1']['props']['align'] === 'left' && $b['b1']['props']['color'] === '#123');
$ok('button: script link refused, empty radius means "the email\'s own", padding completed', $b['b2']['props']['href'] === '' && $b['b2']['props']['radius'] === null && $b['b2']['props']['full'] === true && $b['b2']['props']['pad'] === [1, 2, 3, 0]);
$ok('image: data address refused, placeholder link kept, width and alt bounded', $b['b3']['props']['src'] === '' && $b['b3']['props']['href'] === '{{ cart.url }}' && $b['b3']['props']['width'] === 5 && mb_strlen($b['b3']['props']['alt']) === 300);
$ok('social: known networks only', $b['b4']['props']['nets'] === ['facebook'] && $b['b4']['props']['urls'] === ['facebook' => 'https://fb.example/shop']);
$ok('products: its own options only, all text', $b['b5']['props']['options']['source'] === 'sale' && $b['b5']['props']['options']['limit'] === '3' && !isset($b['b5']['props']['options']['bogus']) && is_string($b['b5']['props']['options']['heading']));
$ok('not a design at all is refused', $throws(fn () => $docs->clean('nope')) !== '' && $throws(fn () => $docs->clean(['rows' => 'x'])) !== '');
$ok('cleaning twice changes nothing', $docs->clean($clean) == $clean);

// ------------------------------------------------------------ rendering
$doc = $docs->clean([
    'settings' => ['width' => 600, 'btn' => '#112233', 'btnText' => '#FFEEDD', 'link' => '#445566'],
    'rows'     => [
        ['layout' => [1], 'cells' => [[['type' => 'storeheader']]], 'pad' => [0, 0]],
        ['layout' => [1, 1], 'hide' => 'mobile', 'cells' => [
            [['type' => 'heading', 'props' => ['html' => 'Hi {{ customer.firstname|there }} &amp; all', 'size' => 30, 'align' => 'center']]],
            [['type' => 'button', 'props' => ['label' => 'Shop <now>', 'href' => 'https://shop.example/?a=1&b=2']]],
        ]],
        ['layout' => [1], 'cells' => [[
            ['type' => 'image', 'props' => ['src' => 'https://shop.example/a.jpg', 'alt' => 'A "book"', 'width' => 50]],
            ['type' => 'coupon', 'props' => ['code' => 'SAVE<10>', 'note' => 'Until Sunday']],
            ['type' => 'menu', 'props' => ['items' => 'Books | https://shop.example/books, Sale']],
            ['type' => 'products', 'props' => ['options' => ['source' => 'new', 'heading' => 'New "in"', 'limit' => 2]]],
            ['type' => 'html', 'props' => ['code' => '<p class="own">Mine</p>']],
            ['type' => 'footer', 'props' => ['company' => 'Shop & Co', 'address' => '1 Main St', 'note' => 'You subscribed.']],
        ]]],
    ],
]);

$out = $html->render($doc, 'Subject <1>');
$ok('a whole document, with the subject as its title', strpos($out, '<!doctype html>') === 0 && strpos($out, '<title>Subject &lt;1&gt;</title>') !== false);
$ok('two columns as table cells that stack on a phone', substr_count($out, '<td class="col" width="300"') === 2 && strpos($out, '.col{display:block!important') !== false);
$ok('a row hidden on phones carries the class', strpos($out, '<tr class="hide-mobile">') !== false);
$ok('typed words kept as typed, placeholder whole', strpos($out, 'Hi {{ customer.firstname|there }} &amp; all</h2>') !== false);
$ok('button: escaped label and link, the email\'s colours, a VML twin for Outlook', strpos($out, '>Shop &lt;now&gt;</a>') !== false && strpos($out, 'href="https://shop.example/?a=1&amp;b=2"') !== false
    && strpos($out, 'background:#112233;color:#FFEEDD') !== false && strpos($out, '<v:roundrect') !== false);
$ok('image: width in pixels for Outlook, escaped alt', strpos($out, 'alt="A &quot;book&quot;" width="272"') !== false);
$ok('coupon escaped', strpos($out, 'SAVE&lt;10&gt;') !== false);
$ok('menu: label and address, a bare label goes nowhere', strpos($out, '<a href="https://shop.example/books"') !== false && strpos($out, '<a href="#"') !== false);
$ok('products: the block the catalog fills in later', (bool) preg_match('#<div data-ec-widget="products"[^>]*data-source="new"[^>]*data-heading="New &quot;in&quot;"[^>]*data-limit="2"[^>]*></div>#', $out));
$ok('custom html as written', strpos($out, '<p class="own">Mine</p>') !== false);
$ok('footer: company, address and the reader\'s unsubscribe link', strpos($out, 'Shop &amp; Co') !== false && strpos($out, '<a href="{{ unsubscribe_url }}"') !== false);
$ok('the store header stands as a marker', strpos($out, $H::STORE_HEADER) !== false);

// -------------------------------------------------- filled in for a reader
$template = Mage::getModel('ysrtech_emailcampaigns/template')->setData(['subject' => 'S', 'preheader' => 'Preview for {{ customer.firstname|you }}'])->setDoc($doc);
$ok('a template takes a design: json, html and the builder\'s frame', $template->getDoc() == $doc && (int) $template->getUseBrand() === $R::FRAME_DOC && strpos($template->getHtml(), '<!doctype html>') === 0);

$sent = $sender->renderPreview($template);
$ok('one document, not one inside another', substr_count($sent, '<html') === 1 && substr_count($sent, '<body') === 1);
$ok('names filled in', strpos($sent, 'Hi Chaya &amp; all') !== false);
$ok('preview text first in the body', (bool) preg_match('#<body[^>]*><div style="display:none[^>]*>Preview for Chaya#', $sent));
$ok('the store\'s header where its block stood', strpos($sent, $H::STORE_HEADER) === false && strpos($sent, 'Staging Store') !== false);
$ok('product block replaced, with its phone rules in the head', strpos($sent, 'data-ec-widget') === false && (bool) preg_match('#<style>[^<]*\.ecw-#', $sent));
$ok('unsubscribe link filled in', strpos($sent, '{{ unsubscribe_url }}') === false);

$checks = array_column(Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($template, 'S'), null, 'code');
$ok('checks: a design with a footer passes', $checks['footer']['level'] !== 'bad');

$bare   = Mage::getModel('ysrtech_emailcampaigns/template')->setDoc(['rows' => [['layout' => [1], 'cells' => [[['type' => 'text', 'props' => ['html' => 'Just words, and nothing to unsubscribe with.']]]]]]]);
$checks = array_column(Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($bare, 'S'), null, 'code');
$ok('checks: a design with no way to unsubscribe cannot be sent', $checks['footer']['level'] === 'bad' && strpos($checks['footer']['message'], 'Footer block') !== false);

// A campaign's own copy
$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData(['name' => 'x', 'subject' => 'S', 'format' => 'letter', 'store_id' => 0])->setDoc($doc);
$ok('a campaign takes a design the same way', $campaign->getDoc() == $doc && $campaign->hasOwnContent() && !$campaign->isLetter()
    && (int) $campaign->getContentTemplate()->getUseBrand() === $R::FRAME_DOC && $campaign->getContentTemplate()->getDoc() == $doc);

// ------------------------------------------------------------- starters
/** @var YSRTech_EmailCampaigns_Model_Template_Starter $starter */
$starter = Mage::getSingleton('ysrtech_emailcampaigns/template_starter');

foreach (['plain', 'new_arrivals', 'sale', 'holiday', 'welcome', 'abandoned_cart', 'order_followup', 'winback'] as $code) {
    $made = $starter->template($code);
    $type = array_column($blocks($made->getDoc()), 'type');
    $ok("starter {$code}: a builder design with a heading and a way out", $made->getDoc() !== null && in_array('heading', $type, true)
        && (in_array('storefooter', $type, true) || in_array('footer', $type, true)) && strlen($sender->renderPreview($made)) > 1500);
}

$cart = $starter->template('abandoned_cart');
$ok('a starter\'s button can go to a placeholder', strpos($cart->getHtml(), 'href="{{ cart.url }}"') !== false);

// ----------------------------------------- emails from before the builder
$old = Mage::getModel('ysrtech_emailcampaigns/template')->setData([
    'name' => 'old', 'subject' => 'Old', 'use_brand' => $R::FRAME_BRAND,
    'html' => '<html><head><style>.x{color:red}</style></head><body><table width="100%"><tr><td>Old words {{ customer.firstname|there }}</td></tr></table>{{unsubscribe}}</body></html>',
]);
$before = $sender->renderPreview($old);
$asDoc  = $old->getDocForBuilder();
$types  = array_column($blocks($asDoc), 'type');
$ok('branded: becomes store header, its html, store footer', $types === ['storeheader', 'html', 'storefooter']);
$ok('branded: only the body goes inside, the old footer token dropped', strpos($asDoc['rows'][1]['cells'][0][0]['props']['code'], '<html') === false && strpos($asDoc['rows'][1]['cells'][0][0]['props']['code'], '{{unsubscribe}}') === false);
$ok('opening it changes nothing stored', (int) $old->getUseBrand() === $R::FRAME_BRAND && $old->getDoc() === null);

$after = $sender->renderPreview((clone $old)->setDoc($asDoc));
$ok('branded: once saved from the builder it still has its words, header, footer and one unsubscribe link', strpos($after, 'Old words Chaya') !== false
    && strpos($after, 'Staging Store') !== false && substr_count($after, 'Unsubscribe') === substr_count($before, 'Unsubscribe'));

$own = Mage::getModel('ysrtech_emailcampaigns/template')->setData([
    'name' => 'own', 'subject' => 'Own', 'use_brand' => $R::FRAME_NONE,
    'html' => '<table class="wrap" width="600" data-ec-bg="#101010"><tr><td class="px">A design of its own {{ customer.firstname|there }} <div data-ec-widget="products" data-layout="grid" data-source="new" data-limit="2"></div><a href="{{ unsubscribe_url }}">out</a></td></tr></table>',
]);
$before = $sender->renderPreview($own);
$asDoc  = $own->getDocForBuilder();
$code   = $asDoc['rows'][0]['cells'][0][0]['props']['code'];
$ok('own design: one block holding the whole document, placeholders and product blocks still in it', count($blocks($asDoc)) === 1 && $H::fullDocument($asDoc) === $code
    && strpos($code, '{{ customer.firstname|there }}') !== false && strpos($code, 'data-ec-widget="products"') !== false && strpos($code, '.wrap{width:100% !important;}') !== false);

$after = $sender->renderPreview((clone $own)->setDoc($asDoc));
$strip = static fn (string $h) => preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<(style|head)\b.*?</\1>#is', '', $h)));
$ok('own design: sends the same words after being saved from the builder', $strip($after) === $strip($before) && strpos($after, 'background-color:#101010') !== false);

echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
