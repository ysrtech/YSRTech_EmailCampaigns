<?php
/**
 * The AI features and the sender check, with the provider, DNS and Mailgun
 * all stood in for: nothing here leaves the machine.
 *
 *   cd <openmage-core> && php <module>/dev/tests/ai.php
 */
require_once 'app/Mage.php';
Mage::app('admin');
Mage::getSingleton('customer/session'); // a product block asks it for the customer group; start it before any output

$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$h    = Mage::helper('ysrtech_emailcampaigns');
$r    = Mage::getSingleton('core/resource');
$db   = $r->getConnection('core_write');
$log  = $r->getTableName('ysrtech_emailcampaigns/ai_usage');

$store  = Mage::app()->getStore(Mage_Core_Model_App::ADMIN_STORE_ID);
$before = [];
$set    = function (string $field, $value) use ($store, &$before) {
    $path = 'ysrtech_emailcampaigns/' . $field;
    $before[$path] = $before[$path] ?? $store->getConfig($path);
    $store->setConfig($path, $value);
};
$throws = function (callable $work): string {
    try { $work(); } catch (Mage_Core_Exception $e) { return $e->getMessage(); }
    return '';
};

/** @var YSRTech_EmailCampaigns_Model_Ai_Client $client */
$client = Mage::getSingleton('ysrtech_emailcampaigns/ai_client');
$sent   = [];
$reply  = fn (string $content, int $status = 200) => ['status' => $status, 'body' => json_encode($status === 200
    ? ['model' => 'test/model-1', 'choices' => [['message' => ['content' => $content]]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40, 'cost' => 0.0012]]
    : ['error' => ['message' => $content]])];
$queue  = [];
$client->setTransport(function (string $url, array $headers, ?string $body) use (&$sent, &$queue, $reply) {
    $sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];

    return $queue ? array_shift($queue) : $reply('ready');
});

$db->delete($log);

// ---------------------------------------------------------------- client
$set('ai/enabled', 0);
$ok('off: says so, calls nothing', strpos($throws(fn () => $client->chat('t', [])), 'switched off') !== false && !$sent);

$set('ai/enabled', 1);
$set('ai/api_key', '');
$set('ai/model', 'test/model-1');
$ok('no key: says so, calls nothing', strpos($throws(fn () => $client->chat('t', [])), 'not set up') !== false && !$sent);

$set('ai/api_key', 'sk-test-0000');
$set('ai/provider', 'openrouter');
$set('ai/max_calls_per_hour', 50);
$ok('configured', $client->configured());

$out = $client->chat('test', [['role' => 'user', 'content' => 'hi']], ['json' => true]);
$ok('reply text and usage come back', $out['text'] === 'ready' && $out['usage'] === ['prompt' => 120, 'completion' => 40, 'cost' => 0.0012]);
$ok('goes to OpenRouter with the key as a bearer', $sent[0]['url'] === 'https://openrouter.ai/api/v1/chat/completions' && in_array('Authorization: Bearer sk-test-0000', $sent[0]['headers'], true));
$ok('asks for a JSON object and the cost', ($sent[0]['body']['response_format']['type'] ?? '') === 'json_object' && !empty($sent[0]['body']['usage']['include']));
$ok('no store address in the headers', !preg_grep('/Referer|example|127\.0\.0\.1|localhost/i', $sent[0]['headers']));

$row = $db->fetchRow("SELECT * FROM {$log} ORDER BY usage_id DESC LIMIT 1");
$ok('call is logged with tokens and cost', $row['feature'] === 'test' && (int) $row['ok'] === 1 && (int) $row['prompt_tokens'] === 120 && abs($row['cost_usd'] - 0.0012) < 1e-9);
$ok('the key is never logged', strpos(json_encode($db->fetchAll("SELECT * FROM {$log}")), 'sk-test') === false);

$set('ai/provider', 'openai');
$sent = [];
$client->chat('test', [['role' => 'user', 'content' => 'hi']]);
$ok('OpenAI: its endpoint, no OpenRouter extras', $sent[0]['url'] === 'https://api.openai.com/v1/chat/completions' && !isset($sent[0]['body']['usage']));
$set('ai/provider', 'openrouter');

$queue = [$reply('Invalid key', 401)];
$ok('401 reads as a key problem', strpos($throws(fn () => $client->chat('test', [])), 'refused the key') !== false);
$queue = [$reply('No such model', 404)];
$ok('404 points at the model name', strpos($throws(fn () => $client->chat('test', [])), 'model name') !== false);
$ok('failures are logged as failures', (int) $db->fetchOne("SELECT COUNT(*) FROM {$log} WHERE ok = 0") === 2);

$set('ai/max_calls_per_hour', 3);
$count = count($sent);
$ok('hourly cap refuses before calling', strpos($throws(fn () => $client->chat('test', [])), 'hourly limit') !== false && count($sent) === $count);
$set('ai/max_calls_per_hour', 500);

$real = Mage::getModel('ysrtech_emailcampaigns/ai_client');
$ok('decodeObject digs JSON out of a fence', $real::decodeObject("Here you go:\n```json\n{\"a\": 1}\n```") === ['a' => 1]);

// ------------------------------------------------------ model selector
$C      = YSRTech_EmailCampaigns_Model_Ai_Client::class;
$text   = ['output_modalities' => ['text']];
$models = [
    ['id' => 'anthropic/claude-sonnet-5.0', 'created' => 100, 'architecture' => $text, 'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015']],
    ['id' => 'anthropic/claude-sonnet-5.5', 'created' => 300, 'architecture' => $text, 'pricing' => ['prompt' => '0.000002', 'completion' => '0.00001']],
    ['id' => 'anthropic/claude-sonnet-5.5:batch', 'created' => 301, 'architecture' => $text, 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000005']],
    ['id' => 'anthropic/claude-haiku-5.5', 'created' => 310, 'architecture' => $text, 'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000005']],
    ['id' => 'openai/gpt-5-image-mini', 'created' => 400, 'architecture' => ['output_modalities' => ['image', 'text']], 'pricing' => ['prompt' => '0.000002', 'completion' => '0.000002']],
    ['id' => 'openai/gpt-5.4-mini', 'created' => 200, 'architecture' => $text, 'pricing' => ['prompt' => '0.00000075', 'completion' => '0.0000045']],
    ['id' => 'some/free-model:free', 'created' => 500, 'architecture' => $text, 'pricing' => ['prompt' => '0', 'completion' => '0']],
    ['id' => 'some/router', 'created' => 1, 'architecture' => $text, 'pricing' => ['prompt' => '-1', 'completion' => '-1']],
    ['id' => '<script>alert(1)</script>', 'created' => 600, 'architecture' => $text],
    'not a model',
];
$list = $C::arrangeModels($models, 'openrouter');
$ok('recommends the newest of each family, never a variant', array_column($list['recommended'], 'id') === ['anthropic/claude-sonnet-5.5', 'anthropic/claude-haiku-5.5', 'openai/gpt-5.4-mini']);
$ok('labels carry the note and the price per million', $list['recommended'][0]['label'] === 'anthropic/claude-sonnet-5.5  (best writing; $2 in / $10 out per million tokens)');
$ok('all: text models only, by name, junk ids dropped', array_column($list['all'], 'id') === ['anthropic/claude-haiku-5.5', 'anthropic/claude-sonnet-5.0', 'anthropic/claude-sonnet-5.5', 'anthropic/claude-sonnet-5.5:batch', 'openai/gpt-5.4-mini', 'some/free-model:free', 'some/router']);
$ok('a free model says so; a router with no price of its own is not called free', in_array('some/free-model:free  (free)', array_column($list['all'], 'label'), true)
    && in_array('some/router  (price varies)', array_column($list['all'], 'label'), true));

$list = $C::arrangeModels([
    ['id' => 'gpt-5-mini', 'created' => 10], ['id' => 'gpt-5.4-mini', 'created' => 30], ['id' => 'gpt-5.4-mini-2026-03-01', 'created' => 31],
    ['id' => 'gpt-5.5', 'created' => 40], ['id' => 'gpt-image-2', 'created' => 50], ['id' => 'text-embedding-4', 'created' => 60], ['id' => 'whisper-2', 'created' => 70],
], 'openai');
$ok('OpenAI: chat models by name, newest mini and newest full recommended', array_column($list['recommended'], 'id') === ['gpt-5.4-mini', 'gpt-5.5']
    && array_column($list['all'], 'id') === ['gpt-5-mini', 'gpt-5.4-mini', 'gpt-5.4-mini-2026-03-01', 'gpt-5.5']);

$sent  = [];
$queue = [['status' => 200, 'body' => json_encode(['data' => $models])]];
$list  = $client->listModels('openrouter');
$ok('OpenRouter\'s list is asked for without the key', $sent[0]['url'] === 'https://openrouter.ai/api/v1/models' && $sent[0]['body'] === null && !preg_grep('/Authorization/', $sent[0]['headers']) && count($list['recommended']) === 3);

$sent  = [];
$queue = [['status' => 200, 'body' => json_encode(['data' => [['id' => 'gpt-5.5', 'created' => 1]]])]];
$client->listModels('openai');
$ok('OpenAI\'s list is asked for with it', $sent[0]['url'] === 'https://api.openai.com/v1/models' && in_array('Authorization: Bearer sk-test-0000', $sent[0]['headers'], true));

$set('ai/api_key', '');
$count = count($sent);
$ok('OpenAI without a saved key: says to save one, calls nothing', strpos($throws(fn () => $client->listModels('openai')), 'Save the API key first') !== false && count($sent) === $count);
$set('ai/api_key', 'sk-test-0000');

$queue = [['status' => 500, 'body' => 'oops']];
$ok('a failed list says to type the id', strpos($throws(fn () => $client->listModels('openrouter')), 'Type the model id') !== false);
$ok('listing models is not counted as an AI request', (int) $db->fetchOne("SELECT COUNT(*) FROM {$log} WHERE feature NOT IN ('test')") === 0);

// --------------------------------------------------- subject suggestions
/** @var YSRTech_EmailCampaigns_Model_Ai_Copy $copy */
$copy = Mage::getSingleton('ysrtech_emailcampaigns/ai_copy');
$html = '<html><head><style>p{color:red}</style></head><body><h1>Chanukah sale</h1><p>Hi {{ customer.firstname|there }},</p>'
    . '<p>All children&#39;s books are 20% off until Sunday with the code LIGHTS20.</p>'
    . '<div data-ec-widget="products" data-layout="grid" data-source="sale" data-heading="On sale now"></div></body></html>';

$text = $copy::plainText($html);
$ok('plain text: words, no markup or css', strpos($text, "children's books are 20% off") !== false && strpos($text, '<') === false && strpos($text, 'color:red') === false);
$ok('plain text names a product block', strpos($text, '[products: On sale now]') !== false);

$sent  = [];
$queue = [$reply(json_encode([
    'subjects'   => ['20% off children\'s books', ' "Eight nights of stories" ', '<b>Ends Sunday</b>', '20% off children\'s books', 42, 'A fourth one', 'A fifth', 'A sixth'],
    'preheaders' => ['Use LIGHTS20 at checkout before Sunday night.', 'Second', 'Third', 'Fourth'],
]))];
$out = $copy->suggest($html, 'Old subject', '');
$ok('five subjects at most, deduplicated, tags and quotes stripped', $out['subjects'] === ['20% off children\'s books', 'Eight nights of stories', 'Ends Sunday', 'A fourth one', 'A fifth']);
$ok('three preheaders at most', count($out['preheaders']) === 3);

$prompt = json_encode($sent[0]['body']['messages']);
$ok('the email text and current subject are what is sent', strpos($prompt, 'LIGHTS20') !== false && strpos($prompt, 'Old subject') !== false);

$subscriber = (string) $db->fetchOne('SELECT subscriber_email FROM ' . $r->getTableName('newsletter/subscriber') . ' LIMIT 1');
$ok('no subscriber address is sent', $subscriber !== '' && strpos($prompt, $subscriber) === false && !preg_match('/[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $prompt));

$count = count($sent);
$ok('an empty email is refused without a call', strpos($throws(fn () => $copy->suggest('<p>Hi</p>')), 'Write the email first') !== false && count($sent) === $count);
$queue = [$reply('I cannot help with that.')];
$ok('an unusable reply is an error, not an empty list', strpos($throws(fn () => $copy->suggest($html)), 'usable suggestions') !== false);

// -------------------------------------------------------- design with AI
/** @var YSRTech_EmailCampaigns_Model_Ai_Designer $designer */
$designer = Mage::getSingleton('ysrtech_emailcampaigns/ai_designer');
$home     = Mage::app()->getDefaultStoreView()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);

$design = json_encode([
    'name' => 'Chanukah sale', 'subject' => '20% off children\'s books this week', 'preheader' => 'Use the code LIGHTS20 until Sunday night.',
    'sections' => [
        ['type' => 'header', 'logo' => 'x'],
        ['type' => 'hero', 'heading' => 'Eight nights <script>alert(1)</script>of stories', 'text' => 'Children\'s books, 20% off'],
        ['type' => 'text', 'text' => "Hi {{ customer.firstname|there }},\n\nOur sale starts Sunday & runs until [date].", 'heading' => ''],
        ['type' => 'coupon', 'code' => 'lights20 <b>', 'note' => 'One use per customer.'],
        ['type' => 'products', 'show' => 'sale', 'heading' => 'On "sale" now', 'count' => 5, 'url' => 'https://evil.example/x'],
        ['type' => 'button', 'label' => 'Shop the sale', 'url' => 'https://evil.example/phish'],
        ['type' => 'image', 'src' => 'https://evil.example/pixel.gif'],
        ['type' => 'divider'],
    ],
]);

$spec = $designer->parse("```json\n{$design}\n```");
$ok('parse keeps known sections only', array_column($spec['sections'], 'type') === ['hero', 'text', 'coupon', 'products', 'button', 'divider']);
$ok('parse: tags are dropped from the words', strpos($spec['sections'][0]['heading'], '<') === false);
$ok('parse: coupon code is cleaned, count is normalised', $spec['sections'][2]['code'] === 'LIGHTS20B' && $spec['sections'][3]['count'] === 4);
$ok('parse: the model cannot supply a link', strpos(json_encode($spec), 'evil.example') === false);
$ok('parse: no sections is no design', $designer->parse('{"sections": [{"type": "divider"}]}') === null && $designer->parse('nope') === null);

$ok('link: empty is the front page', $designer->link('') === $home);
$ok('link: a path is put on the store', $designer->link('sale.html') === rtrim($home, '/') . '/sale.html');
$ok('link: this store\'s own address is kept', $designer->link($home . 'sale.html') === $home . 'sale.html');
$ok('link: another site is refused', strpos($throws(fn () => $designer->link('https://evil.example/')), 'on this store') !== false);

$sent  = [];
$queue = [$reply('Sorry, here is some prose.'), $reply($design)];
$template = $designer->design('Our Chanukah sale starts Sunday: 20% off all children\'s books with the code LIGHTS20.', 'sale.html', 'festive');
$body     = (string) $template->getHtml();

$ok('a bad first reply is retried once', count($sent) === 2 && count($sent[1]['body']['messages']) === 4);
$ok('only the brief, the tone and the store name go out', strpos(json_encode($sent[0]['body']['messages']), 'festive') !== false && !preg_match('/@[a-z0-9.-]+\.[a-z]{2,}/i', json_encode($sent[0]['body']['messages'])));
$ok('template saved with subject and preheader', $template->getId() && $template->getSubject() === '20% off children\'s books this week' && strpos($template->getPreheader(), 'LIGHTS20') !== false);
$ok('wears the store\'s header and footer', (int) $template->getUseBrand() === YSRTech_EmailCampaigns_Model_Renderer::FRAME_BRAND);
$ok('no script reaches the html', stripos($body, '<script') === false && strpos($body, 'alert(1)') !== false);
$ok('ampersand escaped, placeholder left whole', strpos($body, 'Sunday &amp; runs') !== false && strpos($body, '{{ customer.firstname|there }}') !== false);
$ok('the button goes where the sender said', strpos($body, 'href="' . rtrim($home, '/') . '/sale.html"') !== false && strpos($body, 'evil.example') === false);
$ok('products are a real product block', (bool) preg_match('#<div data-ec-widget="products" data-layout="grid" data-source="sale" data-heading="On &quot;sale&quot; now" data-limit="4"[^>]*></div>#', $body));

$rendered = Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template);
$ok('renders: block replaced, coupon and heading there', strpos($rendered, 'data-ec-widget') === false && strpos($rendered, 'LIGHTS20B') !== false && strpos($rendered, 'of stories') !== false);

$queue = [$reply('no'), $reply('still no')];
$n     = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $r->getTableName('ysrtech_emailcampaigns/template'));
$ok('two bad replies: an error and nothing saved', strpos($throws(fn () => $designer->design('A long enough brief about a sale.', '', 'plain')), 'usable design') !== false
    && (int) $db->fetchOne('SELECT COUNT(*) FROM ' . $r->getTableName('ysrtech_emailcampaigns/template')) === $n);
$count = count($sent);
$ok('a brief too short is refused without a call', $throws(fn () => $designer->design('Sale', '', 'plain')) !== '' && count($sent) === $count);

$template->delete();

// ---------------------------------------------------------- sender check
$D = YSRTech_EmailCampaigns_Model_Deliverability::class;
$ok('registered domain', $D::registeredDomain('news.example.com') === 'example.com' && $D::registeredDomain('mail.shop.co.uk') === 'shop.co.uk' && $D::registeredDomain('example.com') === 'example.com');
$ok('dmarc tag', $D::tag('v=DMARC1; p=quarantine; rua=mailto:a@b.c', 'P') === 'quarantine' && $D::tag('v=DMARC1; p=none', 'rua') === null);

$set('sending/transport', 'mailgun');
$set('sending/from_email', 'news@shop.example.com');
$set('sending/mailgun_domain', 'mg.shop.example.com');
$set('sending/mailgun_webhook_signing_key', 'whk-test');

$check = function (array $dns, $mailgun = null) {
    return Mage::getModel('ysrtech_emailcampaigns/deliverability')
        ->setResolver(fn (string $host) => array_key_exists($host, $dns) ? $dns[$host] : [])
        ->setMailgunLookup(fn () => $mailgun)
        ->run();
};
$titles = fn (array $result) => implode(' | ', array_column($result['findings'], 'title'));
// The send log on staging has its own history; keep only the setup findings
$setup  = fn (array $result) => array_values(array_filter($result['findings'], fn ($f) => !preg_match('/^(Spam complaints|Bounces) are/', $f['title'])));

$good = [
    'mg.shop.example.com'        => ['v=spf1 include:mailgun.org ~all'],
    '_dmarc.shop.example.com'    => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com'],
];
$valid = [['name' => 'pic._domainkey.mg.shop.example.com', 'valid' => 'valid'], ['name' => 'mg.shop.example.com', 'valid' => 'valid']];

$result = $check($good, $valid);
$ok('all in place: nothing to fix in the setup', $setup($result) === [] && count($result['passed']) >= 5);

$result = $check([], $valid);
$ok('no SPF and no DMARC are both found', strpos($titles($result), 'No SPF record') !== false && strpos($titles($result), 'No DMARC record') !== false);
$ok('score is 100 less the weights', $result['score'] === max(0, 100 - array_sum(array_column($result['findings'], 'weight'))));

$result = $check(['_dmarc.example.com' => ['v=DMARC1; p=none']] + array_diff_key($good, ['_dmarc.shop.example.com' => 1]), $valid);
$ok('DMARC is inherited from the registered domain; p=none and no rua are notes', strpos($titles($result), 'No DMARC') === false
    && strpos($titles($result), 'monitoring mode') !== false && strpos($titles($result), 'reports are not being sent') !== false);

$result = $check(['mg.shop.example.com' => ['v=spf1 include:_spf.google.com ~all', 'v=spf1 -all']] + $good, $valid);
$ok('two SPF records is a failure', strpos($titles($result), 'has 2 SPF records') !== false);

$result = $check(['mg.shop.example.com' => ['v=spf1 include:_spf.google.com ~all']] + $good, $valid);
$ok('SPF without Mailgun is flagged', strpos($titles($result), 'does not include Mailgun') !== false);

$result = $check($good, [['name' => 'pic._domainkey.mg.shop.example.com', 'valid' => 'unknown']]);
$ok('DKIM unverified at Mailgun is a failure', strpos($titles($result), 'DKIM is not verified') !== false);

$result = $check($good, null);
$ok('DKIM unknown is said, not failed', strpos($titles($result), 'DKIM') === false && (bool) preg_grep('/^DKIM: could not be confirmed/', $result['unknown']));

$result = $check($good + ['k1._domainkey.mg.shop.example.com' => ['k=rsa; p=MIGfMA0GCSq']], null);
$ok('DKIM found in DNS under a Mailgun selector passes', (bool) preg_grep('/^DKIM signing is set up/', $result['passed']));

$set('sending/from_email', 'news@otherbrand.example.org');
$result = $check($good + ['_dmarc.otherbrand.example.org' => ['v=DMARC1; p=none; rua=mailto:x@y.z']], $valid);
$ok('From on another domain is the top finding', $result['findings'][0]['weight'] === 25 && strpos($result['findings'][0]['title'], 'different domain') !== false);
$set('sending/from_email', 'news@shop.example.com');

$set('sending/mailgun_webhook_signing_key', '');
$ok('missing webhook key is found', strpos($titles($check($good, $valid)), 'webhook signing key') !== false);
$set('sending/mailgun_webhook_signing_key', 'whk-test');

$result = Mage::getModel('ysrtech_emailcampaigns/deliverability')->setResolver(fn () => null)->setMailgunLookup(fn () => $valid)->run();
$ok('DNS not answering is unknown, never a failure', strpos($titles($result), 'SPF') === false && strpos($titles($result), 'DMARC') === false && count(preg_grep('/did not answer/', $result['unknown'])) === 2);

$model = Mage::getModel('ysrtech_emailcampaigns/deliverability')->setResolver(fn (string $host) => $good[$host] ?? [])->setMailgunLookup(fn () => $valid);
$fresh = $model->refresh();
$ok('the result is kept for the dashboard', Mage::getModel('ysrtech_emailcampaigns/deliverability')->latest() == $fresh);
Mage::app()->getCache()->remove($D::CACHE_KEY);

// ---------------------------------------------------------------- tidy up
$db->delete($log);
foreach ($before as $path => $value) { $store->setConfig($path, $value); }

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
