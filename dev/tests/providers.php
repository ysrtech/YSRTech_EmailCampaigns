<?php
/**
 * Checks the import providers against canned API responses. No request
 * leaves the machine: each provider is subclassed with _send() replaced, and
 * a request with no fixture fails the run instead of going out.
 *
 *   cd /path/to/openmage && php /path/to/YSRTech_EmailCampaigns/dev/tests/providers.php
 *
 * The fixtures are written from the response shapes the providers document,
 * as the code reads them. They prove the paging, mapping and error handling;
 * they cannot prove a provider's API still answers that way.
 *
 * Page sizes are shrunk through the providers' constants so that two pages
 * are two or three rows, not two thousand.
 */
require_once 'app/Mage.php';
Mage::app('admin');

// ---------------------------------------------------------------- harness

$GLOBALS['checks'] = ['pass' => 0, 'fail' => 0];

function ok(string $label, bool $condition, string $why = ''): bool
{
    $GLOBALS['checks'][$condition ? 'pass' : 'fail']++;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label . (!$condition && $why !== '' ? "\n        " . $why : '') . "\n";

    return $condition;
}

function eq(string $label, $expected, $actual): bool
{
    return ok(
        $label,
        $expected === $actual,
        'expected ' . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n        got      " . json_encode($actual, JSON_UNESCAPED_SLASHES)
    );
}

/** Method + URL with the query sorted, so a fixture need not match parameter order */
function fixtureKey(string $method, string $url): string
{
    $parts = parse_url($url);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    ksort($query);

    return $method . ' ' . ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '')
        . ($query ? '?' . http_build_query($query) : '');
}

trait FakeSend
{
    /** @var array<string, mixed> */
    public $fixtures = [];

    /** @var array<int, array{method:string, url:string, headers:array, body:?string}> */
    public $log = [];

    /**
     * @param mixed $response Array to send as JSON with a 200; or a full
     *                        ['status' =>, 'headers' =>, 'body' =>]; or a closure returning either
     */
    public function on(string $method, string $url, $response, array $query = []): self
    {
        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $this->fixtures[fixtureKey($method, $url)] = $response;

        return $this;
    }

    protected function _send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $key = fixtureKey($method, $url);

        if (!array_key_exists($key, $this->fixtures)) {
            throw new RuntimeException('No fixture for ' . $key);
        }

        $response = $this->fixtures[$key];

        if ($response instanceof Closure) {
            $response = $response();
        }

        if (isset($response['status'], $response['body'])) {
            return $response + ['headers' => []];
        }

        return ['status' => 200, 'headers' => [], 'body' => json_encode($response)];
    }

    /** A GET through the provider's own _get(), for the host check */
    public function probe(string $url): array
    {
        return $this->_get($url);
    }

    public function sentTo(string $host): array
    {
        return array_values(array_filter($this->log, static function (array $entry) use ($host) {
            return parse_url($entry['url'], PHP_URL_HOST) === $host;
        }));
    }
}

class Test_Mailchimp extends YSRTech_EmailCampaigns_Model_Import_Provider_Mailchimp
{
    use FakeSend;

    public const PAGE          = 2;
    public const CAMPAIGN_PAGE = 2;
    public const CAMPAIGNS     = 3;
}

class Test_Klaviyo extends YSRTech_EmailCampaigns_Model_Import_Provider_Klaviyo
{
    use FakeSend;

    public const PAGE = 2;
}

class Test_Brevo extends YSRTech_EmailCampaigns_Model_Import_Provider_Brevo
{
    use FakeSend;

    public const BLOCKED_PAGE  = 2;
    public const CONTACT_PAGE  = 2;
    public const TEMPLATE_PAGE = 2;
}

class Test_Kit extends YSRTech_EmailCampaigns_Model_Import_Provider_Kit
{
    use FakeSend;

    public const PAGE           = 2;
    public const BROADCAST_PAGE = 2;
}

class Test_KitCapped extends Test_Kit
{
    public const BROADCASTS = 2;
}

class Test_Sendgrid extends YSRTech_EmailCampaigns_Model_Import_Provider_Sendgrid
{
    use FakeSend;

    public const SUPPRESSION_PAGE = 2;
    public const GROUP_PAGE       = 2;
    public const ROWS             = 2;
    public const TEMPLATE_PAGE    = 2;

    public $dir;

    protected function _exportDir(): string
    {
        return $this->dir;
    }
}

class Test_Mailgun extends YSRTech_EmailCampaigns_Model_Import_Provider_Mailgun
{
    use FakeSend;

    public const SUPPRESSION_PAGE = 2;
    public const MEMBER_PAGE      = 2;
    public const LIST_PAGE        = 2;
    public const TEMPLATE_PAGE    = 2;
}

/**
 * Page a stage to its end the way the import runner will: the cursor goes
 * through JSON between calls, and a retryable error means "same cursor
 * again".
 *
 * @return array{items:array, pages:int, retries:int, totals:array}
 */
function drain($provider, string $stage): array
{
    $cursor = null;
    $out    = ['items' => [], 'pages' => 0, 'retries' => 0, 'totals' => []];

    for ($i = 0; $i < 60; $i++) {
        try {
            $page = $provider->fetch($stage, $cursor);
        } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
            if (!$e->isRetryable() || $out['retries'] > 5) {
                throw $e;
            }

            $out['retries']++;
            continue;
        }

        $out['pages']++;
        $out['items'] = array_merge($out['items'], $page['items']);

        if (isset($page['total'])) {
            $out['totals'][] = $page['total'];
        }

        if ($page['cursor'] === null) {
            return $out;
        }

        $cursor = json_decode(json_encode($page['cursor']), true);
    }

    throw new RuntimeException("Stage {$stage} did not finish in 60 pages");
}

function shapesOk(string $stage, array $items): string
{
    $reasons  = ['unsubscribe', 'bounce', 'complaint'];
    $statuses = ['subscribed', 'unsubscribed', 'bounced', 'complained', 'pending'];

    foreach ($items as $i => $item) {
        $keys = array_keys($item);
        sort($keys);

        if ($stage === 'suppressions') {
            if ($keys !== ['detail', 'email', 'reason'] || !is_string($item['email']) || $item['email'] === ''
                || !in_array($item['reason'], $reasons, true) || !is_string($item['detail'])
            ) {
                return "item {$i}: " . json_encode($item);
            }
        } elseif ($stage === 'contacts') {
            if ($keys !== ['email', 'fields', 'first_name', 'last_name', 'status', 'tags']
                || !is_string($item['email']) || $item['email'] === ''
                || !is_array($item['fields']) || !is_array($item['tags'])
                || $item['tags'] !== array_values($item['tags'])
                || !in_array($item['status'], $statuses, true)
            ) {
                return "item {$i}: " . json_encode($item);
            }
        } else {
            if ($keys !== ['html', 'name', 'subject'] || !is_string($item['name']) || $item['name'] === ''
                || !is_string($item['subject']) || !is_string($item['html']) || $item['html'] === ''
            ) {
                return "item {$i}: " . json_encode($item);
            }
        }
    }

    return '';
}

/** email => reason (or status), for comparing a stage's mapping in one go */
function byEmail(array $items, string $field): array
{
    $out = [];

    foreach ($items as $item) {
        $out[] = $item['email'] . ' ' . $item[$field];
    }

    return $out;
}

/**
 * The checks every provider gets, whatever its API looks like.
 *
 * @param callable $make       Returns a fresh provider with credentials set and no fixtures
 * @param string   $accountUrl The URL account() requests
 */
function commonChecks(string $name, callable $make, string $accountUrl, string $host): void
{
    $p = $make();
    ok("$name: is a provider", $p instanceof YSRTech_EmailCampaigns_Model_Import_Provider_Interface);
    eq("$name: stages in order", ['suppressions', 'contacts', 'templates'], $p->stages());
    ok("$name: key help is one sentence", (bool) preg_match('/^In [^.]+\.$/u', $p->getKeyHelp()), $p->getKeyHelp());

    $p = $make()->on('GET', $accountUrl, ['status' => 401, 'body' => '{"detail":"nope"}']);
    try {
        $p->account();
        ok("$name: 401 throws", false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok("$name: 401 is not retryable", !$e->isRetryable(), $e->getMessage());
    }

    $p = $make()->on('GET', $accountUrl, ['status' => 429, 'headers' => ['retry-after' => '17'], 'body' => '{}']);
    try {
        $p->account();
        ok("$name: 429 throws", false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok("$name: 429 is retryable after 17s", $e->isRetryable() && $e->getRetryAfter() === 17, $e->getMessage());
    }

    $p = $make();
    try {
        $p->probe('https://evil.example.com/steal?x=1');
        ok("$name: foreign host refused", false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok("$name: foreign host refused, nothing sent", !$e->isRetryable() && $p->log === []);
    }

    $p = $make();
    try {
        $p->probe('http://' . $host . '/plain');
        ok("$name: plain http refused", false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok("$name: plain http refused, nothing sent", $p->log === []);
    }

    $p = $make()->setCredentials(['api_key' => '  ']);
    try {
        $p->account();
        ok("$name: empty key throws", false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok("$name: empty key refused, nothing sent", $p->log === []);
    }

    eq("$name: unknown stage is empty", ['items' => [], 'cursor' => null], $make()->fetch('lists', null));
}

function hasHeader(array $entry, string $header): bool
{
    return in_array($header, $entry['headers'], true);
}

// -------------------------------------------------------------- Mailchimp

$mc   = 'https://us21.api.mailchimp.com/3.0';
$make = static function () {
    return (new Test_Mailchimp())->setCredentials(['api_key' => 'k3y-us21']);
};

$fill = static function (Test_Mailchimp $p) use ($mc) {
    $listFields = 'lists.id,lists.stats.member_count,lists.stats.unsubscribe_count,lists.stats.cleaned_count,total_items';
    $memFields  = 'members.email_address,members.status,members.merge_fields,members.tags,members.interests,total_items';

    $p->on('GET', "$mc/", ['account_name' => 'Acme Ltd'], ['fields' => 'account_name']);
    $p->on('GET', "$mc/lists", [
        'lists' => [
            ['id' => 'L1', 'stats' => ['member_count' => 1, 'unsubscribe_count' => 3, 'cleaned_count' => 1]],
            ['id' => 'L2', 'stats' => ['member_count' => 1, 'unsubscribe_count' => 0, 'cleaned_count' => 0]],
        ],
        'total_items' => 2,
    ], ['count' => 1000, 'offset' => 0, 'fields' => $listFields]);
    $p->on('GET', "$mc/campaigns", ['total_items' => 5], ['status' => 'sent', 'count' => 1, 'fields' => 'total_items']);

    // Suppressions: L1 has two pages of unsubscribed, one cleaned, one abuse report; L2 has nothing
    $sup = static function (string $list, string $status, int $offset, array $emails, int $total) use ($p, $mc) {
        $p->on('GET', "$mc/lists/$list/members", [
            'members'     => array_map(static function ($e) { return ['email_address' => $e]; }, $emails),
            'total_items' => $total,
        ], ['status' => $status, 'count' => 2, 'offset' => $offset, 'fields' => 'members.email_address,total_items']);
    };
    $sup('L1', 'unsubscribed', 0, ['u1@x.test', 'u2@x.test'], 3);
    $sup('L1', 'unsubscribed', 2, ['u3@x.test'], 3);
    $sup('L1', 'cleaned', 0, ['c1@x.test'], 1);
    $sup('L2', 'unsubscribed', 0, [], 0);
    $sup('L2', 'cleaned', 0, [], 0);
    $p->on('GET', "$mc/lists/L1/abuse-reports", ['abuse_reports' => [['email_address' => 'a1@x.test']], 'total_items' => 1],
        ['count' => 2, 'offset' => 0, 'fields' => 'abuse_reports.email_address,total_items']);
    $p->on('GET', "$mc/lists/L2/abuse-reports", ['abuse_reports' => [], 'total_items' => 0],
        ['count' => 2, 'offset' => 0, 'fields' => 'abuse_reports.email_address,total_items']);

    // Contacts: L1 has interest groups and two pages; L2 repeats an address from L1
    $p->on('GET', "$mc/lists/L1/interest-categories", ['categories' => [['id' => 'cat1']]], ['count' => 100, 'fields' => 'categories.id']);
    $p->on('GET', "$mc/lists/L1/interest-categories/cat1/interests", ['interests' => [['id' => 'i1', 'name' => 'VIP'], ['id' => 'i2', 'name' => 'Trade']]],
        ['count' => 100, 'fields' => 'interests.id,interests.name']);
    $p->on('GET', "$mc/lists/L2/interest-categories", ['categories' => []], ['count' => 100, 'fields' => 'categories.id']);
    $p->on('GET', "$mc/lists/L1/members", [
        'members' => [
            ['email_address' => 'ann@x.test', 'status' => 'subscribed',
                'merge_fields' => ['FNAME' => 'Ann', 'LNAME' => 'Lee', 'PHONE' => '123'],
                'tags' => [['id' => 1, 'name' => 'gold']], 'interests' => ['i1' => true, 'i2' => false]],
            ['email_address' => 'u1@x.test', 'status' => 'unsubscribed', 'merge_fields' => ['FNAME' => '', 'LNAME' => ''], 'tags' => [], 'interests' => []],
        ],
        'total_items' => 4,
    ], ['count' => 2, 'offset' => 0, 'fields' => $memFields]);
    $p->on('GET', "$mc/lists/L1/members", [
        'members' => [
            ['email_address' => 'c1@x.test', 'status' => 'cleaned', 'merge_fields' => [], 'tags' => []],
            ['email_address' => 'pen@x.test', 'status' => 'pending', 'merge_fields' => [], 'tags' => []],
        ],
        'total_items' => 4,
    ], ['count' => 2, 'offset' => 2, 'fields' => $memFields]);
    $p->on('GET', "$mc/lists/L2/members", [
        'members'     => [['email_address' => 'ann@x.test', 'status' => 'subscribed', 'merge_fields' => ['FNAME' => 'Ann'], 'tags' => []]],
        'total_items' => 1,
    ], ['count' => 2, 'offset' => 0, 'fields' => $memFields]);

    // Templates: three of five sent campaigns, two per page; the second has no HTML
    $campFields = 'campaigns.id,campaigns.settings.subject_line,campaigns.settings.title,total_items';
    $base       = ['status' => 'sent', 'sort_field' => 'send_time', 'sort_dir' => 'DESC', 'fields' => $campFields];
    $p->on('GET', "$mc/campaigns", [
        'campaigns' => [
            ['id' => 'cmp1', 'settings' => ['subject_line' => 'Hello', 'title' => 'May news']],
            ['id' => 'cmp2', 'settings' => ['subject_line' => 'Empty', 'title' => 'Plain text one']],
        ],
        'total_items' => 5,
    ], $base + ['count' => 2, 'offset' => 0]);
    $p->on('GET', "$mc/campaigns", [
        'campaigns'   => [['id' => 'cmp3', 'settings' => ['subject_line' => 'Sale', 'title' => '']]],
        'total_items' => 5,
    ], $base + ['count' => 1, 'offset' => 2]);
    $p->on('GET', "$mc/campaigns/cmp1/content", ['html' => '<p>Hi *|FNAME|*</p>'], ['fields' => 'html']);
    $p->on('GET', "$mc/campaigns/cmp2/content", ['html' => ''], ['fields' => 'html']);
    $p->on('GET', "$mc/campaigns/cmp3/content", ['html' => '<p>Sale</p>'], ['fields' => 'html']);

    return $p;
};

commonChecks('Mailchimp', $make, "$mc/?fields=account_name", 'us21.api.mailchimp.com');

$p = $fill($make());
eq('Mailchimp: account', ['name' => 'Acme Ltd'], $p->account());
ok('Mailchimp: basic auth carries the key', hasHeader($p->log[0], 'Authorization: Basic ' . base64_encode('import:k3y-us21')));
eq('Mailchimp: no extra credential fields', [], $p->getCredentialFields());

$d = $p->discover();
eq('Mailchimp: discover counts', [6, 4, 3], [$d['contacts'], $d['suppressions'], $d['templates']]);
ok('Mailchimp: discover has notes and no lists', count($d['notes']) >= 3 && !array_key_exists('lists', $d));

$r = drain($p, 'suppressions');
eq('Mailchimp: suppressions shape', '', shapesOk('suppressions', $r['items']));
eq('Mailchimp: suppressions across audiences', [
    'u1@x.test unsubscribe', 'u2@x.test unsubscribe', 'u3@x.test unsubscribe', 'c1@x.test bounce', 'a1@x.test complaint',
], byEmail($r['items'], 'reason'));
eq('Mailchimp: suppressions pages', 7, $r['pages']);

$r = drain($p, 'contacts');
eq('Mailchimp: contacts shape', '', shapesOk('contacts', $r['items']));
eq('Mailchimp: contacts status mapping, duplicate kept', [
    'ann@x.test subscribed', 'u1@x.test unsubscribed', 'c1@x.test bounced', 'pen@x.test pending', 'ann@x.test subscribed',
], byEmail($r['items'], 'status'));
eq('Mailchimp: contact names, fields, tags and groups', [
    'email' => 'ann@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'fields' => ['PHONE' => '123'],
    'tags' => ['gold', 'group: VIP'], 'status' => 'subscribed',
], $r['items'][0]);
eq('Mailchimp: contacts pages and total', [3, 6], [$r['pages'], $r['totals'][0]]);

$r = drain($p, 'templates');
eq('Mailchimp: templates shape', '', shapesOk('templates', $r['items']));
eq('Mailchimp: templates, capped and paged', [
    ['name' => 'May news', 'subject' => 'Hello', 'html' => '<p>Hi *|FNAME|*</p>'],
    ['name' => 'Sale', 'subject' => 'Sale', 'html' => '<p>Sale</p>'],
], $r['items']);
eq('Mailchimp: templates pages', 2, $r['pages']);
eq('Mailchimp: every request went to the key\'s data centre', count($p->log), count($p->sentTo('us21.api.mailchimp.com')));

$bad = (new Test_Mailchimp())->setCredentials(['api_key' => 'no-suffix-here']);
try {
    $bad->account();
    ok('Mailchimp: key without data centre refused', false);
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('Mailchimp: key without data centre refused, nothing sent', $bad->log === []);
}

eq(
    'Mailchimp: merge tags',
    'Hi {{customer.firstname}} {{customer.lastname}} {{customer.firstname}} <{{customer.email}}> at {{store.name}} '
        . '<a href="{{unsubscribe_url}}">out</a> *|BIRTHDAY|* *|IF:FNAME|* *|LIST:ADDRESS|*',
    $p->convertMergeTags(
        'Hi *|FNAME|* *|LNAME|* *|TITLE:FNAME|* <*|EMAIL|*> at *|LIST:COMPANY|* '
        . '<a href="*|UNSUB|*">out</a> *|BIRTHDAY|* *|IF:FNAME|* *|LIST:ADDRESS|*'
    )
);

// ---------------------------------------------------------------- Klaviyo

$kl   = 'https://a.klaviyo.com/api';
$make = static function () {
    return (new Test_Klaviyo())->setCredentials(['api_key' => 'pk_test']);
};

$profile = static function (string $email, array $marketing = [], array $more = []) {
    return ['type' => 'profile', 'id' => md5($email), 'attributes' => $more + [
        'email'         => $email,
        'subscriptions' => ['email' => ['marketing' => $marketing]],
    ]];
};

$fill = static function (Test_Klaviyo $p) use ($kl, $profile) {
    $p->on('GET', "$kl/accounts", ['data' => [['attributes' => ['contact_information' => ['organization_name' => 'Acme Shop']]]]]);

    $sup = static function (string $reason) {
        return [
            'additional-fields[profile]' => 'subscriptions',
            'fields[profile]'            => 'email,subscriptions',
            'page[size]'                 => 2,
            'filter'                     => 'equals(subscriptions.email.marketing.suppression.reason,"' . $reason . '")',
        ];
    };
    $p->on('GET', "$kl/profiles", [
        'data'  => [$profile('hb1@x.test'), $profile('hb2@x.test')],
        'links' => ['next' => "$kl/profiles?page%5Bcursor%5D=HB2&page%5Bsize%5D=2"],
    ], $sup('HARD_BOUNCE'));
    $p->on('GET', "$kl/profiles?page%5Bcursor%5D=HB2&page%5Bsize%5D=2", ['data' => [$profile('hb3@x.test')], 'links' => ['next' => null]]);
    $p->on('GET', "$kl/profiles", ['data' => [$profile('inv@x.test')], 'links' => ['next' => null]], $sup('INVALID_EMAIL'));
    $p->on('GET', "$kl/profiles", ['data' => [$profile('spam@x.test')], 'links' => ['next' => null]], $sup('SPAM_COMPLAINT'));
    $p->on('GET', "$kl/profiles", ['data' => [$profile('unsub@x.test')], 'links' => ['next' => null]], $sup('UNSUBSCRIBE'));
    $p->on('GET', "$kl/profiles", ['data' => [], 'links' => ['next' => null]], $sup('USER_SUPPRESSED'));

    $p->on('GET', "$kl/profiles", [
        'data' => [
            $profile('ann@x.test', ['can_receive_email_marketing' => true, 'consent' => 'SUBSCRIBED', 'suppression' => []],
                ['first_name' => 'Ann', 'last_name' => 'Lee', 'properties' => ['plan' => 'gold']]),
            $profile('hb1@x.test', ['can_receive_email_marketing' => false, 'consent' => 'SUBSCRIBED', 'suppression' => [['reason' => 'HARD_BOUNCE']]]),
        ],
        'links' => ['next' => "$kl/profiles?page%5Bcursor%5D=C2"],
    ], ['additional-fields[profile]' => 'subscriptions', 'page[size]' => 2]);
    $p->on('GET', "$kl/profiles?page%5Bcursor%5D=C2", [
        'data' => [
            $profile('spam@x.test', ['can_receive_email_marketing' => false, 'suppression' => [['reason' => 'SPAM_COMPLAINT']]]),
            $profile('unsub@x.test', ['can_receive_email_marketing' => false, 'consent' => 'UNSUBSCRIBED']),
        ],
        'links' => ['next' => "$kl/profiles?page%5Bcursor%5D=C3"],
    ]);
    $p->on('GET', "$kl/profiles?page%5Bcursor%5D=C3", [
        'data'  => [$profile('never@x.test', ['can_receive_email_marketing' => false, 'consent' => 'NEVER_SUBSCRIBED'])],
        'links' => ['next' => null],
    ]);

    $p->on('GET', "$kl/templates", [
        'data'  => [['attributes' => ['name' => 'Welcome', 'html' => '<p>Hi {{ first_name }}</p>']], ['attributes' => ['name' => 'Text only', 'html' => '']]],
        'links' => ['next' => "$kl/templates?page%5Bcursor%5D=T2"],
    ], ['fields[template]' => 'name,html']);
    $p->on('GET', "$kl/templates?page%5Bcursor%5D=T2", ['data' => [['attributes' => ['name' => 'Winback', 'html' => '<p>Back?</p>']]], 'links' => ['next' => null]]);

    return $p;
};

commonChecks('Klaviyo', $make, "$kl/accounts", 'a.klaviyo.com');

$p = $fill($make());
eq('Klaviyo: account', ['name' => 'Acme Shop'], $p->account());
ok('Klaviyo: key and revision headers', hasHeader($p->log[0], 'Authorization: Klaviyo-API-Key pk_test') && hasHeader($p->log[0], 'revision: ' . Test_Klaviyo::REVISION));

$sent = count($p->log);
$d    = $p->discover();
eq('Klaviyo: discover counts unknown', [null, null, null], [$d['contacts'], $d['suppressions'], $d['templates']]);
ok('Klaviyo: discover has notes, no lists, no requests', count($d['notes']) >= 2 && !array_key_exists('lists', $d) && count($p->log) === $sent);

$r = drain($p, 'suppressions');
eq('Klaviyo: suppressions shape', '', shapesOk('suppressions', $r['items']));
eq('Klaviyo: suppressions reason mapping', [
    'hb1@x.test bounce', 'hb2@x.test bounce', 'hb3@x.test bounce', 'inv@x.test bounce', 'spam@x.test complaint', 'unsub@x.test unsubscribe',
], byEmail($r['items'], 'reason'));
eq('Klaviyo: suppressions pages', 6, $r['pages']);

$r = drain($p, 'contacts');
eq('Klaviyo: contacts shape', '', shapesOk('contacts', $r['items']));
eq('Klaviyo: contacts status mapping', [
    'ann@x.test subscribed', 'hb1@x.test bounced', 'spam@x.test complained', 'unsub@x.test unsubscribed', 'never@x.test pending',
], byEmail($r['items'], 'status'));
eq('Klaviyo: contact names and properties', ['Ann', 'Lee', ['plan' => 'gold']], [$r['items'][0]['first_name'], $r['items'][0]['last_name'], $r['items'][0]['fields']]);
eq('Klaviyo: contacts pages', 3, $r['pages']);

$r = drain($p, 'templates');
eq('Klaviyo: templates shape', '', shapesOk('templates', $r['items']));
eq('Klaviyo: templates, empty one skipped', ['Welcome', 'Winback'], array_column($r['items'], 'name'));
eq('Klaviyo: templates pages', 2, $r['pages']);

$evil = $make()->on('GET', "$kl/templates", ['data' => [], 'links' => ['next' => 'https://a.klaviyo.com.evil.example/api/templates?c=2']], ['fields[template]' => 'name,html']);
$page = $evil->fetch('templates', null);
try {
    $evil->fetch('templates', json_decode(json_encode($page['cursor']), true));
    ok('Klaviyo: foreign next link refused', false, 'no exception');
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('Klaviyo: foreign next link refused, key not sent', count($evil->log) === 1 && !$e->isRetryable());
}

eq(
    'Klaviyo: merge tags',
    'Hi {{customer.firstname|there}} {{customer.firstname}} {{customer.lastname}} {{customer.email}} from {{store.name}} '
        . '{{unsubscribe_url}} <a href="{{unsubscribe_url}}">Unsubscribe</a> <a href="{{unsubscribe_url}}">Leave</a> '
        . '{{ person.favourite_colour }} {{ first_name|title }} {% manage_preferences_link %} {% if x %}',
    $p->convertMergeTags(
        "Hi {{ first_name|default:'there' }} {{ person.first_name }} {{last_name}} {{ email }} from {{ organization.name }} "
        . "{% unsubscribe_link %} {% unsubscribe %} {% unsubscribe 'Leave' %} "
        . '{{ person.favourite_colour }} {{ first_name|title }} {% manage_preferences_link %} {% if x %}'
    )
);

// ------------------------------------------------------------------ Brevo

$bv   = 'https://api.brevo.com/v3';
$make = static function () {
    return (new Test_Brevo())->setCredentials(['api_key' => 'xkeysib-test']);
};

$fill = static function (Test_Brevo $p) use ($bv) {
    $p->on('GET', "$bv/account", ['email' => 'owner@acme.test', 'companyName' => 'Acme BV']);
    $p->on('GET', "$bv/contacts", ['contacts' => [], 'count' => 3], ['limit' => 1]);
    $p->on('GET', "$bv/smtp/blockedContacts", ['contacts' => [], 'count' => 5], ['limit' => 1]);
    $p->on('GET', "$bv/smtp/templates", ['templates' => [], 'count' => 3], ['limit' => 1]);

    $blocked = static function (string $email, string $code) {
        return ['email' => $email, 'reason' => ['code' => $code, 'message' => 'x'], 'blockedAt' => '2026-01-01T00:00:00Z'];
    };
    $p->on('GET', "$bv/smtp/blockedContacts", ['contacts' => [$blocked('hb@x.test', 'hardBounce'), $blocked('spam@x.test', 'contactFlaggedAsSpam')], 'count' => 5], ['limit' => 2, 'offset' => 0]);
    $p->on('GET', "$bv/smtp/blockedContacts", ['contacts' => [$blocked('un1@x.test', 'unsubscribedViaEmail'), $blocked('adm@x.test', 'adminBlocked')], 'count' => 5], ['limit' => 2, 'offset' => 2]);
    $p->on('GET', "$bv/smtp/blockedContacts", ['contacts' => [$blocked('odd@x.test', 'somethingNew')], 'count' => 5], ['limit' => 2, 'offset' => 4]);

    $p->on('GET', "$bv/contacts", ['contacts' => [
        ['email' => 'ann@x.test', 'emailBlacklisted' => false, 'listIds' => [2, 5],
            'attributes' => ['FIRSTNAME' => 'Ann', 'LASTNAME' => 'Lee', 'SMS' => '3212345', 'CITY' => 'Ghent']],
        ['email' => 'un1@x.test', 'emailBlacklisted' => true, 'listIds' => [2], 'attributes' => []],
    ], 'count' => 3], ['limit' => 2, 'offset' => 0, 'sort' => 'asc']);
    $p->on('GET', "$bv/contacts", ['contacts' => [
        ['email' => 'bob@x.test', 'emailBlacklisted' => false, 'listIds' => [], 'attributes' => ['FIRSTNAME' => 'Bob']],
    ], 'count' => 3], ['limit' => 2, 'offset' => 2, 'sort' => 'asc']);

    $p->on('GET', "$bv/smtp/templates", ['templates' => [
        ['id' => 1, 'name' => 'Welcome', 'subject' => 'Hi', 'htmlContent' => '<p>Hi {{ contact.FIRSTNAME }}</p>'],
        ['id' => 2, 'name' => 'Receipt', 'subject' => 'Order', 'htmlContent' => '<p>Thanks</p>'],
    ], 'count' => 3], ['limit' => 2, 'offset' => 0]);
    $p->on('GET', "$bv/smtp/templates", ['templates' => [
        ['id' => 3, 'name' => 'Winback', 'subject' => 'Back?', 'htmlContent' => '<p>Back?</p>'],
    ], 'count' => 3], ['limit' => 2, 'offset' => 2]);

    return $p;
};

commonChecks('Brevo', $make, "$bv/account", 'api.brevo.com');

$p = $fill($make());
eq('Brevo: account', ['name' => 'Acme BV'], $p->account());
ok('Brevo: api-key header', hasHeader($p->log[0], 'api-key: xkeysib-test'));

$d = $p->discover();
eq('Brevo: discover counts', [3, 5, 3], [$d['contacts'], $d['suppressions'], $d['templates']]);
ok('Brevo: discover has notes and no lists', count($d['notes']) >= 2 && !array_key_exists('lists', $d));

$r = drain($p, 'suppressions');
eq('Brevo: suppressions shape', '', shapesOk('suppressions', $r['items']));
eq('Brevo: suppressions reason mapping, unknown code is an unsubscribe', [
    'hb@x.test bounce', 'spam@x.test complaint', 'un1@x.test unsubscribe', 'adm@x.test unsubscribe', 'odd@x.test unsubscribe',
], byEmail($r['items'], 'reason'));
eq('Brevo: suppressions pages, total and detail', [3, 5, 'hardBounce'], [$r['pages'], $r['totals'][0], $r['items'][0]['detail']]);

$r = drain($p, 'contacts');
eq('Brevo: contacts shape', '', shapesOk('contacts', $r['items']));
eq('Brevo: contacts status mapping', ['ann@x.test subscribed', 'un1@x.test unsubscribed', 'bob@x.test subscribed'], byEmail($r['items'], 'status'));
eq('Brevo: contact names and fields', [
    'email' => 'ann@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'fields' => ['CITY' => 'Ghent'], 'tags' => [], 'status' => 'subscribed',
], $r['items'][0]);
eq('Brevo: contacts pages', 2, $r['pages']);

$r = drain($p, 'templates');
eq('Brevo: templates shape', '', shapesOk('templates', $r['items']));
eq('Brevo: templates', ['Welcome', 'Receipt', 'Winback'], array_column($r['items'], 'name'));
eq('Brevo: templates pages and subject', [2, 'Hi'], [$r['pages'], $r['items'][0]['subject']]);

eq(
    'Brevo: merge tags',
    'Hi {{customer.firstname|friend}} {{customer.firstname}} {{customer.lastname}} {{customer.email}} {{unsubscribe_url}} {{ contact.CITY }} {{ params.total }} {{ mirror }}',
    $p->convertMergeTags(
        'Hi {{ contact.FIRSTNAME | default: "friend" }} {{contact.FIRSTNAME}} {{ contact.LASTNAME }} {{ contact.EMAIL }} {{ unsubscribe }} {{ contact.CITY }} {{ params.total }} {{ mirror }}'
    )
);

// -------------------------------------------------------------------- Kit

$kit  = 'https://api.kit.com/v4';
$make = static function () {
    return (new Test_Kit())->setCredentials(['api_key' => 'kit_test']);
};

$fill = static function ($p) use ($kit) {
    $page = static function (array $rows, ?string $end, string $key = 'subscribers') {
        return [$key => $rows, 'pagination' => ['has_previous_page' => false, 'has_next_page' => $end !== null, 'start_cursor' => 'S', 'end_cursor' => $end ?? 'LAST', 'per_page' => 2]];
    };
    $sub = static function (string $email, string $state, array $more = []) {
        return $more + ['id' => crc32($email), 'first_name' => null, 'email_address' => $email, 'state' => $state, 'fields' => []];
    };

    $p->on('GET', "$kit/account", ['user' => ['email' => 'me@acme.test'], 'account' => ['name' => 'Acme Letters', 'plan_type' => 'creator']]);
    $p->on('GET', "$kit/subscribers", ['subscribers' => [], 'pagination' => ['has_next_page' => true, 'end_cursor' => 'x', 'total_count' => 7]],
        ['status' => 'all', 'per_page' => 1, 'include_total_count' => 'true']);

    $p->on('GET', "$kit/subscribers", $page([$sub('can1@x.test', 'cancelled'), $sub('can2@x.test', 'cancelled')], 'CAN2'), ['status' => 'cancelled', 'per_page' => 2]);
    $p->on('GET', "$kit/subscribers", $page([$sub('can3@x.test', 'cancelled')], null), ['status' => 'cancelled', 'per_page' => 2, 'after' => 'CAN2']);
    $p->on('GET', "$kit/subscribers", $page([$sub('bnc@x.test', 'bounced')], null), ['status' => 'bounced', 'per_page' => 2]);
    $p->on('GET', "$kit/subscribers", $page([$sub('cmp@x.test', 'complained')], null), ['status' => 'complained', 'per_page' => 2]);

    $p->on('GET', "$kit/subscribers", $page([
        $sub('ann@x.test', 'active', ['first_name' => 'Ann', 'fields' => ['last_name' => 'Lee', 'city' => 'Ghent'], 'tags' => [['id' => 1, 'name' => 'reader']]]),
        $sub('can1@x.test', 'cancelled'),
    ], 'ALL2'), ['status' => 'all', 'per_page' => 2, 'include' => 'tags']);
    $p->on('GET', "$kit/subscribers", $page([$sub('bnc@x.test', 'bounced'), $sub('cmp@x.test', 'complained')], 'ALL3'), ['status' => 'all', 'per_page' => 2, 'include' => 'tags', 'after' => 'ALL2']);
    $p->on('GET', "$kit/subscribers", $page([$sub('new@x.test', 'inactive')], null), ['status' => 'all', 'per_page' => 2, 'include' => 'tags', 'after' => 'ALL3']);

    $p->on('GET', "$kit/broadcasts", $page([
        ['id' => 11, 'subject' => 'Issue 1', 'content' => '<p>One</p>'],
        ['id' => 12, 'subject' => 'Issue 2', 'content' => null],
    ], 'B2', 'broadcasts'), ['per_page' => 2]);
    $p->on('GET', "$kit/broadcasts/12", ['broadcast' => ['id' => 12, 'subject' => 'Issue 2', 'content' => '<p>Two</p>']]);
    $p->on('GET', "$kit/broadcasts", $page([['id' => 13, 'subject' => '', 'content' => '<p>Three</p>']], null, 'broadcasts'), ['per_page' => 2, 'after' => 'B2']);

    return $p;
};

commonChecks('Kit', $make, "$kit/account", 'api.kit.com');

$p = $fill($make());
eq('Kit: account', ['name' => 'Acme Letters'], $p->account());
ok('Kit: key header', hasHeader($p->log[0], 'X-Kit-Api-Key: kit_test'));

$d = $p->discover();
eq('Kit: discover counts', [7, null, null], [$d['contacts'], $d['suppressions'], $d['templates']]);
ok('Kit: discover has notes and no lists', count($d['notes']) >= 2 && !array_key_exists('lists', $d));

$r = drain($p, 'suppressions');
eq('Kit: suppressions shape', '', shapesOk('suppressions', $r['items']));
eq('Kit: suppressions reason mapping', [
    'can1@x.test unsubscribe', 'can2@x.test unsubscribe', 'can3@x.test unsubscribe', 'bnc@x.test bounce', 'cmp@x.test complaint',
], byEmail($r['items'], 'reason'));
eq('Kit: suppressions pages', 4, $r['pages']);

$r = drain($p, 'contacts');
eq('Kit: contacts shape', '', shapesOk('contacts', $r['items']));
eq('Kit: contacts status mapping', [
    'ann@x.test subscribed', 'can1@x.test unsubscribed', 'bnc@x.test bounced', 'cmp@x.test complained', 'new@x.test pending',
], byEmail($r['items'], 'status'));
eq('Kit: contact names, fields and tags', [
    'email' => 'ann@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'fields' => ['city' => 'Ghent'], 'tags' => ['reader'], 'status' => 'subscribed',
], $r['items'][0]);
eq('Kit: contacts pages', 3, $r['pages']);

$r = drain($p, 'templates');
eq('Kit: templates shape', '', shapesOk('templates', $r['items']));
eq('Kit: templates, content fetched when the list omits it', [
    ['name' => 'Issue 1', 'subject' => 'Issue 1', 'html' => '<p>One</p>'],
    ['name' => 'Issue 2', 'subject' => 'Issue 2', 'html' => '<p>Two</p>'],
    ['name' => 'Kit broadcast', 'subject' => '', 'html' => '<p>Three</p>'],
], $r['items']);
eq('Kit: templates pages', 2, $r['pages']);

$capped = $fill((new Test_KitCapped())->setCredentials(['api_key' => 'kit_test']));
$r      = drain($capped, 'templates');
eq('Kit: broadcast cap ends the stage', [1, 2], [$r['pages'], count($r['items'])]);

eq(
    'Kit: merge tags',
    'Hi {{customer.firstname|there}} {{customer.firstname}} {{customer.email}} {{unsubscribe_url}} <a href="{{unsubscribe_url}}">Unsubscribe</a> {{ subscriber.city }} {% if x %}',
    $p->convertMergeTags(
        'Hi {{ subscriber.first_name | default: "there" }} {{subscriber.first_name}} {{ subscriber.email_address }} {{ unsubscribe_url }} {{ unsubscribe_link }} {{ subscriber.city }} {% if x %}'
    )
);

// --------------------------------------------------------------- SendGrid

$sg    = 'https://api.sendgrid.com/v3';
$files = 'https://sendgrid-exports.s3.example-storage.com';
$tmp   = sys_get_temp_dir() . '/ysrtech-providers-test-' . getmypid();
$make  = static function () use ($tmp) {
    $p      = (new Test_Sendgrid())->setCredentials(['api_key' => 'SG.test']);
    $p->dir = $tmp;

    return $p;
};

$polls = 0;
$fill  = static function (Test_Sendgrid $p) use ($sg, $files, &$polls) {
    $p->on('GET', "$sg/user/profile", ['first_name' => 'Ann', 'last_name' => 'Lee', 'company' => '']);
    $p->on('GET', "$sg/marketing/contacts/count", ['contact_count' => 4, 'billable_count' => 4]);

    $row = static function (string $email) {
        return ['created' => 1700000000, 'email' => $email, 'reason' => '550 no such user', 'status' => '5.1.1'];
    };
    $p->on('GET', "$sg/suppression/bounces", [$row('b1@x.test'), $row('b2@x.test')], ['limit' => 2, 'offset' => 0]);
    $p->on('GET', "$sg/suppression/bounces", [$row('b3@x.test')], ['limit' => 2, 'offset' => 2]);
    $p->on('GET', "$sg/suppression/spam_reports", [$row('spam@x.test')], ['limit' => 2, 'offset' => 0]);
    $p->on('GET', "$sg/suppression/unsubscribes", [], ['limit' => 2, 'offset' => 0]);
    $p->on('GET', "$sg/suppression/invalid_emails", [$row('inv@x.test')], ['limit' => 2, 'offset' => 0]);
    $p->on('GET', "$sg/asm/suppressions", [
        ['email' => 'g1@x.test', 'group_id' => 1, 'group_name' => 'Offers', 'created_at' => 1],
        ['email' => 'g2@x.test', 'group_id' => 1, 'group_name' => 'Offers', 'created_at' => 1],
        ['email' => 'g3@x.test', 'group_id' => 2, 'group_name' => 'News', 'created_at' => 1],
    ]);

    // The export: not ready the first time it is asked about, then two files in two formats
    $p->on('POST', "$sg/marketing/contacts/exports", ['id' => 'exp-1_a', '_metadata' => ['self' => 'x']]);
    $p->on('GET', "$sg/marketing/contacts/exports/exp-1_a", static function () use (&$polls, $files) {
        return ++$polls === 1
            ? ['id' => 'exp-1_a', 'status' => 'pending']
            : ['id' => 'exp-1_a', 'status' => 'ready', 'contact_count' => 4, 'urls' => ["$files/a.json.gz?X-Amz-Signature=abc", "$files/b.csv?X-Amz-Signature=def"]];
    });
    $jsonl = implode("\n", [
        json_encode(['email' => 'ann@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'city' => 'Ghent', 'list_ids' => ['l1'], 'custom_fields' => ['plan' => 'gold']]),
        json_encode(['email' => 'bob@x.test', 'first_name' => '', 'last_name' => '', 'contact_id' => 'c2']),
        json_encode(['email' => 'cy@x.test', 'first_name' => 'Cy']),
    ]);
    $p->on('GET', "$files/a.json.gz?X-Amz-Signature=abc", ['status' => 200, 'body' => gzencode($jsonl)]);
    $p->on('GET', "$files/b.csv?X-Amz-Signature=def", ['status' => 200, 'body' => "\xEF\xBB\xBFEMAIL,FIRST_NAME,LAST_NAME,shoe_size\r\ndee@x.test,Dee,\"Van, Jr\",42\r\n"]);

    $p->on('GET', "$sg/templates", ['result' => [['id' => 'd-1', 'name' => 'Welcome'], ['id' => 'd-2', 'name' => 'Draft'], ['id' => 'd-3', 'name' => 'Receipt']]],
        ['generations' => 'dynamic', 'page_size' => 200]);
    $p->on('GET', "$sg/templates", [
        'result'    => [['id' => 'd-1', 'name' => 'Welcome'], ['id' => 'd-2', 'name' => 'Draft']],
        '_metadata' => ['self' => 'x', 'next' => "$sg/templates?generations=dynamic&page_size=2&page_token=TOK%3D2", 'count' => 3],
    ], ['generations' => 'dynamic', 'page_size' => 2]);
    $p->on('GET', "$sg/templates", ['result' => [['id' => 'd-3', 'name' => 'Receipt']], '_metadata' => ['self' => 'x', 'count' => 3]],
        ['generations' => 'dynamic', 'page_size' => 2, 'page_token' => 'TOK=2']);
    $p->on('GET', "$sg/templates/d-1", ['id' => 'd-1', 'name' => 'Welcome', 'versions' => [
        ['id' => 'v1', 'active' => 0, 'subject' => 'Old', 'html_content' => '<p>Old</p>'],
        ['id' => 'v2', 'active' => 1, 'subject' => 'Hi {{first_name}}', 'html_content' => '<p>New</p>'],
    ]]);
    $p->on('GET', "$sg/templates/d-2", ['id' => 'd-2', 'name' => 'Draft', 'versions' => []]);
    $p->on('GET', "$sg/templates/d-3", ['id' => 'd-3', 'name' => 'Receipt', 'versions' => [['id' => 'v1', 'active' => 0, 'subject' => 'Order', 'html_content' => '<p>Thanks</p>']]]);

    return $p;
};

commonChecks('SendGrid', $make, "$sg/user/profile", 'api.sendgrid.com');

$p = $fill($make());
eq('SendGrid: account falls back to the person\'s name', ['name' => 'Ann Lee'], $p->account());
ok('SendGrid: bearer header', hasHeader($p->log[0], 'Authorization: Bearer SG.test'));
eq('SendGrid: only an optional region is asked for', ['region'], array_keys(array_filter($p->getCredentialFields(), static function ($f) { return empty($f['required']); })));

$eu = (new Test_Sendgrid())->setCredentials(['api_key' => 'SG.test', 'region' => 'EU'])->on('GET', 'https://api.eu.sendgrid.com/v3/user/profile', ['company' => 'Acme EU']);
eq('SendGrid: eu region uses the eu host', ['name' => 'Acme EU'], $eu->account());

$d = $p->discover();
eq('SendGrid: discover counts', [4, null, 3], [$d['contacts'], $d['suppressions'], $d['templates']]);
ok('SendGrid: discover has notes and no lists', count($d['notes']) === 3 && !array_key_exists('lists', $d));

$noMarketing = $fill($make())->on('GET', "$sg/marketing/contacts/count", ['status' => 403, 'body' => '{"errors":[{"message":"access forbidden"}]}']);
$d           = $noMarketing->discover();
ok('SendGrid: discover survives a key without Marketing access', $d['contacts'] === null && count($d['notes']) === 4 && $d['templates'] === 3);

$r = drain($p, 'suppressions');
eq('SendGrid: suppressions shape', '', shapesOk('suppressions', $r['items']));
eq('SendGrid: suppressions reason mapping', [
    'b1@x.test bounce', 'b2@x.test bounce', 'b3@x.test bounce', 'spam@x.test complaint', 'inv@x.test bounce',
    'g1@x.test unsubscribe', 'g2@x.test unsubscribe', 'g3@x.test unsubscribe',
], byEmail($r['items'], 'reason'));
eq('SendGrid: suppressions pages and group detail', [7, 'group: News'], [$r['pages'], $r['items'][7]['detail']]);

$r = drain($p, 'contacts');
eq('SendGrid: contacts shape', '', shapesOk('contacts', $r['items']));
eq('SendGrid: contacts from gzip JSON lines and CSV', [
    'ann@x.test subscribed', 'bob@x.test subscribed', 'cy@x.test subscribed', 'dee@x.test subscribed',
], byEmail($r['items'], 'status'));
eq('SendGrid: contact names and custom fields', [
    'email' => 'ann@x.test', 'first_name' => 'Ann', 'last_name' => 'Lee', 'fields' => ['plan' => 'gold', 'city' => 'Ghent'], 'tags' => [], 'status' => 'subscribed',
], $r['items'][0]);
eq('SendGrid: CSV row', ['Dee', 'Van, Jr', ['shoe_size' => '42']], [$r['items'][3]['first_name'], $r['items'][3]['last_name'], $r['items'][3]['fields']]);
eq('SendGrid: export waited for once, total reported', [1, [4]], [$r['retries'], $r['totals']]);
ok('SendGrid: contacts took start, download, and 2+ read pages', $r['pages'] >= 5, (string) $r['pages']);
ok('SendGrid: export files removed afterwards', glob($tmp . '/*') === [], json_encode(glob($tmp . '/*')));

$downloads = $p->sentTo('sendgrid-exports.s3.example-storage.com');
$leaked    = array_filter($downloads, static function ($entry) { return (bool) preg_grep('/authorization|SG\.test/i', $entry['headers']); });
ok('SendGrid: export downloads carry no key', count($downloads) === 2 && !$leaked);

// Files lost between pages (var/ cleared, another node): fetched again, position kept
$polls = 5;
$lost  = $fill($make());
$page  = $lost->fetch('contacts', ['export' => 'exp-1_a', 'n' => 2, 'f' => 0, 'line' => 2]);
eq('SendGrid: missing export file is downloaded again', ['cy@x.test'], array_column($page['items'], 'email'));
array_map('unlink', glob($tmp . '/*'));

$pending = $fill($make())->on('GET', "$sg/marketing/contacts/exports/exp-1_a", ['id' => 'exp-1_a', 'status' => 'pending']);
try {
    $pending->fetch('contacts', ['export' => 'exp-1_a']);
    ok('SendGrid: export not ready is retryable', false, 'no exception');
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('SendGrid: export not ready is retryable', $e->isRetryable() && $e->getRetryAfter() === 30);
}

$failed = $fill($make())->on('GET', "$sg/marketing/contacts/exports/exp-1_a", ['id' => 'exp-1_a', 'status' => 'failure', 'message' => 'boom']);
try {
    $failed->fetch('contacts', ['export' => 'exp-1_a']);
    ok('SendGrid: failed export stops the import', false, 'no exception');
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('SendGrid: failed export stops the import', !$e->isRetryable() && strpos($e->getMessage(), 'boom') !== false);
}

$plain = $fill($make())->on('GET', "$sg/marketing/contacts/exports/exp-1_a", ['status' => 'ready', 'urls' => ['http://files.example.com/a.json']]);
try {
    $plain->fetch('contacts', ['export' => 'exp-1_a']);
    ok('SendGrid: non-https export link refused', false, 'no exception');
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('SendGrid: non-https export link refused', count($plain->log) === 1);
}

try {
    $fill($make())->fetch('contacts', ['export' => '../../etc/passwd', 'n' => 1, 'f' => 0, 'line' => 0]);
    ok('SendGrid: export id that is not an id refused', false, 'no exception');
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('SendGrid: export id that is not an id refused', true);
}

$r = drain($p, 'templates');
eq('SendGrid: templates shape', '', shapesOk('templates', $r['items']));
eq('SendGrid: templates, active version wins, empty one skipped', [
    ['name' => 'Welcome', 'subject' => 'Hi {{first_name}}', 'html' => '<p>New</p>'],
    ['name' => 'Receipt', 'subject' => 'Order', 'html' => '<p>Thanks</p>'],
], $r['items']);
eq('SendGrid: templates pages', 2, $r['pages']);

$evil = $make()->on('GET', "$sg/templates", ['result' => [], '_metadata' => ['next' => 'https://evil.example.com/v3/templates?page_token=EVIL']], ['generations' => 'dynamic', 'page_size' => 2])
    ->on('GET', "$sg/templates", ['result' => []], ['generations' => 'dynamic', 'page_size' => 2, 'page_token' => 'EVIL']);
$page = $evil->fetch('templates', null);
$evil->fetch('templates', json_decode(json_encode($page['cursor']), true));
ok('SendGrid: foreign next link is never followed, only its token used', count($evil->sentTo('api.sendgrid.com')) === 2 && count($evil->log) === 2);

eq(
    'SendGrid: merge tags',
    'Hi {{customer.firstname|there}} {{customer.firstname}} {{customer.lastname}} {{customer.email}} from {{store.name}} '
        . '{{unsubscribe_url}} {{unsubscribe_url}} {{unsubscribe_url}} {{shoe_size}} {{{unsubscribe_preferences}}} {{#if first_name}}',
    $p->convertMergeTags(
        'Hi {{insert first_name "default=there"}} {{first_name}} {{{last_name}}} {{ email }} from {{Sender_Name}} '
        . '{{{unsubscribe}}} <%asm_group_unsubscribe_raw_url%> <%asm_global_unsubscribe_raw_url%> {{shoe_size}} {{{unsubscribe_preferences}}} {{#if first_name}}'
    )
);
@rmdir($tmp);

// ---------------------------------------------------------------- Mailgun

$mg   = 'https://api.eu.mailgun.net';
$dom  = 'mg.acme.test';
$make = static function () use ($dom) {
    return (new Test_Mailgun())->setCredentials(['api_key' => 'key-test', 'domain' => ' MG.Acme.test ', 'region' => 'EU']);
};

$fill = static function (Test_Mailgun $p) use ($mg, $dom) {
    $p->on('GET', "$mg/v4/domains", ['total_count' => 2, 'items' => [['name' => 'other.test'], ['name' => $dom]]], ['limit' => 1000]);
    $p->on('GET', "$mg/v3/lists", ['total_count' => 2, 'items' => [
        ['address' => 'news@mg.acme.test', 'name' => 'News', 'members_count' => 3],
        ['address' => 'vip@mg.acme.test', 'name' => 'VIP', 'members_count' => 1],
    ]], ['limit' => 2, 'skip' => 0]);
    $p->on('GET', "$mg/v3/lists", ['total_count' => 2, 'items' => []], ['limit' => 2, 'skip' => 2]);
    $p->on('GET', "$mg/v3/$dom/templates", ['items' => [['name' => 'welcome'], ['name' => 'receipt'], ['name' => 'empty']]], ['limit' => 100]);

    // Mailgun's last page is an empty one that still carries a next link
    $paged = static function (string $first, array $firstQuery, array $pages) use ($p) {
        $url   = $first;
        $query = $firstQuery;

        foreach (array_values($pages) as $i => $items) {
            $next = $first . '?page=next&p=' . ($i + 1);
            $p->on('GET', $url, ['items' => $items, 'paging' => ['first' => $first, 'next' => $next, 'previous' => $first, 'last' => $first]], $query);
            $url   = $next;
            $query = [];
        }
    };

    $paged("$mg/v3/$dom/bounces", ['limit' => 2], [
        [['address' => 'b1@x.test', 'code' => '550', 'error' => 'no'], ['address' => 'b2@x.test', 'code' => 554]],
        [['address' => 'b3@x.test', 'code' => '550']],
        [],
    ]);
    $paged("$mg/v3/$dom/complaints", ['limit' => 2], [[['address' => 'spam@x.test']], []]);
    $paged("$mg/v3/$dom/unsubscribes", ['limit' => 2], [[['address' => 'un@x.test', 'tags' => ['*']]], []]);

    $paged("$mg/v3/lists/" . rawurlencode('news@mg.acme.test') . '/members/pages', ['limit' => 2], [
        [
            ['address' => 'ann@x.test', 'name' => 'Ann van der Lee', 'subscribed' => true, 'vars' => ['city' => 'Ghent']],
            ['address' => 'un@x.test', 'name' => '', 'subscribed' => false, 'vars' => []],
        ],
        [['address' => 'bob@x.test', 'name' => 'Bob', 'subscribed' => true]],
        [],
    ]);
    $paged("$mg/v3/lists/" . rawurlencode('vip@mg.acme.test') . '/members/pages', ['limit' => 2], [
        [['address' => 'ann@x.test', 'name' => 'Ann van der Lee', 'subscribed' => true, 'vars' => []]],
        [],
    ]);

    $paged("$mg/v3/$dom/templates", ['limit' => 2], [[['name' => 'welcome'], ['name' => 'receipt']], [['name' => 'empty']], []]);
    $p->on('GET', "$mg/v3/$dom/templates/welcome", ['template' => ['name' => 'welcome', 'version' => ['tag' => 'v2', 'template' => '<p>Hi %recipient_fname%</p>', 'headers' => ['Subject' => 'Welcome!']]]], ['active' => 'yes']);
    $p->on('GET', "$mg/v3/$dom/templates/receipt", ['template' => ['name' => 'receipt', 'version' => ['tag' => 'v1', 'template' => '<p>Thanks</p>']]], ['active' => 'yes']);
    $p->on('GET', "$mg/v3/$dom/templates/empty", ['template' => ['name' => 'empty']], ['active' => 'yes']);

    return $p;
};

commonChecks('Mailgun', $make, "$mg/v4/domains?limit=1000", 'api.eu.mailgun.net');

$p = $fill($make());
eq('Mailgun: account is the domain', ['name' => $dom], $p->account());
ok('Mailgun: basic auth is api:<key>', hasHeader($p->log[0], 'Authorization: Basic ' . base64_encode('api:key-test')));

$fields = $p->getCredentialFields();
ok('Mailgun: asks for a required domain and a region', !empty($fields['domain']['required']) && isset($fields['region']) && empty($fields['region']['required']));

$us = (new Test_Mailgun())->setCredentials(['api_key' => 'key-test', 'domain' => $dom])
    ->on('GET', 'https://api.mailgun.net/v4/domains', ['items' => [['name' => $dom]]], ['limit' => 1000]);
eq('Mailgun: no region means the US host', ['name' => $dom], $us->account());

foreach ([
    'region other than us/eu refused' => ['api_key' => 'key-test', 'domain' => $dom, 'region' => 'eu.evil.example'],
    'missing domain refused'          => ['api_key' => 'key-test', 'region' => 'us'],
    'domain with a path refused'      => ['api_key' => 'key-test', 'domain' => 'a.test/../../v3/lists'],
] as $label => $credentials) {
    $bad = (new Test_Mailgun())->setCredentials($credentials);
    try {
        $bad->account();
        ok("Mailgun: $label", false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok("Mailgun: $label, nothing sent", $bad->log === [] && !$e->isRetryable());
    }
}

$wrong = (new Test_Mailgun())->setCredentials(['api_key' => 'key-test', 'domain' => 'nothere.test', 'region' => 'eu'])
    ->on('GET', "$mg/v4/domains", ['items' => [['name' => $dom]]], ['limit' => 1000]);
try {
    $wrong->account();
    ok('Mailgun: domain not in the account refused', false, 'no exception');
} catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
    ok('Mailgun: domain not in the account refused', strpos($e->getMessage(), 'nothere.test') !== false);
}

$d = $p->discover();
eq('Mailgun: discover counts', [4, null, 3], [$d['contacts'], $d['suppressions'], $d['templates']]);
ok('Mailgun: discover has notes and no lists', count($d['notes']) >= 2 && !array_key_exists('lists', $d));

$r = drain($p, 'suppressions');
eq('Mailgun: suppressions shape', '', shapesOk('suppressions', $r['items']));
eq('Mailgun: suppressions reason mapping', [
    'b1@x.test bounce', 'b2@x.test bounce', 'b3@x.test bounce', 'spam@x.test complaint', 'un@x.test unsubscribe',
], byEmail($r['items'], 'reason'));
eq('Mailgun: suppressions detail', ['550', '554', 'complaints'], [$r['items'][0]['detail'], $r['items'][1]['detail'], $r['items'][3]['detail']]);
eq('Mailgun: suppressions pages', 7, $r['pages']);

$r = drain($p, 'contacts');
eq('Mailgun: contacts shape', '', shapesOk('contacts', $r['items']));
eq('Mailgun: contacts across lists, duplicate kept', [
    'ann@x.test subscribed', 'un@x.test unsubscribed', 'bob@x.test subscribed', 'ann@x.test subscribed',
], byEmail($r['items'], 'status'));
eq('Mailgun: name split in two', [
    'email' => 'ann@x.test', 'first_name' => 'Ann', 'last_name' => 'van der Lee', 'fields' => ['city' => 'Ghent'], 'tags' => [], 'status' => 'subscribed',
], $r['items'][0]);
eq('Mailgun: one-word and empty names', [['Bob', null], [null, null]], [
    [$r['items'][2]['first_name'], $r['items'][2]['last_name']], [$r['items'][1]['first_name'], $r['items'][1]['last_name']],
]);
eq('Mailgun: contacts pages', 5, $r['pages']);

$r = drain($p, 'templates');
eq('Mailgun: templates shape', '', shapesOk('templates', $r['items']));
eq('Mailgun: templates, one without content skipped', [
    ['name' => 'welcome', 'subject' => 'Welcome!', 'html' => '<p>Hi %recipient_fname%</p>'],
    ['name' => 'receipt', 'subject' => '', 'html' => '<p>Thanks</p>'],
], $r['items']);
eq('Mailgun: templates pages', 3, $r['pages']);
eq('Mailgun: every request went to the EU host', count($p->log), count($p->sentTo('api.eu.mailgun.net')));

// A US link handed to an EU import is another host too
foreach (['https://evil.example.com/v3/x/bounces?page=next', 'https://api.mailgun.net/v3/' . $dom . '/bounces?page=next'] as $i => $link) {
    $evil = $make()->on('GET', "$mg/v3/$dom/bounces", ['items' => [['address' => 'b1@x.test', 'code' => '550']], 'paging' => ['next' => $link]], ['limit' => 2]);
    $page = $evil->fetch('suppressions', null);
    try {
        $evil->fetch('suppressions', json_decode(json_encode($page['cursor']), true));
        ok('Mailgun: foreign next link refused #' . ($i + 1), false, 'no exception');
    } catch (YSRTech_EmailCampaigns_Model_Import_Provider_Exception $e) {
        ok('Mailgun: foreign next link refused #' . ($i + 1) . ', key not sent', count($evil->log) === 1 && !$e->isRetryable());
    }
}

eq(
    'Mailgun: merge tags',
    'Hi {{customer.firstname}} {{customer.lastname}} {{customer.firstname}} <{{customer.email}}> {{customer.email}} '
        . '{{unsubscribe_url}} {{unsubscribe_url}} %recipient.city% %recipient_name%',
    $p->convertMergeTags(
        'Hi %recipient_fname% %recipient_lname% %recipient.first_name% <%recipient_email%> %recipient% '
        . '%unsubscribe_url% %mailing_list_unsubscribe_url% %recipient.city% %recipient_name%'
    )
);

// ---------------------------------------------------------------- summary

echo "\n" . $GLOBALS['checks']['pass'] . ' passed, ' . $GLOBALS['checks']['fail'] . " failed\n";
exit($GLOBALS['checks']['fail'] ? 1 : 0);
