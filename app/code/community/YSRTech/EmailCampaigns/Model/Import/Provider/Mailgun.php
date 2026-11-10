<?php
/**
 * Mailgun.
 *
 * Suppressions belong to a sending domain (bounces, complaints,
 * unsubscribes), which is why the domain is asked for. Contacts are the
 * members of the account's mailing lists: Mailgun has no other record of
 * people, so every list is walked in turn and the cursor remembers which.
 * An address on two lists comes out twice; the importer dedupes.
 *
 * Paging is by following paging.next, which Mailgun returns as whole URLs;
 * the base class refuses one that points anywhere but the region's host.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Mailgun
    extends YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
{
    /** Suppression collections, in order => our reason */
    public const KINDS = [
        'bounces'      => 'bounce',
        'complaints'   => 'complaint',
        'unsubscribes' => 'unsubscribe',
    ];

    /** Page sizes; the first two are Mailgun's maxima for their endpoints */
    public const SUPPRESSION_PAGE = 1000;
    public const MEMBER_PAGE      = 100;
    public const LIST_PAGE        = 100;

    /** Templates per page: each one costs a second request for its content */
    public const TEMPLATE_PAGE = 25;

    public function getName(): string
    {
        return 'Mailgun';
    }

    public function getCredentialFields(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            'domain' => [
                'label'    => $h->__('Sending domain'),
                'note'     => $h->__('The domain you send from in Mailgun, like mg.yourdomain.com.'),
                'required' => true,
            ],
            'region' => [
                'label' => $h->__('Region'),
                'note'  => $h->__('"us" or "eu": where the domain was created in Mailgun. Empty means US.'),
            ],
        ];
    }

    public function getKeyHelp(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__(
            'In Mailgun: Account settings → API security → Add new key, with the Analyst (read-only) role if your plan offers roles.'
        );
    }

    /**
     * A region that is neither is refused rather than guessed at: the key
     * would otherwise go to the US host for a typo of "eu".
     */
    protected function _host(): string
    {
        $region = strtolower(trim((string) ($this->_credentials['region'] ?? '')));

        if ($region === 'eu') {
            return 'api.eu.mailgun.net';
        }

        if ($region === '' || $region === 'us') {
            return 'api.mailgun.net';
        }

        throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
            Mage::helper('ysrtech_emailcampaigns')->__('The Mailgun region is either "us" or "eu".')
        );
    }

    protected function _authHeaders(): array
    {
        return ['Authorization: Basic ' . base64_encode('api:' . $this->_key())];
    }

    /** The sending domain, checked before it is put into a URL */
    protected function _domain(): string
    {
        $domain = strtolower(trim((string) ($this->_credentials['domain'] ?? '')));

        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                Mage::helper('ysrtech_emailcampaigns')->__('Enter your Mailgun sending domain (like mg.yourdomain.com).')
            );
        }

        return $domain;
    }

    /**
     * Proves the key, and that the domain is in this account and region: a
     * US key asked about an EU domain is the usual mistake.
     */
    public function account(): array
    {
        $domain = $this->_domain();
        $r      = $this->_get('/v4/domains', ['limit' => 1000]);

        foreach ((array) ($r['items'] ?? []) as $item) {
            if (strtolower((string) ($item['name'] ?? '')) === $domain) {
                return ['name' => $domain];
            }
        }

        throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(Mage::helper('ysrtech_emailcampaigns')->__(
            'Mailgun does not have %s in this account and region. Check the domain, or switch between US and EU.',
            $domain
        ));
    }

    public function discover(): array
    {
        $h        = Mage::helper('ysrtech_emailcampaigns');
        $domain   = $this->_domain();
        $contacts = 0;

        foreach ($this->_lists() as $list) {
            $contacts += (int) ($list['members_count'] ?? 0);
        }

        $templates = $this->_get("/v3/{$domain}/templates", ['limit' => 100]);
        $count     = count((array) ($templates['items'] ?? []));

        return [
            'contacts'     => $contacts,
            'suppressions' => null,
            // A full first page may not be all of them
            'templates'    => $count < 100 ? $count : null,
            'notes'        => [
                $h->__('Bounces, spam complaints and unsubscribes for %s are imported first.', $domain),
                $h->__('Mailing list members become contacts; an address on several lists is counted once per list here, and imported once.'),
                $h->__('Routes and inbound mail are not imported.'),
            ],
        ];
    }

    public function stages(): array
    {
        return [self::STAGE_SUPPRESSIONS, self::STAGE_CONTACTS, self::STAGE_TEMPLATES];
    }

    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        $domain = $this->_domain();
        $next   = isset($cursor['next']) ? (string) $cursor['next'] : null;

        switch ($stage) {
            case self::STAGE_SUPPRESSIONS:
                $kinds = array_keys(static::KINDS);
                $k     = (int) ($cursor['k'] ?? 0);

                if (!isset($kinds[$k])) {
                    return ['items' => [], 'cursor' => null];
                }

                $r = $next !== null
                    ? $this->_get($next)
                    : $this->_get("/v3/{$domain}/{$kinds[$k]}", ['limit' => static::SUPPRESSION_PAGE]);

                $rows  = (array) ($r['items'] ?? []);
                $items = [];

                foreach ($rows as $row) {
                    $items[] = [
                        'email'  => (string) ($row['address'] ?? ''),
                        'reason' => static::KINDS[$kinds[$k]],
                        // Bounces carry the SMTP code; the others only their kind
                        'detail' => isset($row['code']) ? (string) $row['code'] : $kinds[$k],
                    ];
                }

                $more = $this->_nextLink($r, $rows);

                if ($more !== null) {
                    return ['items' => $items, 'cursor' => ['k' => $k, 'next' => $more]];
                }

                return ['items' => $items, 'cursor' => isset($kinds[$k + 1]) ? ['k' => $k + 1] : null];

            case self::STAGE_CONTACTS:
                $lists = $this->_lists();
                $l     = (int) ($cursor['l'] ?? 0);

                if (!isset($lists[$l])) {
                    return ['items' => [], 'cursor' => null];
                }

                $r = $next !== null
                    ? $this->_get($next)
                    : $this->_get(
                        '/v3/lists/' . rawurlencode((string) $lists[$l]['address']) . '/members/pages',
                        ['limit' => static::MEMBER_PAGE]
                    );

                $rows  = (array) ($r['items'] ?? []);
                $items = [];

                foreach ($rows as $member) {
                    // Mailgun keeps one name; the first word is taken as the first name
                    $name = trim((string) ($member['name'] ?? ''));
                    [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);

                    $items[] = [
                        'email'      => (string) ($member['address'] ?? ''),
                        'first_name' => $first !== '' ? $first : null,
                        'last_name'  => $last !== null ? trim($last) : null,
                        'fields'     => (array) ($member['vars'] ?? []),
                        'tags'       => [],
                        'status'     => ($member['subscribed'] ?? true) ? 'subscribed' : 'unsubscribed',
                    ];
                }

                $more = $this->_nextLink($r, $rows);

                if ($more !== null) {
                    return ['items' => $items, 'cursor' => ['l' => $l, 'next' => $more]];
                }

                return ['items' => $items, 'cursor' => isset($lists[$l + 1]) ? ['l' => $l + 1] : null];

            case self::STAGE_TEMPLATES:
                $r = $next !== null
                    ? $this->_get($next)
                    : $this->_get("/v3/{$domain}/templates", ['limit' => static::TEMPLATE_PAGE]);

                $rows  = (array) ($r['items'] ?? []);
                $items = [];

                foreach ($rows as $template) {
                    $one = $this->_get(
                        "/v3/{$domain}/templates/" . rawurlencode((string) $template['name']),
                        ['active' => 'yes']
                    );
                    $version = (array) ($one['template']['version'] ?? []);

                    if (!empty($version['template'])) {
                        $headers = (array) ($version['headers'] ?? []);
                        $items[] = [
                            'name'    => (string) $template['name'],
                            'subject' => (string) ($headers['Subject'] ?? $headers['subject'] ?? ''),
                            'html'    => (string) $version['template'],
                        ];
                    }
                }

                $more = $this->_nextLink($r, $rows);

                return ['items' => $items, 'cursor' => $more !== null ? ['next' => $more] : null];
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * Mailgun gives a next link on every page, the last one included: the
     * collection has ended when a page comes back empty, not when the link
     * goes away.
     */
    protected function _nextLink(array $response, array $rows): ?string
    {
        if (!$rows || empty($response['paging']['next'])) {
            return null;
        }

        return (string) $response['paging']['next'];
    }

    /**
     * Every mailing list in the account. Read again on each contacts page:
     * the cursor then only has to hold an index, not every list address.
     *
     * @return array<int, array>
     */
    protected function _lists(): array
    {
        $out = [];

        for ($skip = 0; $skip < 10000; $skip += static::LIST_PAGE) {
            $rows = (array) ($this->_get('/v3/lists', ['limit' => static::LIST_PAGE, 'skip' => $skip])['items'] ?? []);

            foreach ($rows as $row) {
                if (isset($row['address'])) {
                    $out[] = $row;
                }
            }

            if (count($rows) < static::LIST_PAGE) {
                break;
            }
        }

        return $out;
    }

    /**
     * Mailgun's %recipient...% variables and its unsubscribe links. Other
     * recipient variables stay as they are.
     */
    public function convertMergeTags(string $html): string
    {
        return strtr($html, [
            '%unsubscribe_url%'              => '{{unsubscribe_url}}',
            '%mailing_list_unsubscribe_url%' => '{{unsubscribe_url}}',
            '%tag_unsubscribe_url%'          => '{{unsubscribe_url}}',
            '%recipient_fname%'              => '{{customer.firstname}}',
            '%recipient_lname%'              => '{{customer.lastname}}',
            '%recipient_email%'              => '{{customer.email}}',
            '%recipient%'                    => '{{customer.email}}',
            '%recipient.first_name%'         => '{{customer.firstname}}',
            '%recipient.last_name%'          => '{{customer.lastname}}',
            '%recipient.email%'              => '{{customer.email}}',
        ]);
    }
}
