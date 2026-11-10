<?php
/**
 * Brevo API v3.
 *
 * Suppressions are Brevo's blocked contacts, each with a reason code.
 * Contacts are one flat collection (their list membership is not carried
 * across); names live in the FIRSTNAME / LASTNAME attributes and the other
 * attributes become fields. A blocklisted contact comes across unsubscribed.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Brevo
    extends YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
{
    /** Page sizes; the first two are Brevo's maxima for their endpoints */
    public const BLOCKED_PAGE  = 100;
    public const CONTACT_PAGE  = 1000;
    public const TEMPLATE_PAGE = 50;

    /**
     * Brevo reason code => ours. A code not listed here is still a blocked
     * address, and is carried as an unsubscribe: the safe reading.
     */
    public const REASONS = [
        'hardBounce'           => 'bounce',
        'contactFlaggedAsSpam' => 'complaint',
        'unsubscribedViaEmail' => 'unsubscribe',
        'unsubscribedViaApi'   => 'unsubscribe',
        'unsubscribedViaMA'    => 'unsubscribe',
        'adminBlocked'         => 'unsubscribe',
    ];

    public function getName(): string
    {
        return 'Brevo';
    }

    public function getKeyHelp(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__(
            'In Brevo: your name (top right) → SMTP & API → API keys → Generate a new API key; Brevo keys cannot be made read-only, so delete it there once the import has finished.'
        );
    }

    protected function _host(): string
    {
        return 'api.brevo.com';
    }

    protected function _authHeaders(): array
    {
        return ['api-key: ' . $this->_key()];
    }

    public function account(): array
    {
        $r    = $this->_get('/v3/account');
        $name = (string) ($r['companyName'] ?? '');

        return ['name' => $name !== '' ? $name : (string) ($r['email'] ?? 'Brevo account')];
    }

    public function discover(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            'contacts'     => (int) ($this->_get('/v3/contacts', ['limit' => 1])['count'] ?? 0),
            'suppressions' => (int) ($this->_get('/v3/smtp/blockedContacts', ['limit' => 1])['count'] ?? 0),
            'templates'    => (int) ($this->_get('/v3/smtp/templates', ['limit' => 1])['count'] ?? 0),
            'notes'        => [
                $h->__('Blocked contacts (hard bounces, spam complaints, unsubscribes) are imported first as suppressions, and blocklisted contacts come across unsubscribed.'),
                $h->__('FIRSTNAME and LASTNAME become first and last name; other attributes become custom fields.'),
                $h->__('SMS and WhatsApp data are not imported.'),
            ],
        ];
    }

    public function stages(): array
    {
        return [self::STAGE_SUPPRESSIONS, self::STAGE_CONTACTS, self::STAGE_TEMPLATES];
    }

    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        $o = (int) ($cursor['o'] ?? 0);

        switch ($stage) {
            case self::STAGE_SUPPRESSIONS:
                $r     = $this->_get('/v3/smtp/blockedContacts', ['limit' => static::BLOCKED_PAGE, 'offset' => $o]);
                $rows  = (array) ($r['contacts'] ?? []);
                $items = [];

                foreach ($rows as $row) {
                    $code    = (string) ($row['reason']['code'] ?? '');
                    $items[] = [
                        'email'  => (string) ($row['email'] ?? ''),
                        'reason' => static::REASONS[$code] ?? 'unsubscribe',
                        'detail' => $code,
                    ];
                }

                return $this->_page($items, $rows, $o, static::BLOCKED_PAGE, $r);

            case self::STAGE_CONTACTS:
                $r = $this->_get('/v3/contacts', [
                    'limit'  => static::CONTACT_PAGE,
                    'offset' => $o,
                    // Oldest first, so contacts created during the import land on the last page
                    'sort'   => 'asc',
                ]);
                $rows  = (array) ($r['contacts'] ?? []);
                $items = [];

                foreach ($rows as $row) {
                    $a       = (array) ($row['attributes'] ?? []);
                    $items[] = [
                        'email'      => (string) ($row['email'] ?? ''),
                        'first_name' => $a['FIRSTNAME'] ?? null,
                        'last_name'  => $a['LASTNAME'] ?? null,
                        'fields'     => array_diff_key($a, ['FIRSTNAME' => 1, 'LASTNAME' => 1, 'EMAIL' => 1, 'SMS' => 1]),
                        'tags'       => [],
                        'status'     => !empty($row['emailBlacklisted']) ? 'unsubscribed' : 'subscribed',
                    ];
                }

                return $this->_page($items, $rows, $o, static::CONTACT_PAGE, $r);

            case self::STAGE_TEMPLATES:
                $r     = $this->_get('/v3/smtp/templates', ['limit' => static::TEMPLATE_PAGE, 'offset' => $o]);
                $rows  = (array) ($r['templates'] ?? []);
                $items = [];

                foreach ($rows as $row) {
                    $html = (string) ($row['htmlContent'] ?? '');

                    if ($html !== '') {
                        $items[] = [
                            'name'    => (string) ($row['name'] ?? 'Brevo template'),
                            'subject' => (string) ($row['subject'] ?? ''),
                            'html'    => $html,
                        ];
                    }
                }

                return $this->_page($items, $rows, $o, static::TEMPLATE_PAGE, $r);
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * A full page means there may be another; a short one is the last.
     * Judged on the rows Brevo returned, not on the items kept from them.
     */
    protected function _page(array $items, array $rows, int $offset, int $size, array $response): array
    {
        return [
            'items'  => $items,
            'cursor' => count($rows) >= $size ? ['o' => $offset + $size] : null,
            'total'  => isset($response['count']) ? (int) $response['count'] : null,
        ];
    }

    /**
     * {{ contact.FIRSTNAME | default: "there" }} and {{ unsubscribe }}.
     * Other contact attributes, {{ params.* }}, {{ mirror }} and
     * {{ update_profile }} have no equivalent and stay as they are.
     */
    public function convertMergeTags(string $html): string
    {
        $map = ['FIRSTNAME' => 'customer.firstname', 'LASTNAME' => 'customer.lastname', 'EMAIL' => 'customer.email'];

        $html = (string) preg_replace_callback(
            '/\{\{\s*contact\.(FIRSTNAME|LASTNAME|EMAIL)\s*(?:\|\s*default\s*:\s*(["\'])([^"\'}]*)\2\s*)?\}\}/',
            static function (array $m) use ($map) {
                $fallback = trim($m[3] ?? '');

                return '{{' . $map[$m[1]] . ($fallback !== '' ? '|' . $fallback : '') . '}}';
            },
            $html
        );

        return (string) preg_replace('/\{\{\s*unsubscribe\s*\}\}/', '{{unsubscribe_url}}', $html);
    }
}
