<?php
/**
 * Kit (formerly ConvertKit) API v4.
 *
 * Every subscriber has one state: active, inactive (never confirmed),
 * cancelled, bounced or complained. The last three are read first, one
 * state at a time, as suppressions. Kit has no saved templates worth the
 * name in the API, so the HTML of its broadcasts comes across instead.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Kit
    extends YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
{
    /** Subscribers per request; Kit's maximum */
    public const PAGE = 1000;

    /** Broadcasts per request: one without content costs a second request */
    public const BROADCAST_PAGE = 50;

    /** Broadcasts to bring across, in all. An old account can have thousands. */
    public const BROADCASTS = 200;

    /** Suppression passes: Kit state => our reason */
    public const PASSES = [
        'cancelled'  => 'unsubscribe',
        'bounced'    => 'bounce',
        'complained' => 'complaint',
    ];

    /** Kit state => our status. Anything else (inactive) never confirmed. */
    public const STATES = [
        'active'     => 'subscribed',
        'cancelled'  => 'unsubscribed',
        'bounced'    => 'bounced',
        'complained' => 'complained',
    ];

    public function getName(): string
    {
        return 'Kit';
    }

    public function getKeyHelp(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__(
            'In Kit: Settings → Developer → API keys (v4) → Add a new key; Kit keys cannot be made read-only, so delete it there once the import has finished.'
        );
    }

    protected function _host(): string
    {
        return 'api.kit.com';
    }

    protected function _authHeaders(): array
    {
        return ['X-Kit-Api-Key: ' . $this->_key()];
    }

    public function account(): array
    {
        $r = $this->_get('/v4/account');

        return ['name' => (string) ($r['account']['name'] ?? 'Kit account')];
    }

    public function discover(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');
        $r = $this->_get('/v4/subscribers', ['status' => 'all', 'per_page' => 1, 'include_total_count' => 'true']);

        return [
            'contacts'     => isset($r['pagination']['total_count']) ? (int) $r['pagination']['total_count'] : null,
            'suppressions' => null,
            'templates'    => null,
            'notes'        => [
                $h->__('Cancelled, bounced and complained subscribers are imported first as suppressions; unconfirmed (inactive) ones are not imported as subscribers.'),
                $h->__('Tags come over as tags. Kit has no last name field: a custom "last_name" field is used when you have one.'),
                $h->__('Your %d most recent broadcasts come over as templates.', static::BROADCASTS),
            ],
        ];
    }

    public function stages(): array
    {
        return [self::STAGE_SUPPRESSIONS, self::STAGE_CONTACTS, self::STAGE_TEMPLATES];
    }

    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        // Kit pages with an opaque "after" cursor of its own
        $after = isset($cursor['a']) ? ['after' => (string) $cursor['a']] : [];

        switch ($stage) {
            case self::STAGE_SUPPRESSIONS:
                $states = array_keys(static::PASSES);
                $p      = (int) ($cursor['p'] ?? 0);

                if (!isset($states[$p])) {
                    return ['items' => [], 'cursor' => null];
                }

                $r = $this->_get('/v4/subscribers', ['status' => $states[$p], 'per_page' => static::PAGE] + $after);

                $items = [];

                foreach ((array) ($r['subscribers'] ?? []) as $subscriber) {
                    $items[] = [
                        'email'  => (string) ($subscriber['email_address'] ?? ''),
                        'reason' => static::PASSES[$states[$p]],
                        'detail' => $states[$p],
                    ];
                }

                $end = $this->_endCursor($r);

                if ($end !== null) {
                    return ['items' => $items, 'cursor' => ['p' => $p, 'a' => $end]];
                }

                return ['items' => $items, 'cursor' => isset($states[$p + 1]) ? ['p' => $p + 1] : null];

            case self::STAGE_CONTACTS:
                $r = $this->_get(
                    '/v4/subscribers',
                    ['status' => 'all', 'per_page' => static::PAGE, 'include' => 'tags'] + $after
                );

                $items = [];

                foreach ((array) ($r['subscribers'] ?? []) as $subscriber) {
                    $fields = (array) ($subscriber['fields'] ?? []);
                    $tags   = [];

                    foreach ((array) ($subscriber['tags'] ?? []) as $tag) {
                        $tags[] = (string) (is_array($tag) ? ($tag['name'] ?? '') : $tag);
                    }

                    $items[] = [
                        'email'      => (string) ($subscriber['email_address'] ?? ''),
                        'first_name' => $subscriber['first_name'] ?? null,
                        'last_name'  => $fields['last_name'] ?? null,
                        'fields'     => array_diff_key($fields, ['last_name' => 1]),
                        'tags'       => array_values(array_filter($tags, 'strlen')),
                        'status'     => static::STATES[$subscriber['state'] ?? ''] ?? 'pending',
                    ];
                }

                $end = $this->_endCursor($r);

                return ['items' => $items, 'cursor' => $end !== null ? ['a' => $end] : null];

            case self::STAGE_TEMPLATES:
                // n = broadcasts read so far, so the cap holds across pages
                $n = (int) ($cursor['n'] ?? 0);
                $r = $this->_get('/v4/broadcasts', ['per_page' => static::BROADCAST_PAGE] + $after);

                $rows  = (array) ($r['broadcasts'] ?? []);
                $items = [];

                foreach ($rows as $broadcast) {
                    $html = $broadcast['content'] ?? null;

                    if (!$html && isset($broadcast['id'])) {
                        $one  = $this->_get('/v4/broadcasts/' . rawurlencode((string) $broadcast['id']));
                        $html = $one['broadcast']['content'] ?? null;
                    }

                    if ($html) {
                        $subject = (string) ($broadcast['subject'] ?? '');
                        $items[] = [
                            'name'    => $subject !== '' ? $subject : 'Kit broadcast',
                            'subject' => $subject,
                            'html'    => (string) $html,
                        ];
                    }
                }

                $n  += count($rows);
                $end = $this->_endCursor($r);

                return [
                    'items'  => $items,
                    'cursor' => $end !== null && $n < static::BROADCASTS ? ['a' => $end, 'n' => $n] : null,
                ];
        }

        return ['items' => [], 'cursor' => null];
    }

    /** Where the next page starts, or null on the last one */
    protected function _endCursor(array $response): ?string
    {
        $pagination = (array) ($response['pagination'] ?? []);

        if (empty($pagination['has_next_page']) || empty($pagination['end_cursor'])) {
            return null;
        }

        return (string) $pagination['end_cursor'];
    }

    /**
     * Kit's Liquid: {{ subscriber.first_name | default: "there" }},
     * {{ subscriber.email_address }}, {{ unsubscribe_url }} and
     * {{ unsubscribe_link }}, which is a whole link. Custom fields and other
     * Liquid stay as they are.
     */
    public function convertMergeTags(string $html): string
    {
        $html = (string) preg_replace(
            '/\{\{\s*unsubscribe_link\s*\}\}/',
            '<a href="{{unsubscribe_url}}">Unsubscribe</a>',
            $html
        );
        $html = (string) preg_replace('/\{\{\s*unsubscribe_url\s*\}\}/', '{{unsubscribe_url}}', $html);

        $map = [
            'first_name'    => 'customer.firstname',
            'last_name'     => 'customer.lastname',
            'email_address' => 'customer.email',
        ];

        return (string) preg_replace_callback(
            '/\{\{\s*subscriber\.(first_name|last_name|email_address)\s*(?:\|\s*default\s*:\s*(["\'])([^"\'}]*)\2\s*)?\}\}/',
            static function (array $m) use ($map) {
                $fallback = trim($m[3] ?? '');

                return '{{' . $map[$m[1]] . ($fallback !== '' ? '|' . $fallback : '') . '}}';
            },
            $html
        );
    }
}
