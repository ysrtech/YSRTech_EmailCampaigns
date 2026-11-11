<?php
/**
 * Plain-text letters, tidied names, subjects counted as read, the webhook
 * inbox and the campaign report.
 *
 *   cd <openmage-core> && php <module>/dev/tests/letters.php
 *
 * Nothing is sent: Mailgun's HTTP call and the SMTP send are stood in for.
 */
require_once 'app/Mage.php';
Mage::app('admin');

/** Mailgun, up to the point where it would post */
class Test_Mailgun extends YSRTech_EmailCampaigns_Model_Transport_Mailgun
{
    public static $posts = [];
    protected function _post(array $params) { self::$posts[] = $params; return []; }
}

/** A transport that expands nothing itself, as SMTP does, recording what it was given */
class YSRTech_EmailCampaigns_Model_Transport_Letterbox implements YSRTech_EmailCampaigns_Model_Transport_Interface
{
    public static $sent = [];
    public static $letter = 'never set';
    public function setLetter(?array $letter): void { self::$letter = $letter; }
    public function supportsRecipientVariables(): bool { return false; }
    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null) { return null; }
    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null, ?callable $onChunkSent = null)
    {
        foreach ($recipients as $r) { self::$sent[] = $r + ['subject' => $subject, 'letter' => self::$letter]; }
        return null;
    }
}

$res  = Mage::getSingleton('core/resource');
$db   = $res->getConnection('core_write');
$t    = fn ($n) => $res->getTableName($n);
$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$h    = Mage::helper('ysrtech_emailcampaigns');
$set  = fn (string $path, $value) => Mage::app()->getStore()->setConfig('ysrtech_emailcampaigns/' . $path, $value);
$throws = function (callable $work): string { try { $work(); } catch (Mage_Core_Exception $e) { return $e->getMessage(); } return ''; };

if ((int) $db->fetchOne("SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/queue')} WHERE status IN ('pending', 'sending')")) {
    echo "There is other mail waiting in the queue; this test would mark it sent. Not running.\n";
    exit(1);
}

// ------------------------------------------------------------- tidy names
foreach ([
    'CHAYA' => 'Chaya', 'chaya' => 'Chaya', 'Chaya' => 'Chaya',
    'MARY-JANE' => 'Mary-Jane', "o'neil" => "O'Neil", 'DE LA CRUZ' => 'De La Cruz',
    'McDonald' => 'McDonald', 'DeShawn' => 'DeShawn', 'van der Berg' => 'van der Berg',
    '  SARAH   LEAH ' => 'Sarah Leah', 'ÉLODIE' => 'Élodie', 'חיה' => 'חיה', '' => '', 'j' => 'J',
] as $typed => $tidy) {
    $ok("name: \"{$typed}\" is \"{$tidy}\"", $h->tidyName((string) $typed) === $tidy);
}

// ------------------------------------------------- subjects, as they are read
$sender = Mage::getSingleton('ysrtech_emailcampaigns/sender');
$ok('a placeholder is counted as what replaces it', $sender->asSeen('{{ customer.firstname|there }}, your order') === 'Chaya, your order');

$long     = 'Hi {{ customer.firstname|there }}, the winter catalogue has arrived at {{ store.name }}';
$template = Mage::getModel('ysrtech_emailcampaigns/template')->setData(['html' => '<p>Hello there, this is the email. {{unsubscribe}}</p>', 'subject' => $long]);
$subject  = array_values(array_filter(Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($template, $long), fn ($c) => $c['code'] === 'subject'))[0];
$ok('the subject check counts what is read, not what is typed', mb_strlen($long) > 70 && $subject['level'] === 'ok'
    && strpos($subject['message'], (string) mb_strlen($sender->asSeen($long))) === 0);

// ------------------------------------------------------------- the letter
/** @var YSRTech_EmailCampaigns_Model_Letter $letter */
$letter = Mage::getSingleton('ysrtech_emailcampaigns/letter');
$vars   = ['customer' => ['firstname' => 'Chaya'], 'store' => ['name' => 'Pardes'], 'unsubscribe_url' => 'https://shop.example/u/abc'];

$text = $letter->text("Hi {{ customer.firstname|there }},\r\n\r\nThe new haggadah is in: https://shop.example/haggadah.\r\n\r\nYossi", $vars);
$ok('text: names filled in, line endings plain', strpos($text, "Hi Chaya,\n\nThe new haggadah") === 0 && strpos($text, "\r") === false);
$ok('text: the way out is added at the end, with the reader\'s link', substr($text, -strlen('unsubscribe here: https://shop.example/u/abc')) === 'unsubscribe here: https://shop.example/u/abc');

$own = $letter->text("Hi,\n\nTo stop these, go to {{ unsubscribe_url }} any time.\n\nYossi", $vars);
$ok('text: a letter with its own way out gets no second one', substr_count($own, 'https://shop.example/u/abc') === 1 && substr($own, -5) === 'Yossi');
$ok('text: the html footer token is read as the link', strpos($letter->text('Bye {{unsubscribe}}', $vars), 'Bye https://shop.example/u/abc') === 0);

$html = $letter->html($letter->text("Tom & Jerry <b>\n\nSee https://shop.example/a?b=1&c=2. Bye", $vars));
$ok('html: escaped, in paragraphs, links made, nothing else', strpos($html, '<p>Tom &amp; Jerry &lt;b&gt;</p>') !== false
    && strpos($html, '<a href="https://shop.example/a?b=1&amp;c=2">https://shop.example/a?b=1&amp;c=2</a>. Bye') !== false
    && !preg_match('/style=|<img|<table|<font|<div/i', $html));

$checks = array_column($letter->checks("Hi,\n\nShort.", 'Subject', false), null, 'code');
$ok('checks: says the way out is added, and that it is text only', strpos($checks['unsubscribe']['message'], 'added for you') !== false && strpos($checks['tracking']['message'], 'text only') !== false);
$ok('checks: an empty letter cannot be sent', array_column($letter->checks('  ', 'S', false), 'level', 'code')['content'] === 'bad');
$ok('checks: html typed into a letter is pointed out', isset(array_column($letter->checks('Hi <b>there</b>', 'S', false), null, 'code')['html']));

// -------------------------------------------- a letter campaign, per reader
$emails = ['LETTER-ONE@letters.invalid' => ['MIRIAM', 'COHEN'], 'letter-two@letters.invalid' => ['dovid', 'levi'], 'letter-three@letters.invalid' => ['', '']];
$clean  = function () use ($db, $t) {
    $db->delete($t('ysrtech_emailcampaigns/queue'), "email LIKE '%@letters.invalid'");
    $db->delete($t('newsletter/subscriber'), "subscriber_email LIKE '%@letters.invalid'");
    $db->delete($t('ysrtech_emailcampaigns/suppression'), "email LIKE '%@letters.invalid'");
    $db->delete($t('ysrtech_emailcampaigns/campaign'), "name LIKE '[test] letter%'");
    $db->delete($t('ysrtech_emailcampaigns/webhook_event'));
};
$clean();

$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->setData([
    'name' => '[test] letter', 'subject' => 'A note for {{ customer.firstname|you }}', 'store_id' => 0, 'status' => 'sending',
    'format' => 'letter', 'letter_text' => "Hi {{ customer.firstname|there }},\n\nJust a note.\n\nYossi", 'letter_track' => 0,
    'from_name' => 'Yossi',
]);
$campaign->save();
$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->load($campaign->getId());

$ok('a letter with words has content; one without has none', $campaign->getContentTemplate() !== null
    && Mage::getModel('ysrtech_emailcampaigns/campaign')->setData(['format' => 'letter', 'letter_text' => ' '])->getContentTemplate() === null);

$preview = $sender->renderPreview($campaign->getContentTemplate());
$ok('the preview is the plain-looking copy, way out included', strpos($preview, '<p>Hi Chaya,</p>') !== false && strpos($preview, 'unsubscribe here: <a href="http') !== false);

$state = Mage::getSingleton('ysrtech_emailcampaigns/campaign_editor')->state($campaign);
$ok('the page knows it is a letter', $state['content']['letter']['is'] && $state['content']['has'] && !$state['content']['letter']['own_optout']);

$n = 0;
foreach ($emails as $email => [$first, $last]) {
    $db->insert($t('newsletter/subscriber'), ['subscriber_email' => strtolower($email), 'subscriber_status' => 1, 'store_id' => 1,
        'subscriber_firstname' => $first, 'subscriber_lastname' => $last, 'subscriber_confirm_code' => 'x']);
    $db->insert($t('ysrtech_emailcampaigns/queue'), ['campaign_id' => $campaign->getId(), 'subscriber_id' => $db->lastInsertId(), 'email' => strtolower($email),
        'status' => 'pending', 'queued_at' => Varien_Date::now(), 'tracking_token' => 'lettertoken' . str_pad((string) ++$n, 21, '0', STR_PAD_LEFT)]);
}

// For this process only: mail "enabled", through the transport above
Mage::app()->getStore()->setConfig('system/smtp/disable', 0);
$set('sending/transport', 'letterbox');
$set('sending/from_email', 'news@mail.example.invalid');
$sender->processQueue();

$sent = array_column(YSRTech_EmailCampaigns_Model_Transport_Letterbox::$sent, null, 'email');
$one  = $sent['letter-one@letters.invalid'] ?? [];
$ok('all three went', count($sent) === 3 && (int) $db->fetchOne("SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/queue')} WHERE campaign_id = ? AND status = 'sent'", [$campaign->getId()]) === 3);
$ok('each reader gets their own words, name tidied', strpos($one['text'], "Hi Miriam,\n\nJust a note.") === 0
    && strpos($sent['letter-two@letters.invalid']['text'], 'Hi Dovid,') === 0 && strpos($sent['letter-three@letters.invalid']['text'], 'Hi there,') === 0);
$ok('with their own unsubscribe address, on this store', (bool) preg_match('#unsubscribe here: https?://\S+/emailcampaigns/preferences/unsubscribe/token/lettertoken0+1/?$#', $one['text']));
$ok('untracked: no html part at all, and no token riding along', $one['html'] === '' && !isset($one['vars']['tracking_token']) && $one['letter']['track'] === false);
$ok('the transport is told it is a letter, and told again when it is over', YSRTech_EmailCampaigns_Model_Transport_Letterbox::$letter === null);
$ok('the name on the envelope is tidied too', $one['name'] === 'Miriam Cohen');

// tracked: the html copy goes with it
YSRTech_EmailCampaigns_Model_Transport_Letterbox::$sent = [];
$db->update($t('ysrtech_emailcampaigns/queue'), ['status' => 'pending', 'sent_at' => null, 'attempts' => 0], ['campaign_id = ?' => $campaign->getId()]);
$campaign->setLetterTrack(1)->setStatus('sending')->save();
$sender->processQueue();
$one = array_column(YSRTech_EmailCampaigns_Model_Transport_Letterbox::$sent, null, 'email')['letter-one@letters.invalid'] ?? [];
$ok('tracked: a plain-looking html copy of the same words', strpos($one['html'] ?? '', '<p>Hi Miriam,</p>') !== false && strpos($one['text'], 'Hi Miriam,') === 0);

// ------------------------------------------------------ what Mailgun is sent
$set('sending/mailgun_domain', 'mg.example.invalid');
$mailgun = new Test_Mailgun();
$batch   = [
    ['email' => 'a@letters.invalid', 'name' => 'A', 'vars' => ['customer' => ['firstname' => 'Ada'], 'optout_url' => 'https://shop.example/u/1', 'unsubscribe_url' => 'https://shop.example/u/1']],
    ['email' => 'b@letters.invalid', 'name' => 'B', 'vars' => ['customer' => ['firstname' => 'Ben'], 'optout_url' => 'https://shop.example/u/2', 'unsubscribe_url' => 'https://shop.example/u/2']],
];
$shared = "Hi %recipient.firstname%,\n\nNote.\n\nUnsubscribe: %recipient.optout_url%";

$mailgun->setLetter(['text' => $shared, 'track' => false]);
$mailgun->sendBatch($batch, 'A note', '');
$post = Test_Mailgun::$posts[0];
$ok('mailgun letter: text and no html', $post['text'] === $shared && !isset($post['html']));
$ok('mailgun letter: tracking off', $post['o:tracking'] === 'no' && $post['o:tracking-opens'] === 'no' && $post['o:tracking-clicks'] === 'no');
$ok('mailgun letter: no custom variable, no tag, no provider unsubscribe token', !preg_grep('/^(v:|o:tag)/', array_keys($post)) && strpos(json_encode($post), '%unsubscribe_url%') === false);
$variables = json_decode($post['recipient-variables'], true);
$ok('mailgun letter: each reader carries a name and their own link, and no token', $variables['a@letters.invalid'] == ['email' => 'a@letters.invalid', 'firstname' => 'Ada', 'lastname' => '', 'optout_url' => 'https://shop.example/u/1']);

$mailgun->setLetter(['text' => $shared, 'track' => true]);
$mailgun->sendBatch($batch, 'A note', $letter->html($shared));
$post = Test_Mailgun::$posts[1];
$ok('mailgun letter, tracked: the html copy goes too, clicks in html only', strpos($post['html'], '<p>Hi %recipient.firstname%,</p>') !== false && $post['text'] === $shared
    && $post['o:tracking'] === 'yes' && $post['o:tracking-clicks'] === 'htmlonly');

$mailgun->setLetter(['text' => '', 'track' => false]);
$ok('mailgun letter: refuses to send an empty one', strpos($throws(fn () => $mailgun->sendBatch($batch, 'A note', '')), 'no text') !== false);

// ------------------------------------------------------- the webhook inbox
/** @var YSRTech_EmailCampaigns_Model_Webhook_Inbox $inbox */
$inbox = Mage::getSingleton('ysrtech_emailcampaigns/webhook_inbox');
$row   = fn (string $email) => $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/queue')} WHERE email = ? AND campaign_id = ?", [$email, $campaign->getId()]);
$event = fn (string $id, array $data) => json_encode(['signature' => [], 'event-data' => ['id' => $id] + $data]);
$then  = time() - 3600;

$inbox->store('mailgun', 'ev-1', $event('ev-1', ['event' => 'delivered', 'recipient' => 'letter-one@letters.invalid', 'timestamp' => $then]));
$inbox->store('mailgun', 'ev-1', $event('ev-1', ['event' => 'delivered', 'recipient' => 'letter-one@letters.invalid', 'timestamp' => $then]));
$inbox->store('mailgun', 'ev-2', $event('ev-2', ['event' => 'opened', 'recipient' => 'letter-one@letters.invalid', 'timestamp' => $then + 60.5, 'client-info' => ['user-agent' => 'Mozilla/5.0 (iPhone)']]));
$inbox->store('mailgun', 'ev-3', $event('ev-3', ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'letter-two@letters.invalid', 'timestamp' => $then, 'delivery-status' => ['code' => 550, 'message' => 'no such user']]));

$ok('kept, not applied: a provider\'s retry of the same notice is one row', $inbox->counts()['waiting'] === 3 && !$row('letter-one@letters.invalid')['delivered_at']);

$applied = $inbox->process();
$one     = $row('letter-one@letters.invalid');
$ok('cron applies them and clears the inbox', $applied === 3 && $inbox->counts()['waiting'] === 0);
$ok('delivered is stamped with when it happened, not when it was applied', $one['delivered_at'] === gmdate('Y-m-d H:i:s', $then));
$ok('an open found by address, a letter carrying no token', (int) $one['open_count'] === 1 && $one['opened_at'] === gmdate('Y-m-d H:i:s', $then + 60));
$ok('a hard bounce still suppresses', (bool) $row('letter-two@letters.invalid')['bounced_at']
    && Mage::getResourceModel('ysrtech_emailcampaigns/suppression')->reasonOf('letter-two@letters.invalid') === 'hard_bounce');

// one that cannot be applied stays, is retried, then set aside
$db->insert($t('ysrtech_emailcampaigns/webhook_event'), ['provider' => 'mailgun', 'event_key' => 'ev-bad', 'received_at' => Varien_Date::now(),
    'payload' => $event('ev-bad', ['event' => 'complained', 'recipient' => str_repeat('x', 400) . '@letters.invalid'])]);
$inbox->store('mailgun', 'ev-4', $event('ev-4', ['event' => 'delivered', 'recipient' => 'letter-three@letters.invalid', 'timestamp' => $then]));
$inbox->process();
$bad = $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/webhook_event')} WHERE event_key = 'ev-bad'");

if ($bad) {
    $ok('one that fails is kept with the reason, and does not hold up the rest', (int) $bad['attempts'] === 1 && $bad['error'] !== '' && (bool) $row('letter-three@letters.invalid')['delivered_at']);
    for ($i = 0; $i < $inbox::MAX_TRIES; $i++) { $inbox->process(); }
    $ok('after a few tries it is set aside, not tried for ever', $inbox->counts() == ['waiting' => 0, 'failed' => 1, 'oldest' => null]);
} else {
    // This install's suppression table took the long address; nothing failed
    $ok('a notice the store can apply is applied', (bool) $row('letter-three@letters.invalid')['delivered_at']);
}

$inbox->store('mailgun', 'ev-5', '{not json');
$ok('a notice with nothing in it is dropped, not retried', $inbox->process() === 1);

// ------------------------------------------------------------ the report
$db->update($t('ysrtech_emailcampaigns/queue'), ['delivered_at' => null], ['email = ?' => 'letter-three@letters.invalid']);
$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign')->load($campaign->getId())->setStatus('sent')->setLetterTrack(0);
$report   = Mage::getSingleton('ysrtech_emailcampaigns/campaign_report')->compute($campaign);

$ok('report: sent, delivered, bounced', $report['sent'] === 3 && $report['delivered'] === 1 && $report['bounced'] === 1);
$ok('report: the one nobody has reported on is named', $report['waiting'] === 1 && $report['waiting_emails'] === ['letter-three@letters.invalid']);
$ok('report: a text-only letter\'s opens are unknown, not zero', $report['tracks_opens'] === false);

$campaign->save();
$state = Mage::getSingleton('ysrtech_emailcampaigns/campaign_editor')->state(Mage::getModel('ysrtech_emailcampaigns/campaign')->load($campaign->getId()));
$ok('the campaign page carries the report and the letter\'s words', $state['report']['waiting'] === 1 && strpos($state['content']['letter']['text'], 'Just a note.') !== false);

$clean();

echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
