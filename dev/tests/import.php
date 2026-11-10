<?php
/**
 * A provider migration end to end, against a provider that is not there.
 *
 *   cd <openmage-core> && php <module>/dev/tests/import.php
 *
 * The runner is given a stand-in client, so this covers what the runner does
 * with a provider's pages - stage order, cursors, the rate-limit wait, what
 * lands in which table - without a key or a network.
 */
require_once 'app/Mage.php';
Mage::app('admin');

class Test_Fake_Provider implements YSRTech_EmailCampaigns_Model_Import_Provider_Interface
{
    public static $limitOnce = true;
    public function setCredentials(array $credentials) { return $this; }
    public function getName(): string { return 'Fake'; }
    public function getCredentialFields(): array { return []; }
    public function getKeyHelp(): string { return ''; }
    public function account(): array { return ['name' => 'Fake account']; }
    public function discover(): array { return ['contacts' => 4, 'suppressions' => 3, 'templates' => 2, 'notes' => []]; }
    public function stages(): array { return ['suppressions', 'contacts', 'templates']; }
    public function convertMergeTags(string $html): string { return str_replace('*|FNAME|*', '{{customer.firstname}}', $html); }
    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        $page = (int) ($cursor['page'] ?? 0);
        $data = [
            'suppressions' => [
                [['email' => 'bounce@t.import-test.invalid', 'reason' => 'bounce', 'detail' => '550 no such user'],
                 ['email' => 'unsub@t.import-test.invalid', 'reason' => 'unsubscribe', 'detail' => '']],
                [['email' => 'spam@t.import-test.invalid', 'reason' => 'complaint', 'detail' => ''],
                 ['email' => 'bounce@t.import-test.invalid', 'reason' => 'complaint', 'detail' => '']],
            ],
            'contacts' => [
                [['email' => 'A@t.import-test.invalid', 'first_name' => 'Ann', 'last_name' => 'A', 'fields' => ['city' => 'X'], 'tags' => [], 'status' => 'subscribed'],
                 ['email' => 'bounce@t.import-test.invalid', 'first_name' => 'Bo', 'last_name' => '', 'fields' => [], 'tags' => [], 'status' => 'subscribed']],
                [['email' => 'a@t.import-test.invalid', 'first_name' => 'Ann', 'last_name' => 'A', 'fields' => [], 'tags' => [], 'status' => 'subscribed'],
                 ['email' => 'gone@t.import-test.invalid', 'first_name' => '', 'last_name' => '', 'fields' => [], 'tags' => [], 'status' => 'unsubscribed'],
                 ['email' => 'wait@t.import-test.invalid', 'first_name' => '', 'last_name' => '', 'fields' => [], 'tags' => [], 'status' => 'pending'],
                 ['email' => 'nope', 'status' => 'subscribed']],
            ],
            'templates' => [
                [['name' => 'ImportTest One', 'subject' => 'Hi *|FNAME|*', 'html' => '<p>Hi *|FNAME|*</p>']],
                [['name' => 'ImportTest Two', 'subject' => 'S', 'html' => '<p>{{ contact.CITY }}</p>']],
            ],
        ][$stage];

        if ($stage === 'contacts' && $page === 1 && self::$limitOnce) {
            self::$limitOnce = false;
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception('Fake is rate limiting.', true, 1);
        }

        return ['items' => $data[$page], 'cursor' => isset($data[$page + 1]) ? ['page' => $page + 1] : null];
    }
}

class Test_Runner extends YSRTech_EmailCampaigns_Model_Import_Runner
{
    protected function _client(YSRTech_EmailCampaigns_Model_Import $import) { return new Test_Fake_Provider(); }
}

$db   = Mage::getSingleton('core/resource')->getConnection('core_write');
$t    = fn ($n) => Mage::getSingleton('core/resource')->getTableName($n);
$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$clean = function () use ($db, $t) {
    $db->delete($t('newsletter/subscriber'), "subscriber_email LIKE '%@t.import-test.invalid'");
    $db->delete($t('ysrtech_emailcampaigns/suppression'), "email LIKE '%@t.import-test.invalid'");
    $db->delete($t('ysrtech_emailcampaigns/template'), "name LIKE 'ImportTest %'");
    $db->delete($t('ysrtech_emailcampaigns/import'), "source_name = 'import-test'");
};
$clean();

$import = Mage::getModel('ysrtech_emailcampaigns/import')->setData([
    'type' => 'provider', 'provider' => 'mailchimp', 'source_name' => 'import-test', 'status' => 'pending',
]);
$import->setCredentials(['api_key' => 'secret-key-us1'])
    // Asked for out of order on purpose: suppressions must still go first
    ->setOptions(['stages' => ['templates', 'contacts', 'suppressions'], 'update_existing' => 1])->save();
$id = (int) $import->getId();

$ok('key is not stored in the clear', strpos((string) $import->getData('credentials_enc'), 'secret-key') === false
    && $import->getCredentials()['api_key'] === 'secret-key-us1');

$runner = new Test_Runner();
$runner->run(20, $id);
$import->load($id);
$ok('waits on a rate limit rather than failing', $import->getStatus() === 'processing' && $import->getRetryAt() && $import->getStage() === 'contacts');
$ok('suppressions were in before any contact', (int) $db->fetchOne("SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/suppression')} WHERE email LIKE '%@t.import-test.invalid'") === 3);

sleep(2);
$db->update($t('ysrtech_emailcampaigns/import'), ['retry_at' => null], ['import_id = ?' => $id]);
$runner->run(20, $id);
$import->load($id);
$stats = $import->getStats();

$ok('finishes', $import->getStatus() === 'done');
$ok('key is deleted when it finishes', (string) $import->getData('credentials_enc') === '');
$reason = fn ($e) => $db->fetchOne("SELECT reason FROM {$t('ysrtech_emailcampaigns/suppression')} WHERE email = ?", [$e]);
$status = fn ($e) => (int) $db->fetchOne("SELECT subscriber_status FROM {$t('newsletter/subscriber')} WHERE subscriber_email = ?", [$e]);
$ok('a complaint outranks a bounce for the same address', $reason('bounce@t.import-test.invalid') === 'complaint');
$ok('a suppressed contact is imported unsubscribed', $status('bounce@t.import-test.invalid') === 3);
$ok('a subscribed contact is subscribed', $status('a@t.import-test.invalid') === 1);
$ok('unsubscribed at the provider: unsubscribed and suppressed here', $status('gone@t.import-test.invalid') === 3 && $reason('gone@t.import-test.invalid') === 'unsubscribe');
$ok('pending at the provider: unconfirmed here', $status('wait@t.import-test.invalid') === 4);
$ok('counts', $stats['created'] === 4 && $stats['duplicates'] === 1 && $stats['invalid'] === 1
    && $stats['suppressions_added'] === 3 && $stats['extras_skipped'] === 1 && $stats['templates_created'] === 2
    && $stats['templates_leftover_tags'] === 1);
$tpl = Mage::getModel('ysrtech_emailcampaigns/template')->load('ImportTest One (Fake)', 'name');
$ok('template merge tags converted', $tpl->getId() && strpos($tpl->getHtml(), '{{customer.firstname}}') !== false && $tpl->getSubject() === 'Hi {{customer.firstname}}');
$ok('no welcome email queued', (int) $db->fetchOne("SELECT COUNT(*) FROM {$t('core/email_queue')}") === 0);

if (!$fail) { $clean(); }
echo $fail ? "\n{$fail} FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
