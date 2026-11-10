<?php
/**
 * Klaviyo's JSON:API.
 *
 * Whether a profile may be mailed is only in its subscriptions, which have
 * to be asked for (additional-fields[profile]=subscriptions). A suppressed
 * profile carries the reason; one that is neither suppressed nor able to
 * receive email marketing never consented, and comes across as pending.
 *
 * Paging is by following links.next, which Klaviyo returns as whole URLs;
 * the base class refuses one that points anywhere but Klaviyo.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Klaviyo
    extends YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
{
    /** The API revision these request and response shapes were written against */
    public const REVISION = '2026-10-15';

    /** Profiles per request; Klaviyo's maximum when subscriptions are included */
    public const PAGE = 100;

    /** Klaviyo suppression reason => ours. One pass over the profiles per reason. */
    public const REASONS = [
        'HARD_BOUNCE'     => 'bounce',
        'INVALID_EMAIL'   => 'bounce',
        'SPAM_COMPLAINT'  => 'complaint',
        'UNSUBSCRIBE'     => 'unsubscribe',
        'USER_SUPPRESSED' => 'unsubscribe',
    ];

    public function getName(): string
    {
        return 'Klaviyo';
    }

    public function getKeyHelp(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__(
            'In Klaviyo: Settings → API keys → Create Private API Key → Read-only access.'
        );
    }

    protected function _host(): string
    {
        return 'a.klaviyo.com';
    }

    /**
     * The revision pins the response shapes. The second Accept is the
     * JSON:API media type Klaviyo documents; it is sent alongside the base
     * class's application/json, and a server reads the two as one list.
     */
    protected function _authHeaders(): array
    {
        return [
            'Authorization: Klaviyo-API-Key ' . $this->_key(),
            'revision: ' . static::REVISION,
            'Accept: application/vnd.api+json',
        ];
    }

    public function account(): array
    {
        $r = $this->_get('/api/accounts');

        return [
            'name' => (string) ($r['data'][0]['attributes']['contact_information']['organization_name'] ?? 'Klaviyo account'),
        ];
    }

    /**
     * Klaviyo gives no totals without reading everything, so every count is
     * unknown. Nothing is requested here.
     */
    public function discover(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            'contacts'     => null,
            'suppressions' => null,
            'templates'    => null,
            'notes'        => [
                $h->__('Suppressed profiles (hard bounces, spam complaints, unsubscribes) are imported first.'),
                $h->__('Profiles that never agreed to email marketing are not imported as subscribers.'),
                $h->__('Profile properties become custom fields. Segments, flows, metrics and orders are not imported.'),
            ],
        ];
    }

    public function stages(): array
    {
        return [self::STAGE_SUPPRESSIONS, self::STAGE_CONTACTS, self::STAGE_TEMPLATES];
    }

    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        $next = isset($cursor['next']) ? (string) $cursor['next'] : null;

        switch ($stage) {
            case self::STAGE_SUPPRESSIONS:
                $reasons = array_keys(static::REASONS);
                $p       = (int) ($cursor['p'] ?? 0);

                if (!isset($reasons[$p])) {
                    return ['items' => [], 'cursor' => null];
                }

                $r = $next !== null ? $this->_get($next) : $this->_get('/api/profiles', [
                    'additional-fields[profile]' => 'subscriptions',
                    'fields[profile]'            => 'email,subscriptions',
                    'page[size]'                 => static::PAGE,
                    'filter'                     => 'equals(subscriptions.email.marketing.suppression.reason,"' . $reasons[$p] . '")',
                ]);

                $items = [];

                foreach ((array) ($r['data'] ?? []) as $profile) {
                    $items[] = [
                        'email'  => (string) ($profile['attributes']['email'] ?? ''),
                        'reason' => static::REASONS[$reasons[$p]],
                        'detail' => strtolower($reasons[$p]),
                    ];
                }

                if (!empty($r['links']['next'])) {
                    return ['items' => $items, 'cursor' => ['p' => $p, 'next' => (string) $r['links']['next']]];
                }

                return ['items' => $items, 'cursor' => isset($reasons[$p + 1]) ? ['p' => $p + 1] : null];

            case self::STAGE_CONTACTS:
                $r = $next !== null ? $this->_get($next) : $this->_get('/api/profiles', [
                    'additional-fields[profile]' => 'subscriptions',
                    'page[size]'                 => static::PAGE,
                ]);

                return [
                    'items'  => array_map([$this, '_profile'], (array) ($r['data'] ?? [])),
                    'cursor' => !empty($r['links']['next']) ? ['next' => (string) $r['links']['next']] : null,
                ];

            case self::STAGE_TEMPLATES:
                $r = $next !== null ? $this->_get($next) : $this->_get('/api/templates', [
                    'fields[template]' => 'name,html',
                ]);

                $items = [];

                foreach ((array) ($r['data'] ?? []) as $template) {
                    $html = (string) ($template['attributes']['html'] ?? '');

                    if ($html !== '') {
                        $items[] = [
                            'name'    => (string) ($template['attributes']['name'] ?? 'Klaviyo template'),
                            // Klaviyo keeps the subject on the campaign or flow message, not the template
                            'subject' => '',
                            'html'    => $html,
                        ];
                    }
                }

                return [
                    'items'  => $items,
                    'cursor' => !empty($r['links']['next']) ? ['next' => (string) $r['links']['next']] : null,
                ];
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * A suppression outranks everything: a profile can still read as
     * consented after it bounced.
     */
    protected function _profile(array $profile): array
    {
        $a         = (array) ($profile['attributes'] ?? []);
        $marketing = (array) ($a['subscriptions']['email']['marketing'] ?? []);
        $reason    = null;

        foreach ((array) ($marketing['suppression'] ?? []) as $suppression) {
            $reason = $reason ?? (static::REASONS[$suppression['reason'] ?? ''] ?? null);
        }

        if ($reason === 'bounce') {
            $status = 'bounced';
        } elseif ($reason === 'complaint') {
            $status = 'complained';
        } elseif ($reason === 'unsubscribe' || ($marketing['consent'] ?? '') === 'UNSUBSCRIBED') {
            $status = 'unsubscribed';
        } elseif (!empty($marketing['can_receive_email_marketing'])) {
            $status = 'subscribed';
        } else {
            $status = 'pending';
        }

        return [
            'email'      => (string) ($a['email'] ?? ''),
            'first_name' => $a['first_name'] ?? null,
            'last_name'  => $a['last_name'] ?? null,
            'fields'     => (array) ($a['properties'] ?? []),
            'tags'       => [],
            'status'     => $status,
        ];
    }

    /**
     * {{ first_name|default:'there' }}, {{ person.email }},
     * {{ organization.name }}, {% unsubscribe %}. Anything else - other
     * profile properties, other filters, other block tags - stays as it is.
     */
    public function convertMergeTags(string $html): string
    {
        $html = (string) preg_replace('/\{%\s*unsubscribe_link\s*%\}/', '{{unsubscribe_url}}', $html);

        // {% unsubscribe %} is a whole link in Klaviyo, optionally with its own text
        $html = (string) preg_replace_callback(
            '/\{%\s*unsubscribe(?:\s+(["\'])([^"\'%<>]*)\1)?\s*%\}/',
            static function (array $m) {
                $text = isset($m[2]) && trim($m[2]) !== '' ? $m[2] : 'Unsubscribe';

                return '<a href="{{unsubscribe_url}}">' . $text . '</a>';
            },
            $html
        );

        $html = (string) preg_replace('/\{\{\s*organization\.name\s*\}\}/', '{{store.name}}', $html);

        $map = ['first_name' => 'customer.firstname', 'last_name' => 'customer.lastname', 'email' => 'customer.email'];

        return (string) preg_replace_callback(
            '/\{\{\s*(?:person\.)?(first_name|last_name|email)\s*(?:\|\s*default\s*:\s*(["\'])([^"\'}]*)\2\s*)?\}\}/',
            static function (array $m) use ($map) {
                $fallback = trim($m[3] ?? '');

                return '{{' . $map[$m[1]] . ($fallback !== '' ? '|' . $fallback : '') . '}}';
            },
            $html
        );
    }
}
