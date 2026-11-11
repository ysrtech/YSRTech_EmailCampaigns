<?php
/**
 * The one way this module talks to a language model.
 *
 * OpenRouter by default, OpenAI as the alternative: both speak the same
 * chat-completions format, so the provider, the model and the key are
 * settings and nothing else here changes with them. Every call is recorded
 * in ai_usage with its tokens and, when the provider reports it, its cost.
 *
 * What goes out is what the feature calling this put in the messages, and
 * the features are written to put in the words of an email and the name of
 * the store. Nothing about a subscriber is ever sent.
 */
class YSRTech_EmailCampaigns_Model_Ai_Client
{
    public const ENDPOINTS = [
        'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
        'openai'     => 'https://api.openai.com/v1/chat/completions',
    ];

    /** Where each provider lists the models a key may use */
    public const MODEL_LISTS = [
        'openrouter' => 'https://openrouter.ai/api/v1/models',
        'openai'     => 'https://api.openai.com/v1/models',
    ];

    /**
     * Which models to put first, as patterns rather than names: the newest
     * model matching each is the one recommended, so the advice keeps up as
     * providers release new versions. These are the general-purpose text
     * models priced for everyday use; the largest ones cost several times
     * more and write a subject line no better.
     */
    private const RECOMMENDED = [
        'openrouter' => [
            '#^anthropic/claude-sonnet-[\d.]+$#' => 'best writing',
            '#^anthropic/claude-haiku-[\d.]+$#'  => 'fast, lowest cost',
            '#^openai/gpt-[\d.]+-mini$#'         => 'fast, low cost',
            '#^google/gemini-[\d.]+-flash$#'     => 'fast, low cost',
        ],
        'openai' => [
            '#^gpt-[\d.]+-mini$#' => 'fast, low cost',
            '#^gpt-[\d.]+$#'      => 'best writing',
        ],
    ];

    public const MODELS_CACHE_KEY = 'ysrtech_emailcampaigns_ai_models_';

    /** @var callable|null fn(string $url, array $headers, ?string $body): array{status:int, body:string}; a null body is a GET */
    private $_transport;

    /**
     * Replace the HTTP call. For tests, which must never reach a provider.
     */
    public function setTransport(?callable $transport): self
    {
        $this->_transport = $transport;

        return $this;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->_config('enabled');
    }

    public function configured(): bool
    {
        return $this->isEnabled() && $this->_key() !== '' && $this->model() !== '';
    }

    public function provider(): string
    {
        $provider = strtolower(trim((string) $this->_config('provider')));

        return isset(self::ENDPOINTS[$provider]) ? $provider : 'openrouter';
    }

    public function model(): string
    {
        $model = trim((string) $this->_config('model'));

        return preg_match('#^[A-Za-z0-9._:/-]{2,120}$#', $model) ? $model : '';
    }

    /**
     * Why a feature cannot run, in words for the person who clicked; null
     * when it can.
     */
    public function unavailable(): ?string
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        if (!$this->isEnabled()) {
            return $h->__('AI features are switched off. Turn them on under System > Configuration > Email Campaigns > AI.');
        }

        if ($this->_key() === '' || $this->model() === '') {
            return $h->__('AI is not set up yet: add a key and a model under System > Configuration > Email Campaigns > AI.');
        }

        return null;
    }

    /**
     * @param  array<int, array{role:string, content:string}> $messages
     * @param  array{max_tokens?:int, temperature?:float, json?:bool} $options
     * @return array{text:string, usage:array{prompt:int, completion:int, cost:float|null}, model:string}
     * @throws Mage_Core_Exception With a message fit to show
     */
    public function chat(string $feature, array $messages, array $options = []): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        if ($reason = $this->unavailable()) {
            Mage::throwException($reason);
        }

        $limit = max(1, (int) $this->_config('max_calls_per_hour'));

        if ($this->callsLastHour() >= $limit) {
            Mage::throwException($h->__('The hourly limit of %d AI requests has been reached. Try again later, or raise the limit in the AI settings.', $limit));
        }

        $provider = $this->provider();
        $model    = $this->model();

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 1200),
            'temperature' => (float) ($options['temperature'] ?? 0.4),
        ];

        if (!empty($options['json'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        if ($provider === 'openrouter') {
            $body['usage'] = ['include' => true]; // asks OpenRouter for the real cost
        }

        $headers = ['Authorization: Bearer ' . $this->_key(), 'Content-Type: application/json', 'Accept: application/json'];

        if ($provider === 'openrouter') {
            // How OpenRouter labels the traffic on its own dashboard. A fixed
            // label: the store's name and address are not theirs to have.
            $headers[] = 'X-Title: Email Campaigns';
        }

        $started = microtime(true);

        try {
            $response = $this->_send(self::ENDPOINTS[$provider], $headers, (string) json_encode($body));
        } catch (Throwable $e) {
            $this->_log($feature, $started, false, 0, 0, null, 'Connection: ' . $e->getMessage());
            Mage::throwException($h->__('The AI service did not answer. Try again in a minute.'));
        }

        $json = json_decode($response['body'], true);
        $json = is_array($json) ? $json : [];

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = is_array($json['error'] ?? null) ? (string) ($json['error']['message'] ?? '') : '';
            $this->_log($feature, $started, false, 0, 0, null, 'HTTP ' . $response['status'] . ': ' . $detail);

            if ($response['status'] === 401 || $response['status'] === 403) {
                Mage::throwException($h->__('The AI provider refused the key. Check it in the AI settings.'));
            }

            if ($response['status'] === 404 || $response['status'] === 400) {
                Mage::throwException($h->__('The AI provider did not accept the request. Check the model name in the AI settings. (%s)', mb_substr($detail, 0, 160)));
            }

            Mage::throwException($response['status'] === 429
                ? $h->__('The AI service is busy or out of credit. Try again in a minute.')
                : $h->__('The AI service returned an error. Try again in a minute.'));
        }

        $text = $json['choices'][0]['message']['content'] ?? '';
        $text = is_string($text) ? $text : '';
        $in   = (int) ($json['usage']['prompt_tokens'] ?? 0);
        $out  = (int) ($json['usage']['completion_tokens'] ?? 0);
        $cost = isset($json['usage']['cost']) && is_numeric($json['usage']['cost']) ? (float) $json['usage']['cost'] : null;

        $this->_log($feature, $started, true, $in, $out, $cost, null);

        return [
            'text'  => $text,
            'usage' => ['prompt' => $in, 'completion' => $out, 'cost' => $cost],
            'model' => (string) ($json['model'] ?? $model),
        ];
    }

    /**
     * chat(), for a reply that has to be a JSON object. Models wrap JSON in
     * a code fence or a sentence often enough that the object is dug out
     * rather than expected bare.
     *
     * @return array|null The decoded object, or null when there was none
     */
    public static function decodeObject(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        $json = json_decode($text, true);

        return is_array($json) ? $json : null;
    }

    /**
     * The provider's own list of models, for the selector in the settings,
     * with the few that suit this work first.
     *
     * OpenRouter's list is public. OpenAI's needs the saved key, and lists
     * what that key may use.
     *
     * @return array{recommended:array<int, array{id:string, label:string}>, all:array<int, array{id:string, label:string}>}
     * @throws Mage_Core_Exception
     */
    public function listModels(?string $provider = null): array
    {
        $h        = Mage::helper('ysrtech_emailcampaigns');
        $provider = isset(self::MODEL_LISTS[$provider]) ? $provider : $this->provider();
        $cache    = Mage::app()->getCache();
        $cached   = $this->_transport ? null : $cache->load(self::MODELS_CACHE_KEY . $provider);

        if ($cached && is_array($list = json_decode((string) $cached, true))) {
            return $list;
        }

        $headers = ['Accept: application/json'];

        if ($provider === 'openai') {
            if ($this->_key() === '') {
                Mage::throwException($h->__('OpenAI lists models only for a key. Save the API key first, then choose from the list.'));
            }

            $headers[] = 'Authorization: Bearer ' . $this->_key();
        }

        try {
            $response = $this->_send(self::MODEL_LISTS[$provider], $headers, null);
        } catch (Throwable $e) {
            Mage::throwException($h->__('The provider\'s model list could not be reached. Type the model id instead.'));
        }

        $json = json_decode($response['body'], true);

        if ($response['status'] === 401 || $response['status'] === 403) {
            Mage::throwException($h->__('The provider refused the saved key, so it would not list its models.'));
        }

        if ($response['status'] !== 200 || !is_array($json['data'] ?? null)) {
            Mage::throwException($h->__('The provider did not return a model list (HTTP %d). Type the model id instead.', $response['status']));
        }

        $list = self::arrangeModels($json['data'], $provider);

        if (!$this->_transport && $list['all']) {
            $cache->save((string) json_encode($list), self::MODELS_CACHE_KEY . $provider, [], 21600);
        }

        return $list;
    }

    /**
     * A provider's raw list as the selector shows it: text models only,
     * newest first, each with its price where the provider gives one.
     *
     * @param  array<int, mixed> $models
     * @return array{recommended:array<int, array{id:string, label:string}>, all:array<int, array{id:string, label:string}>}
     */
    public static function arrangeModels(array $models, string $provider): array
    {
        $usable = [];

        foreach ($models as $model) {
            $id = is_array($model) && is_string($model['id'] ?? null) ? $model['id'] : '';

            if (!preg_match('#^[A-Za-z0-9._:/-]{2,120}$#', $id)) {
                continue;
            }

            // Text in, text out. OpenRouter says; OpenAI's list has to be
            // read by name, and holds image, audio and embedding models too.
            $outputs = $model['architecture']['output_modalities'] ?? null;

            if (is_array($outputs) ? $outputs !== ['text'] : !preg_match('#^(gpt-|o\d|chatgpt-)#', $id)
                || preg_match('#image|audio|realtime|transcribe|tts|embedding|moderation|search|instruct|codex#', $id)) {
                continue;
            }

            $price = '';
            $in    = $model['pricing']['prompt'] ?? null;
            $out   = $model['pricing']['completion'] ?? null;

            if (is_numeric($in) && is_numeric($out)) {
                $money = static fn ($perToken) => '$' . rtrim(rtrim(number_format((float) $perToken * 1000000, 2, '.', ''), '0'), '.');
                // A router that picks a model per request has no price of its
                // own, and reports none; only what says it is free is called so
                $price = (float) $in + (float) $out > 0
                    ? $money($in) . ' in / ' . $money($out) . ' out per million tokens'
                    : (preg_match('#(:free|/free)$#', $id) ? 'free' : 'price varies');
            }

            $usable[] = [
                'id'      => $id,
                'name'    => is_string($model['name'] ?? null) ? mb_substr(strip_tags($model['name']), 0, 80) : $id,
                'price'   => $price,
                'created' => (int) ($model['created'] ?? 0),
            ];
        }

        usort($usable, static fn ($a, $b) => $b['created'] <=> $a['created'] ?: strcmp($a['id'], $b['id']));

        $label       = static fn (array $m, string $note = '') => $m['id'] . ($note !== '' || $m['price'] !== '' ? '  (' . implode('; ', array_filter([$note, $m['price']])) . ')' : '');
        $recommended = [];

        foreach (self::RECOMMENDED[$provider] ?? [] as $pattern => $note) {
            foreach ($usable as $model) {
                if (preg_match($pattern, $model['id'])) {
                    $recommended[] = ['id' => $model['id'], 'label' => $label($model, $note)]; // the newest: the list is in that order
                    break;
                }
            }
        }

        usort($usable, static fn ($a, $b) => strcmp($a['id'], $b['id']));

        return [
            'recommended' => $recommended,
            'all'         => array_map(static fn ($m) => ['id' => $m['id'], 'label' => $label($m)], $usable),
        ];
    }

    public function callsLastHour(): int
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        return (int) $adapter->fetchOne(
            $adapter->select()
                ->from($resource->getTableName('ysrtech_emailcampaigns/ai_usage'), [new Zend_Db_Expr('COUNT(*)')])
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - 3600))
        );
    }

    /**
     * Calls, tokens and cost over the last $days days.
     *
     * @return array{calls:int, failed:int, tokens:int, cost:float|null}
     */
    public function usage(int $days = 30): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $row = $adapter->fetchRow(
            $adapter->select()
                ->from($resource->getTableName('ysrtech_emailcampaigns/ai_usage'), [
                    'calls'  => new Zend_Db_Expr('COUNT(*)'),
                    'failed' => new Zend_Db_Expr('COALESCE(SUM(ok = 0), 0)'),
                    'tokens' => new Zend_Db_Expr('COALESCE(SUM(prompt_tokens + completion_tokens), 0)'),
                    'cost'   => new Zend_Db_Expr('SUM(cost_usd)'),
                ])
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - $days * 86400))
        );

        return [
            'calls'  => (int) $row['calls'],
            'failed' => (int) $row['failed'],
            'tokens' => (int) $row['tokens'],
            'cost'   => $row['cost'] !== null ? (float) $row['cost'] : null,
        ];
    }

    /**
     * A one-word round trip, for the Test button in the settings.
     *
     * @return array{ok:bool, lines:array<int, array{level:string, text:string}>}
     */
    public function test(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        if ($reason = $this->unavailable()) {
            return ['ok' => false, 'lines' => [['level' => 'error', 'text' => $reason]]];
        }

        try {
            $reply = $this->chat('test', [['role' => 'user', 'content' => 'Reply with the single word: ready']], ['max_tokens' => 20, 'temperature' => 0]);
        } catch (Mage_Core_Exception $e) {
            return ['ok' => false, 'lines' => [['level' => 'error', 'text' => $e->getMessage()]]];
        }

        $lines = [[
            'level' => 'ok',
            'text'  => $h->__('%s answered with model %s (%d tokens).', $this->provider() === 'openai' ? 'OpenAI' : 'OpenRouter', $reply['model'], $reply['usage']['prompt'] + $reply['usage']['completion']),
        ]];

        if (trim($reply['text']) === '') {
            $lines[] = ['level' => 'warn', 'text' => $h->__('The reply was empty. Some models need a higher token limit; try another model if the features return nothing.')];
        }

        return ['ok' => true, 'lines' => $lines];
    }

    /**
     * @return array{status:int, body:string}
     */
    private function _send(string $url, array $headers, ?string $body): array
    {
        if ($this->_transport) {
            return call_user_func($this->_transport, $url, $headers, $body);
        }

        // The key goes out with the request, so only ever to the addresses above
        if (!in_array($url, self::ENDPOINTS, true) && !in_array($url, self::MODEL_LISTS, true)) {
            throw new RuntimeException('Refusing to send an AI key to ' . parse_url($url, PHP_URL_HOST));
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $body === null ? 20 : 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException($error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    private function _log(string $feature, float $started, bool $ok, int $in, int $out, ?float $cost, ?string $error): void
    {
        try {
            $resource = Mage::getSingleton('core/resource');
            $admin    = Mage::app()->getStore()->isAdmin() && PHP_SAPI !== 'cli'
                ? Mage::getSingleton('admin/session')->getUser()
                : null;

            $resource->getConnection('core_write')->insert($resource->getTableName('ysrtech_emailcampaigns/ai_usage'), [
                'feature'           => mb_substr($feature, 0, 40),
                'provider'          => $this->provider(),
                'model'             => mb_substr($this->model(), 0, 120),
                'prompt_tokens'     => $in,
                'completion_tokens' => $out,
                'cost_usd'          => $cost,
                'duration_ms'       => (int) ((microtime(true) - $started) * 1000),
                'ok'                => $ok ? 1 : 0,
                // The provider's error text can quote the request; the key is
                // never in it, but keep what is stored short all the same
                'error'             => $error !== null ? mb_substr($error, 0, 255) : null,
                'admin_user_id'     => $admin ? (int) $admin->getId() : null,
                'created_at'        => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Mage::logException($e); // a failed log line is no reason to fail the feature
        }
    }

    private function _key(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->getSecret('ai/api_key');
    }

    private function _config(string $field)
    {
        return Mage::helper('ysrtech_emailcampaigns')->getConfig('ai/' . $field);
    }
}
