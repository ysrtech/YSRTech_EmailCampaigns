<?php
/**
 * The send job's pacing, with a transport that sends nothing.
 *
 *   cd <openmage-core> && php <module>/dev/tests/sending.php
 *
 * Nothing leaves the machine: the transport defined below only counts what
 * it is handed, and it is selected in memory for this process alone. The
 * saved configuration - mail disabled, no credentials - is never touched.
 */
require_once 'app/Mage.php';
Mage::app('admin');

class YSRTech_EmailCampaigns_Model_Transport_Noop implements YSRTech_EmailCampaigns_Model_Transport_Interface
{
    public static $calls = 0;
    public static $recipients = 0;
    public static $envelope = [];
    public static $sentWith = [];
    public static $lastHtml = '';
    public static $lastRecipients = [];
    public function setEnvelope(?string $from, ?string $replyTo = null): void { self::$envelope = [$from, $replyTo]; }
    public function supportsRecipientVariables(): bool { return true; }
    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null) { return null; }
    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null, ?callable $onChunkSent = null)
    {
        self::$sentWith[] = self::$envelope;
        self::$lastHtml = $html;
        self::$lastRecipients = $recipients;
        self::$calls++;
        self::$recipients += count($recipients);
        return null;
    }
}

$res  = Mage::getSingleton('core/resource');
$db   = $res->getConnection('core_write');
$t    = fn ($n) => $res->getTableName($n);
$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$set  = fn (string $path, $value) => Mage::app()->getStore()->setConfig($path, $value);

$pendingElsewhere = (int) $db->fetchOne("SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/queue')} WHERE status IN ('pending', 'sending')");

if ($pendingElsewhere) {
    echo "There is other mail waiting in the queue; this test would mark it sent. Not running.\n";
    exit(1);
}

$templateId = (int) $db->fetchOne("SELECT MIN(template_id) FROM {$t('ysrtech_emailcampaigns/template')}");
$segmentId  = (int) $db->fetchOne("SELECT MIN(segment_id) FROM {$t('ysrtech_emailcampaigns/segment')}");

$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData([
    'name' => '[test] pacing', 'subject' => 'Pacing', 'template_id' => $templateId, 'store_id' => 0,
    'status' => 'scheduled', 'scheduled_at' => Varien_Date::now(), 'included_segment_ids' => [$segmentId],
]);
$campaign->save();
$id = (int) $campaign->getId();
$count = fn (string $status) => (int) $db->fetchOne(
    "SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/queue')} WHERE campaign_id = ? AND status = ?", [$id, $status]
);

$sender = Mage::getSingleton('ysrtech_emailcampaigns/sender');

// The store's switch is still on "disabled" here, exactly as saved
$sender->launchScheduledCampaigns();
$total = $count('pending');
$ok("the launcher queues the campaign in the background ({$total} recipients)", $total > 1500
    && Mage::getModel('ysrtech_emailcampaigns/campaign')->load($id)->getStatus() === 'sending');
$ok('nothing is sent while the store has email disabled', $sender->processQueue() === 0 && $count('sent') === 0);

// For this process only: mail "enabled", through the transport above
$set('system/smtp/disable', 0);
$set('ysrtech_emailcampaigns/sending/transport', 'noop');
$set('ysrtech_emailcampaigns/sending/batch_size', 500);
$set('ysrtech_emailcampaigns/sending/max_per_minute', 700);

$handled = $sender->processQueue();
$ok('one run stops at the per-minute limit', $handled === 700 && $count('sent') === 700 && $count('pending') === $total - 700);
$ok('in batches: 500 then 200, one call each', YSRTech_EmailCampaigns_Model_Transport_Noop::$calls === 2
    && YSRTech_EmailCampaigns_Model_Transport_Noop::$recipients === 700);
$ok('nothing is left claimed', $count('sending') === 0);

// A second sender while one holds the lock does nothing
$other = new Varien_Db_Adapter_Pdo_Mysql($db->getConfig());
$other->fetchOne("SELECT GET_LOCK('ysrtech_emailcampaigns_send', 0)");
$ok('a run that overlaps another stands aside', $sender->processQueue() === 0 && $count('sent') === 700);
$other->query("SELECT RELEASE_LOCK('ysrtech_emailcampaigns_send')");
$other->closeConnection();

// No limit: everything due goes in one run, inside the time budget
$set('ysrtech_emailcampaigns/sending/max_per_minute', 0);
$start   = microtime(true);
$handled = $sender->processQueue();
$took    = microtime(true) - $start;
$ok(sprintf('with no limit a run sends until done or out of time (%d in %.1fs)', $handled, $took),
    $handled > 0 && $took < 60 && ($count('pending') === 0 || $took >= 39));

while ($count('pending') > 0) {
    $sender->processQueue();
}

$ok('the campaign is marked sent when its queue is empty',
    Mage::getModel('ysrtech_emailcampaigns/campaign')->load($id)->getStatus() === 'sent' && $count('sent') === $total);

// ---- a campaign with its own email and its own sender
$shop = (string) Mage::app()->getDefaultStoreView()->getConfig('web/unsecure/base_url');
$own = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData([
    'name' => '[test] own content', 'subject' => 'Own', 'template_id' => $templateId, 'store_id' => 0,
    'status' => 'scheduled', 'scheduled_at' => Varien_Date::now(), 'included_segment_ids' => [$segmentId],
    'html' => '<p>ONLY-IN-THE-CAMPAIGN {{ customer.firstname|there }} <a href="' . $shop . 'sale.html">Shop</a> <a href="https://elsewhere.example/">Other</a></p>',
    'preheader' => 'Own preview text',
    'from_name' => 'Deals Desk', 'from_local' => 'deals', 'reply_to' => 'help@example.invalid',
]);
$own->save();
$ownId = (int) $own->getId();
$set('ysrtech_emailcampaigns/sending/from_email', 'news@mail.example.invalid');
$set('ysrtech_emailcampaigns/sending/max_per_minute', 500);
YSRTech_EmailCampaigns_Model_Transport_Noop::$sentWith = [];
$sender->launchScheduledCampaigns();
$sender->processQueue();
$ok('a campaign sends its own copy, not the template it started from',
    strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'ONLY-IN-THE-CAMPAIGN') !== false
    && strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'Own preview text') !== false);
$ok('its links to the store carry each recipient\'s token and the campaign\'s name; links elsewhere are untouched',
    strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'href="' . $shop . 'sale.html?ec=%recipient.tracking_token%&amp;utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=test-own-content"') !== false
    && strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'href="https://elsewhere.example/"') !== false);
$ok('from its own sender at the configured domain, with its reply address',
    YSRTech_EmailCampaigns_Model_Transport_Noop::$sentWith[0] === ['Deals Desk <deals@mail.example.invalid>', 'help@example.invalid']);
$ok('and the sender is put back to the default afterwards',
    YSRTech_EmailCampaigns_Model_Transport_Noop::$envelope === [null, null]);
// ---- links straight to the store: the unsubscribe link is the store's own page, per person
$db->delete($t('ysrtech_emailcampaigns/queue'), ['campaign_id = ?' => $ownId]);
$db->update($t('ysrtech_emailcampaigns/campaign'), ['status' => 'scheduled', 'scheduled_at' => Varien_Date::now(), 'sent_at' => null,
    'html' => '<p><a href="' . $shop . 'sale.html">Shop</a> <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>'], ['campaign_id = ?' => $ownId]);
$set('ysrtech_emailcampaigns/tracking/direct_links', 1);
$sender->launchScheduledCampaigns();
$sender->processQueue();
$one = YSRTech_EmailCampaigns_Model_Transport_Noop::$lastRecipients[0] ?? [];
$ok('with links going straight to the store, the shared body asks the provider for each person\'s own unsubscribe address',
    strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'href="%recipient.optout_url%"') !== false
    && strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, '%unsubscribe_url%') === false);
$ok('and each person carries it: the store\'s unsubscribe page with their message\'s token',
    strpos((string) ($one['vars']['optout_url'] ?? ''), 'emailcampaigns/preferences/unsubscribe/token/' . ($one['vars']['tracking_token'] ?? 'none')) !== false);
$ok('links to the store still carry the token, which is how the click is now counted',
    strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'sale.html?ec=%recipient.tracking_token%') !== false);
// A test send goes out the same way
$sender->sendTest(Mage::getModel('ysrtech_emailcampaigns/campaign')->load($ownId), 'tester@example.com');
$one = YSRTech_EmailCampaigns_Model_Transport_Noop::$lastRecipients[0] ?? [];
$ok('a test send has the store\'s unsubscribe link too, not the provider\'s',
    strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'href="%recipient.optout_url%"') !== false
    && strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, '%unsubscribe_url%') === false
    && strpos((string) ($one['vars']['optout_url'] ?? ''), 'emailcampaigns/preferences/unsubscribe/test/1') !== false && ($one['email'] ?? '') === 'tester@example.com');
$set('ysrtech_emailcampaigns/tracking/direct_links', 0);
$sender->sendTest(Mage::getModel('ysrtech_emailcampaigns/campaign')->load($ownId), 'tester@example.com');
$one = YSRTech_EmailCampaigns_Model_Transport_Noop::$lastRecipients[0] ?? [];
$ok('with the setting off, a test send still never carries the provider\'s live unsubscribe link',
    strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, '%unsubscribe_url%') === false
    && strpos(YSRTech_EmailCampaigns_Model_Transport_Noop::$lastHtml, 'href="%recipient.optout_url%"') !== false
    && strpos((string) ($one['vars']['optout_url'] ?? ''), 'emailcampaigns/preferences/unsubscribe/test/1') !== false);

$db->delete($t('ysrtech_emailcampaigns/queue'), ['campaign_id = ?' => $ownId]);
$db->delete($t('ysrtech_emailcampaigns/campaign'), ['campaign_id = ?' => $ownId]);

$db->delete($t('ysrtech_emailcampaigns/queue'), ['campaign_id = ?' => $id]);
$db->delete($t('ysrtech_emailcampaigns/campaign'), ['campaign_id = ?' => $id]);

echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
