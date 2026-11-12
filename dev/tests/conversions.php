<?php
// Conversion tracking: tagging links, crediting orders, reading the totals. Makes its own rows and removes them.
// Run from the store's root: php path/to/dev/tests/conversions.php clean
require 'app/Mage.php';
Mage::app('admin');
Mage::setIsDeveloperMode(true);

$res = Mage::getSingleton('core/resource');
$db  = $res->getConnection('core_write');
$t   = fn ($e) => $res->getTableName($e);
$fails = 0;
$ok = function ($name, $cond, $extra = '') use (&$fails) { if (!$cond) { $fails++; } echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($extra !== '' ? ' ' . $extra : '') . "\n"; };

/** @var YSRTech_EmailCampaigns_Model_Conversions $c */
$c     = Mage::getSingleton('ysrtech_emailcampaigns/conversions');
$store = Mage::app()->getDefaultStoreView();
$base  = rtrim($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK), '/');
$host  = parse_url($base, PHP_URL_HOST);
$mark  = 'xconv';

$cleanup = function () use ($db, $t, $mark) {
    $ids = $db->fetchCol("SELECT entity_id FROM {$t('sales/order')} WHERE customer_email LIKE '{$mark}%'");
    if ($ids) {
        $db->delete($t('ysrtech_emailcampaigns/conversion'), ['order_id IN (?)' => $ids]);
        $db->delete($t('sales/order_grid'), ['entity_id IN (?)' => $ids]);
        $db->delete($t('sales/order'), ['entity_id IN (?)' => $ids]);
    }
    $db->delete($t('ysrtech_emailcampaigns/conversion'), "email LIKE '{$mark}%'");
    $db->query("DELETE l FROM {$t('ysrtech_emailcampaigns/link_click')} l JOIN {$t('ysrtech_emailcampaigns/queue')} q ON q.queue_id = l.queue_id WHERE q.email LIKE '{$mark}%'");
    $db->delete($t('ysrtech_emailcampaigns/queue'), "email LIKE '{$mark}%'");
    $db->delete($t('ysrtech_emailcampaigns/campaign'), "name LIKE '{$mark}%'");
};
$cleanup();

/* ------------------------------------------------------------ tagging */
$html = '<p><a href="' . $base . '/books.html">Books</a> <a class="b" href=\'' . $base . '/sale?color=red&amp;size=2#top\'>Sale</a>'
    . ' <a href="https://elsewhere.example/x">Other</a> <a href="mailto:a@b.c">Mail</a> <a href="' . $base . '/x?utm_campaign=mine">Mine</a>'
    . ' <a href="' . $base . '/emailcampaigns/preferences/unsubscribe/t/abc">Unsubscribe</a> <a href="#top">Top</a> <a href="%unsubscribe_url%">U</a></p>';
$out = $c->tagLinks($html, 'TOKEN1234567890abcdef', 'Spring Sale! 20% off', $store->getId());
$ok('a link to the store gets the token and the utm labels', strpos($out, 'href="' . $base . '/books.html?ec=TOKEN1234567890abcdef&amp;utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=spring-sale-20-off"') !== false, $out);
$ok('a link with its own query and an anchor keeps both, and stays valid HTML', strpos($out, "href='" . $base . '/sale?color=red&amp;size=2&amp;ec=TOKEN1234567890abcdef&amp;utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=spring-sale-20-off#top\'') !== false);
$ok('links to other sites, mailto, anchors and the provider\'s own tokens are left alone', strpos($out, 'href="https://elsewhere.example/x"') !== false && strpos($out, 'href="mailto:a@b.c"') !== false && strpos($out, 'href="#top"') !== false && strpos($out, 'href="%unsubscribe_url%"') !== false);
$ok('a utm label put on a link by hand is kept', strpos($out, '/x?utm_campaign=mine&amp;ec=TOKEN1234567890abcdef&amp;utm_source=newsletter&amp;utm_medium=email"') !== false);
$ok('the unsubscribe page is not tagged', strpos($out, 'href="' . $base . '/emailcampaigns/preferences/unsubscribe/t/abc"') !== false);
$shared = $c->tagLinks('<a href="' . $base . '/a">A</a>', '%recipient.tracking_token%', 'N', $store->getId());
$ok('a body shared by many people carries the provider\'s placeholder, unescaped', strpos($shared, '?ec=%recipient.tracking_token%&amp;utm_source') !== false, $shared);
$store->setConfig('ysrtech_emailcampaigns/tracking/utm', 0);
$ok('with the Google Analytics labels off, only the token is added', $c->tagLinks('<a href="' . $base . '/a">A</a>', 'TOK12345678901234567', 'N', $store->getId()) === '<a href="' . $base . '/a?ec=TOK12345678901234567">A</a>');
$store->setConfig('ysrtech_emailcampaigns/tracking/conversions', 0);
$ok('with tracking off, the email is untouched', $c->tagLinks($html, 'TOKEN1234567890abcdef', 'N', $store->getId()) === $html);
$store->setConfig('ysrtech_emailcampaigns/tracking/conversions', 1);
$store->setConfig('ysrtech_emailcampaigns/tracking/utm', 1);

/* ----------------------------------------------- what goes out in a send */
$sender = Mage::getSingleton('ysrtech_emailcampaigns/sender');

/* ------------------------------------------------------------ fixtures */
$db->insert($t('ysrtech_emailcampaigns/campaign'), ['name' => "{$mark} campaign", 'status' => 'sent', 'store_id' => 0, 'subject' => 'S']);
$campaignId = (int) $db->lastInsertId();
$db->insert($t('ysrtech_emailcampaigns/campaign'), ['name' => "{$mark} second", 'status' => 'sent', 'store_id' => 0, 'subject' => 'S2']);
$second = (int) $db->lastInsertId();
$n = 0;
$queue = function (string $who, ?string $clicked, array $o = []) use ($db, $t, $mark, $campaignId, &$n) {
    $n++;
    $token = substr(str_repeat('T' . $n . 'abcdefghij', 4), 0, 32);
    $db->insert($t('ysrtech_emailcampaigns/queue'), $o + [
        'campaign_id' => $campaignId, 'email' => "{$mark}-{$who}@sync-test.invalid", 'status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s', time() - 6 * 86400),
        'tracking_token' => $token, 'clicked_at' => $clicked, 'click_count' => $clicked ? 1 : 0,
    ]);
    return ['id' => (int) $db->lastInsertId(), 'token' => $token];
};
$m = 0;
$order = function (string $who, array $o = []) use (&$m, $store, $mark) {
    $m++;
    $order = Mage::getModel('sales/order')->setData($o + [
        'store_id' => $store->getId(), 'increment_id' => 'XC' . time() . $m, 'customer_is_guest' => 1,
        'customer_email' => "{$mark}-{$who}@sync-test.invalid", 'state' => 'new', 'status' => 'pending',
        'base_subtotal' => 100, 'base_discount_amount' => -20, 'base_shipping_amount' => 9, 'base_tax_amount' => 7, 'base_grand_total' => 96,
        'base_currency_code' => 'USD', 'order_currency_code' => 'USD',
    ]);
    $order->save();   // sales_order_save_commit_after fires here: this is the real path
    return $order;
};
$conv = fn ($order) => $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/conversion')} WHERE order_id = ?", [(int) $order->getId()]);
$ago  = fn (float $days) => gmdate('Y-m-d H:i:s', time() - (int) ($days * 86400));

/* ------------------------------------------------- credit: by the link */
$qa = $queue('a', null);
$_COOKIE[YSRTech_EmailCampaigns_Model_Conversions::COOKIE] = $qa['token'];
$oa  = $order('somebody-else');   // the cookie decides, not the address on the order
$row = $conv($oa);
$ok('an order placed in the browser that clicked is credited to that email', $row && (int) $row['queue_id'] === $qa['id'] && $row['via'] === 'link' && (int) $row['campaign_id'] === $campaignId, json_encode($row));
$ok('revenue is the goods less the discount: no shipping, no tax', $row && (float) $row['revenue'] === 80.0 && $row['currency'] === 'USD', (string) ($row['revenue'] ?? ''));
$oa->setStatus('processing')->save();
$ok('saving the order again credits nothing twice', (int) $db->fetchOne("SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/conversion')} WHERE order_id = ?", [(int) $oa->getId()]) === 1);
unset($_COOKIE[YSRTech_EmailCampaigns_Model_Conversions::COOKIE]);

/* ---------------------------------------------- credit: by the address */
$qb1 = $queue('b', $ago(4));
$qb2 = $queue('b', $ago(1), ['campaign_id' => $second]);
$ob  = $order('b');
$row = $conv($ob);
$ok('with no cookie, the order goes to the last email that address clicked', $row && (int) $row['queue_id'] === $qb2['id'] && $row['via'] === 'address', json_encode($row));

$queue('c', $ago(6));
$ok('a click older than the window earns nothing', $conv($order('c')) === false);
$queue('d', null, ['opened_at' => $ago(1), 'open_count' => 3]);
$ok('an open with no click earns nothing', $conv($order('d')) === false);
$ok('somebody who was never emailed is credited to nothing', $conv($order('nobody')) === false);
$queue('e', $ago(1));
$old = $order('e', ['created_at' => $ago(2)]);
$ok('an old order being saved again is not looked at', $conv($old) === false);
$store->setConfig('ysrtech_emailcampaigns/tracking/conversions', 0);
$queue('f', $ago(1));
$ok('with tracking off nothing is credited', $conv($order('f')) === false);
$store->setConfig('ysrtech_emailcampaigns/tracking/conversions', 1);
$_COOKIE[YSRTech_EmailCampaigns_Model_Conversions::COOKIE] = 'not-a-real-token-at-all-000000000';
$queue('g', $ago(1));
$row = $conv($order('g'));
$ok('a cookie that is nobody\'s token falls back to the address', $row && $row['via'] === 'address');
unset($_COOKIE[YSRTech_EmailCampaigns_Model_Conversions::COOKIE]);

/* -------------------------------------------------------------- totals */
$tot = $c->forCampaign($campaignId);
$ok('the campaign: two orders, one by link and one by address, $160', $tot['orders'] === 2 && $tot['by_link'] === 1 && $tot['by_address'] === 1 && $tot['revenue'] === 160.0 && $tot['currency'] === 'USD', json_encode($tot));
$db->update($t('sales/order'), ['base_subtotal_refunded' => 50, 'base_discount_refunded' => 10], ['entity_id = ?' => (int) $ob->getId()]);
$ok('a refund comes off the revenue', $c->forCampaign($second)['revenue'] === 40.0, (string) $c->forCampaign($second)['revenue']);
$db->update($t('sales/order'), ['state' => 'canceled'], ['entity_id = ?' => (int) $oa->getId()]);
$tot = $c->forCampaign($campaignId);
$ok('a cancelled order stops counting', $tot['orders'] === 1 && $tot['revenue'] === 80.0, json_encode($tot));
$db->delete($t('ysrtech_emailcampaigns/queue'), ['queue_id = ?' => $qb2['id']]);
$ok('the nightly sweep removing the queue row does not lose the sale', $c->forCampaign($second)['orders'] === 1);
$mine = $c->forEmail("{$mark}-b@sync-test.invalid");
$ok('one address\'s credited orders, by message', $mine['totals']['orders'] === 1 && isset($mine['by_queue'][$qb2['id']]) && $mine['by_queue'][$qb2['id']]['revenue'] === 40.0, json_encode($mine));
$ok('everything credited in the last day', $c->since($ago(1))['orders'] >= 2 && $c->since($ago(1), null, 'automation')['orders'] === 0);

/* ------------------------------------------------------- links clicked */
$proc  = Mage::getModel('ysrtech_emailcampaigns/webhook_processor');
$click = function (array $q, string $who, string $url) use ($proc, $mark) {
    $proc->apply('mailgun', json_encode(['event-data' => ['event' => 'clicked', 'recipient' => "{$mark}-{$who}@sync-test.invalid", 'timestamp' => time(), 'url' => $url,
        'user-variables' => ['tracking_token' => $q['token']]]]), gmdate('Y-m-d H:i:s'));
};
$l1 = $queue('l1', null); $l2 = $queue('l2', null); $l3 = $queue('l3', null);
$sale = 'https://shop.example/sale.html';
$click($l1, 'l1', $sale . '?ec=' . $l1['token'] . '&utm_source=newsletter&utm_medium=email&utm_campaign=x');
$click($l1, 'l1', $sale . '?ec=' . $l1['token'] . '&utm_source=newsletter&utm_medium=email&utm_campaign=x');   // the same person, again
$click($l2, 'l2', $sale . '?utm_campaign=x&ec=' . $l2['token'] . '#top');
$click($l2, 'l2', 'https://shop.example/books.html?color=red&ec=' . $l2['token']);
$click($l3, 'l3', 'javascript:alert(1)');
$links = $c->linksForCampaign($campaignId);
$ok('links are listed by how many people clicked each, most first', count($links) === 2 && $links[0] === ['url' => $sale, 'people' => 2, 'clicks' => 3] && $links[1] === ['url' => 'https://shop.example/books.html?color=red', 'people' => 1, 'clicks' => 1], json_encode($links));
$ok('the link is shown as it was written: no token, no utm labels, its own query kept', $c->linkAsWritten('https://a.example/p?x=1&ec=abc&utm_source=n&y=2#frag') === 'https://a.example/p?x=1&y=2');
$ok('something that is not a web address is not recorded as a link', $c->linkAsWritten('javascript:alert(1)') === '' && $c->linkAsWritten('mailto:a@b.c') === '');
$ok('the click itself is still counted on the message', (int) $db->fetchOne("SELECT click_count FROM {$t('ysrtech_emailcampaigns/queue')} WHERE queue_id = ?", [$l1['id']]) === 2);
$db->delete($t('ysrtech_emailcampaigns/link_click'), ['campaign_id = ?' => $campaignId]);

echo 'TOKEN ' . $queue('http', null)['token'] . ' ' . $base . "\n";
if (($argv[1] ?? '') === 'clean') { $cleanup(); echo "cleaned\n"; }
echo $fails ? "{$fails} FAILED\n" : "all passed\n";
