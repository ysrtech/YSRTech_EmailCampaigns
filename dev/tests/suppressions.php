<?php
/**
 * The suppression list, the Mailgun sync that feeds it, and the webhook.
 *
 *   cd <openmage-core> && php <module>/dev/tests/suppressions.php
 *
 * Mailgun is stood in for; nothing here leaves the machine. The webhook is
 * called over HTTP on the staging server, signed with a key set for the
 * length of the test and removed after.
 */
require_once 'app/Mage.php';
Mage::app('admin');

class Test_Suppressions extends YSRTech_EmailCampaigns_Model_Suppressions
{
    public $pages = [];
    protected function _getKey(): string { return 'k'; }
    protected function _getDomain(): string { return 'mg.sync-test.invalid'; }
    protected function _get(string $url): array { return $this->pages[$url] ?? ['items' => []]; }
}

$res  = Mage::getSingleton('core/resource');
$db   = $res->getConnection('core_write');
$t    = fn ($n) => $res->getTableName($n);
$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$like = "'%@sync-test.invalid'";
$clean = function () use ($db, $t, $like) {
    $db->delete($t('ysrtech_emailcampaigns/queue'), "email LIKE {$like}");
    $db->delete($t('newsletter/subscriber'), "subscriber_email LIKE {$like}");
    $db->delete($t('ysrtech_emailcampaigns/suppression'), "email LIKE {$like}");
};
$clean();

foreach (['a', 'b', 'c', 'd'] as $who) {
    $db->insert($t('newsletter/subscriber'), ['store_id' => 1, 'subscriber_email' => "{$who}@sync-test.invalid", 'subscriber_status' => 1]);
}

$campaignId = (int) $db->fetchOne("SELECT MIN(campaign_id) FROM {$t('ysrtech_emailcampaigns/campaign')}");
$db->insert($t('ysrtech_emailcampaigns/queue'), ['campaign_id' => $campaignId, 'email' => 'a@sync-test.invalid', 'status' => 'pending', 'tracking_token' => 'synctesttokenpending0000000000001']);
$db->insert($t('ysrtech_emailcampaigns/queue'), ['campaign_id' => $campaignId, 'email' => 'd@sync-test.invalid', 'status' => 'sent', 'sent_at' => Varien_Date::now(), 'tracking_token' => 'synctesttokensent000000000000002']);

/** @var YSRTech_EmailCampaigns_Model_Resource_Suppression $store */
$store  = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression');
$status = fn ($e) => (int) $db->fetchOne("SELECT subscriber_status FROM {$t('newsletter/subscriber')} WHERE subscriber_email = ?", [$e]);

// ---- the store
$ok('add is new the first time', $store->add('A@sync-test.invalid ', 'manual', 'by hand', 'admin') === true);
$ok('and not the second', $store->add('a@sync-test.invalid', 'manual') === false);
$ok('newsletter status turned', $status('a@sync-test.invalid') === 3);
$ok('pending mail cancelled', $db->fetchOne("SELECT status FROM {$t('ysrtech_emailcampaigns/queue')} WHERE tracking_token = 'synctesttokenpending0000000000001'") === 'cancelled');
$store->add('a@sync-test.invalid', 'complained');
$ok('a stronger reason replaces a weaker one', $store->reasonOf('a@sync-test.invalid') === 'complaint');
$store->add('a@sync-test.invalid', 'unsubscribed');
$ok('a weaker one does not', $store->reasonOf('a@sync-test.invalid') === 'complaint');

// ---- the Mailgun sync
$sync = new Test_Suppressions();
$base = 'mg.sync-test.invalid/';
$sync->pages = [
    $base . 'bounces?limit=1000' => ['items' => [['address' => 'B@sync-test.invalid', 'code' => '550', 'error' => 'No such user']],
        'paging' => ['next' => 'https://api.mailgun.net/v3/' . $base . 'bounces?page=next']],
    'https://api.mailgun.net/v3/' . $base . 'bounces?page=next' => ['items' => []],
    $base . 'unsubscribes?limit=1000' => ['items' => [['address' => 'c@sync-test.invalid']]],
    $base . 'complaints?limit=1000' => ['items' => []],
];
Mage::app()->getStore()->setConfig('ysrtech_emailcampaigns/sending/transport', 'mailgun');
$counts = $sync->sync();
Mage::app()->getStore()->setConfig('ysrtech_emailcampaigns/sending/transport', 'smtp');
$row = $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/suppression')} WHERE email = 'b@sync-test.invalid'");
$ok('sync adds a bounce with its detail', ($counts['bounces'] ?? 0) === 1 && $row && $row['reason'] === 'hard_bounce' && $row['detail'] === '550 No such user' && $row['source'] === 'mailgun_sync');
$ok('sync adds an unsubscribe', $store->reasonOf('c@sync-test.invalid') === 'unsubscribe' && $status('c@sync-test.invalid') === 3);
$again = $sync->sync();
$ok('a second sync adds nothing', array_sum($again) === 0);

// ---- removal
$ids = $db->fetchPairs("SELECT email, suppression_id FROM {$t('ysrtech_emailcampaigns/suppression')} WHERE email LIKE {$like}");
$store->removeByIds([$ids['b@sync-test.invalid'], $ids['c@sync-test.invalid']]);
$ok('lifting a bounce resubscribes', $status('b@sync-test.invalid') === 1 && $store->reasonOf('b@sync-test.invalid') === null);
$ok('lifting an unsubscribe does not', $status('c@sync-test.invalid') === 3);

// ---- a suppressed address is left out of a campaign and an automation
$segment = (int) $db->fetchOne("SELECT MIN(segment_id) FROM {$t('ysrtech_emailcampaigns/segment')}");
$subId   = (int) $db->fetchOne("SELECT subscriber_id FROM {$t('newsletter/subscriber')} WHERE subscriber_email = 'a@sync-test.invalid'");
$db->update($t('newsletter/subscriber'), ['subscriber_status' => 1], ['subscriber_id = ?' => $subId]);
$db->insertOnDuplicate($t('ysrtech_emailcampaigns/segment_subscriber'), ['segment_id' => $segment, 'subscriber_id' => $subId]);
$emails = $db->fetchCol(YSRTech_EmailCampaigns_Model_Campaign::recipientSelect([$segment], [], ['subscriber_email']));
$ok('not a campaign recipient even if the newsletter flag says subscribed', !in_array('a@sync-test.invalid', $emails, true));
$db->delete($t('ysrtech_emailcampaigns/segment_subscriber'), ['subscriber_id = ?' => $subId]);

// ---- the webhook, over HTTP
$config = Mage::getModel('core/config');
$key    = 'test-signing-key-' . bin2hex(random_bytes(6));
$config->saveConfig('ysrtech_emailcampaigns/sending/mailgun_webhook_signing_key', $key);
Mage::app()->getCacheInstance()->flush();

$url  = rtrim((string) Mage::getStoreConfig('web/unsecure/base_url'), '/') . '/index.php/emailcampaigns/webhook/mailgun';
$call = function (array $event, bool $sign = true) use ($url, $key): int {
    $ts    = (string) time();
    $token = bin2hex(random_bytes(16));
    $body  = json_encode([
        'signature'  => ['timestamp' => $ts, 'token' => $token, 'signature' => $sign ? hash_hmac('sha256', $ts . $token, $key) : 'nope'],
        'event-data' => $event,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // The request only keeps the notice; cron applies it. Here, now.
    Mage::getSingleton('ysrtech_emailcampaigns/webhook_inbox')->process();

    return $code;
};
$token = 'synctesttokensent000000000000002';
$item  = fn () => $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/queue')} WHERE tracking_token = ?", [$token]);

$ok('an unsigned webhook is refused', $call(['event' => 'complained', 'recipient' => 'd@sync-test.invalid'], false) === 401
    && $store->reasonOf('d@sync-test.invalid') === null);
$call(['event' => 'opened', 'recipient' => 'd@sync-test.invalid', 'user-variables' => ['tracking_token' => $token], 'client-info' => ['bot' => 'apple']]);
$ok('a machine open is counted apart', (int) $item()['open_count'] === 1 && (int) $item()['machine_open_count'] === 1);
$call(['event' => 'opened', 'recipient' => 'd@sync-test.invalid', 'user-variables' => ['tracking_token' => $token], 'client-info' => ['user-agent' => 'Mozilla/5.0 (iPhone)']]);
$ok('a real open is a real open', (int) $item()['open_count'] === 2 && (int) $item()['machine_open_count'] === 1);
$call(['event' => 'failed', 'severity' => 'temporary', 'recipient' => 'd@sync-test.invalid']);
$ok('a soft bounce suppresses nothing', $store->reasonOf('d@sync-test.invalid') === null && !$item()['bounced_at']);
$code = $call(['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'd@sync-test.invalid',
    'user-variables' => ['tracking_token' => '%recipient.tracking_token%'],
    'delivery-status' => ['code' => 550, 'message' => '5.1.1 mailbox does not exist']]);
$row = $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/suppression')} WHERE email = 'd@sync-test.invalid'");
$ok('a hard bounce suppresses, with the detail', $code === 200 && $row && $row['reason'] === 'hard_bounce' && $row['detail'] === '550 5.1.1 mailbox does not exist');
$ok('and is stamped on the message, found by address when the token is a placeholder', (bool) $item()['bounced_at']);
$call(['event' => 'complained', 'recipient' => 'd@sync-test.invalid', 'user-variables' => ['tracking_token' => $token]]);
$ok('a complaint is stamped and outranks the bounce', (bool) $item()['complained_at'] && $store->reasonOf('d@sync-test.invalid') === 'complaint');

$config->deleteConfig('ysrtech_emailcampaigns/sending/mailgun_webhook_signing_key');
Mage::app()->getCacheInstance()->flush();
$clean();

echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
