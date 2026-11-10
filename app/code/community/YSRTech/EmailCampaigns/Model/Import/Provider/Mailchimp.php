<?php
/**
 * Mailchimp Marketing API v3.
 *
 * Mailchimp keeps everything per audience, so every stage walks the
 * audiences one after another and the cursor remembers which one it is in.
 * An address that sits in two audiences therefore comes out twice; the
 * importer dedupes.
 *
 * Suppressions are the unsubscribed and cleaned members of every audience,
 * plus each audience's abuse reports. Email HTML comes from sent campaigns:
 * the API gives no HTML for saved templates themselves.
 */
class YSRTech_EmailCampaigns_Model_Import_Provider_Mailchimp
    extends YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
{
    /**
     * Members per request. Mailchimp allows 1000, but a page that size with
     * merge fields and tags can take longer than the request timeout.
     */
    public const PAGE = 500;

    /** Campaigns per templates page: each one costs a second request for its HTML */
    public const CAMPAIGN_PAGE = 10;

    /** The most recent sent campaigns to bring across, in all */
    public const CAMPAIGNS = 25;

    /** Suppression passes per audience, in order: endpoint, member status, reason */
    public const PASSES = [
        ['members', 'unsubscribed', 'unsubscribe'],
        ['members', 'cleaned', 'bounce'],
        ['abuse', null, 'complaint'],
    ];

    /** Mailchimp member status => ours. Anything else never agreed to marketing. */
    public const STATUSES = [
        'subscribed'   => 'subscribed',
        'unsubscribed' => 'unsubscribed',
        'cleaned'      => 'bounced',
    ];

    public function getName(): string
    {
        return 'Mailchimp';
    }

    public function getKeyHelp(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__(
            'In Mailchimp: Profile → Extras → API keys → Create A Key; it ends in something like -us21, and Mailchimp keys cannot be made read-only, so delete it there once the import has finished.'
        );
    }

    /**
     * The data centre is the key's suffix. A key without one cannot be sent
     * anywhere, so it is refused here, before any request is built.
     */
    protected function _host(): string
    {
        if (!preg_match('/-([a-z]{2,3}\d{1,3})$/', $this->_key(), $m)) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                Mage::helper('ysrtech_emailcampaigns')->__(
                    'That does not look like a Mailchimp API key: it should end in something like -us21.'
                )
            );
        }

        return $m[1] . '.api.mailchimp.com';
    }

    protected function _authHeaders(): array
    {
        // Mailchimp ignores the user name; only the key matters
        return ['Authorization: Basic ' . base64_encode('import:' . $this->_key())];
    }

    public function account(): array
    {
        $r = $this->_get('/3.0/', ['fields' => 'account_name']);

        return ['name' => (string) ($r['account_name'] ?? 'Mailchimp account')];
    }

    public function discover(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $contacts = $suppressions = 0;

        foreach ($this->_audiences() as $audience) {
            $contacts     += $audience['members'] + $audience['unsubscribed'] + $audience['cleaned'];
            $suppressions += $audience['unsubscribed'] + $audience['cleaned'];
        }

        $sent = (int) ($this->_get('/3.0/campaigns', [
            'status' => 'sent',
            'count'  => 1,
            'fields' => 'total_items',
        ])['total_items'] ?? 0);

        $notes = [
            $h->__('Unsubscribed and cleaned (bounced) members, and spam complaints, are imported first as suppressions.'),
            $h->__('Pending (unconfirmed) and transactional-only members are not imported as subscribers: they never agreed to marketing.'),
            $h->__('Every audience is read. An address in more than one audience is counted once per audience here, and imported once.'),
            $h->__('Tags come over as tags, and interest groups as tags named "group: ...". Saved segments are not imported.'),
        ];

        if ($sent) {
            $notes[] = $h->__(
                'Your %d most recent sent campaigns come over as templates (Mailchimp gives no HTML for saved templates).',
                min(static::CAMPAIGNS, $sent)
            );
        }

        return [
            'contacts'     => $contacts,
            'suppressions' => $suppressions,
            'templates'    => min(static::CAMPAIGNS, $sent),
            'notes'        => $notes,
        ];
    }

    public function stages(): array
    {
        return [self::STAGE_SUPPRESSIONS, self::STAGE_CONTACTS, self::STAGE_TEMPLATES];
    }

    public function fetch(string $stage, ?array $cursor, array $options = []): array
    {
        $cursor = $cursor ?? [];

        switch ($stage) {
            case self::STAGE_SUPPRESSIONS:
                return $this->_suppressionPage($cursor);
            case self::STAGE_CONTACTS:
                return $this->_contactPage($cursor);
            case self::STAGE_TEMPLATES:
                return $this->_campaignPage($cursor);
        }

        return ['items' => [], 'cursor' => null];
    }

    /**
     * Every audience, with the counts discover() adds up.
     *
     * Read again on each page rather than carried in the cursor: accounts
     * have a handful of audiences, and the cursor only has to hold an index.
     *
     * @return array<int, array{id:string, members:int, unsubscribed:int, cleaned:int}>
     */
    protected function _audiences(): array
    {
        $out    = [];
        $offset = 0;

        do {
            $r = $this->_get('/3.0/lists', [
                'count'  => 1000,
                'offset' => $offset,
                'fields' => 'lists.id,lists.stats.member_count,lists.stats.unsubscribe_count,lists.stats.cleaned_count,total_items',
            ]);

            foreach ((array) ($r['lists'] ?? []) as $list) {
                $out[] = [
                    'id'           => (string) $list['id'],
                    'members'      => (int) ($list['stats']['member_count'] ?? 0),
                    'unsubscribed' => (int) ($list['stats']['unsubscribe_count'] ?? 0),
                    'cleaned'      => (int) ($list['stats']['cleaned_count'] ?? 0),
                ];
            }

            $offset += 1000;
        } while ($offset < (int) ($r['total_items'] ?? 0));

        return $out;
    }

    /**
     * @param array $c l = audience index, p = pass index, o = offset
     */
    protected function _suppressionPage(array $c): array
    {
        $lists = $this->_audiences();
        $l     = (int) ($c['l'] ?? 0);
        $p     = (int) ($c['p'] ?? 0);
        $o     = (int) ($c['o'] ?? 0);

        if (!isset($lists[$l]) || !isset(static::PASSES[$p])) {
            return ['items' => [], 'cursor' => null];
        }

        [$kind, $status, $reason] = static::PASSES[$p];
        $id = rawurlencode($lists[$l]['id']);

        if ($kind === 'members') {
            $r = $this->_get("/3.0/lists/{$id}/members", [
                'status' => $status,
                'count'  => static::PAGE,
                'offset' => $o,
                'fields' => 'members.email_address,total_items',
            ]);
            $rows = (array) ($r['members'] ?? []);
        } else {
            $r = $this->_get("/3.0/lists/{$id}/abuse-reports", [
                'count'  => static::PAGE,
                'offset' => $o,
                'fields' => 'abuse_reports.email_address,total_items',
            ]);
            $rows = (array) ($r['abuse_reports'] ?? []);
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'email'  => (string) ($row['email_address'] ?? ''),
                'reason' => $reason,
                'detail' => $kind === 'abuse' ? 'spam complaint' : $status,
            ];
        }

        if ($o + static::PAGE < (int) ($r['total_items'] ?? 0)) {
            return ['items' => $items, 'cursor' => ['l' => $l, 'p' => $p, 'o' => $o + static::PAGE]];
        }

        if (isset(static::PASSES[$p + 1])) {
            return ['items' => $items, 'cursor' => ['l' => $l, 'p' => $p + 1, 'o' => 0]];
        }

        return ['items' => $items, 'cursor' => isset($lists[$l + 1]) ? ['l' => $l + 1, 'p' => 0, 'o' => 0] : null];
    }

    /**
     * @param array $c l = audience index, o = offset, g = interest id => "group: name"
     */
    protected function _contactPage(array $c): array
    {
        $lists = $this->_audiences();
        $l     = (int) ($c['l'] ?? 0);
        $o     = (int) ($c['o'] ?? 0);

        if (!isset($lists[$l])) {
            return ['items' => [], 'cursor' => null];
        }

        $id = rawurlencode($lists[$l]['id']);

        /*
         * Members carry interest ids, not names. The names are looked up once
         * per audience and ride along in the cursor for its later pages.
         */
        $groups = isset($c['g']) && is_array($c['g']) ? $c['g'] : $this->_interestGroups($id);

        $r = $this->_get("/3.0/lists/{$id}/members", [
            'count'  => static::PAGE,
            'offset' => $o,
            'fields' => 'members.email_address,members.status,members.merge_fields,members.tags,members.interests,total_items',
        ]);

        $items = [];

        foreach ((array) ($r['members'] ?? []) as $member) {
            $merge = (array) ($member['merge_fields'] ?? []);
            $tags  = [];

            foreach ((array) ($member['tags'] ?? []) as $tag) {
                $tags[] = (string) ($tag['name'] ?? '');
            }

            foreach ((array) ($member['interests'] ?? []) as $interestId => $on) {
                if ($on && isset($groups[$interestId])) {
                    $tags[] = (string) $groups[$interestId];
                }
            }

            $items[] = [
                'email'      => (string) ($member['email_address'] ?? ''),
                'first_name' => $merge['FNAME'] ?? null,
                'last_name'  => $merge['LNAME'] ?? null,
                'fields'     => array_diff_key($merge, ['FNAME' => 1, 'LNAME' => 1, 'EMAIL' => 1]),
                'tags'       => array_values(array_filter($tags, 'strlen')),
                'status'     => static::STATUSES[$member['status'] ?? ''] ?? 'pending',
            ];
        }

        $total = 0;

        foreach ($lists as $list) {
            $total += $list['members'] + $list['unsubscribed'] + $list['cleaned'];
        }

        if ($o + static::PAGE < (int) ($r['total_items'] ?? 0)) {
            $next = ['l' => $l, 'o' => $o + static::PAGE, 'g' => $groups];
        } else {
            $next = isset($lists[$l + 1]) ? ['l' => $l + 1, 'o' => 0] : null;
        }

        return ['items' => $items, 'cursor' => $next, 'total' => $total];
    }

    /**
     * @param  string $id Audience id, already URL-encoded
     * @return array<string, string> interest id => "group: name"
     */
    protected function _interestGroups(string $id): array
    {
        $groups     = [];
        $categories = $this->_get("/3.0/lists/{$id}/interest-categories", [
            'count'  => 100,
            'fields' => 'categories.id',
        ]);

        foreach ((array) ($categories['categories'] ?? []) as $category) {
            $interests = $this->_get(
                "/3.0/lists/{$id}/interest-categories/" . rawurlencode((string) $category['id']) . '/interests',
                ['count' => 100, 'fields' => 'interests.id,interests.name']
            );

            foreach ((array) ($interests['interests'] ?? []) as $interest) {
                $groups[(string) $interest['id']] = 'group: ' . mb_substr((string) $interest['name'], 0, 55);
            }
        }

        return $groups;
    }

    /**
     * Sent campaigns, newest first, a few at a time: the HTML of each is a
     * request of its own.
     *
     * @param array $c o = offset
     */
    protected function _campaignPage(array $c): array
    {
        $o     = (int) ($c['o'] ?? 0);
        $count = min(static::CAMPAIGN_PAGE, static::CAMPAIGNS - $o);

        if ($count <= 0) {
            return ['items' => [], 'cursor' => null];
        }

        $r = $this->_get('/3.0/campaigns', [
            'status'     => 'sent',
            'count'      => $count,
            'offset'     => $o,
            'sort_field' => 'send_time',
            'sort_dir'   => 'DESC',
            'fields'     => 'campaigns.id,campaigns.settings.subject_line,campaigns.settings.title,total_items',
        ]);

        $items = [];

        foreach ((array) ($r['campaigns'] ?? []) as $campaign) {
            $content = $this->_get(
                '/3.0/campaigns/' . rawurlencode((string) $campaign['id']) . '/content',
                ['fields' => 'html']
            );

            if (empty($content['html'])) {
                continue;
            }

            $subject = (string) ($campaign['settings']['subject_line'] ?? '');
            $title   = (string) ($campaign['settings']['title'] ?? '');

            $items[] = [
                'name'    => $title !== '' ? $title : ($subject !== '' ? $subject : 'Mailchimp campaign'),
                'subject' => $subject,
                'html'    => (string) $content['html'],
            ];
        }

        $next  = $o + $count;
        $total = min(static::CAMPAIGNS, (int) ($r['total_items'] ?? 0));

        return ['items' => $items, 'cursor' => $next < $total ? ['o' => $next] : null, 'total' => $total];
    }

    /**
     * *|FNAME|* and friends. Mailchimp tags carry no default (those live in
     * the audience settings), so none comes across. Conditionals, custom
     * merge fields and Mailchimp's own system tags have no equivalent and
     * stay as they are.
     */
    public function convertMergeTags(string $html): string
    {
        $map = [
            'FNAME'        => '{{customer.firstname}}',
            'LNAME'        => '{{customer.lastname}}',
            'EMAIL'        => '{{customer.email}}',
            'UNSUB'        => '{{unsubscribe_url}}',
            'LIST:COMPANY' => '{{store.name}}',
            'USER:COMPANY' => '{{store.name}}',
        ];

        return (string) preg_replace_callback(
            '/\*\|(?:(?:TITLE|LOWER|UPPER):)?(FNAME|LNAME|EMAIL)\|\*|\*\|(UNSUB|LIST:COMPANY|USER:COMPANY)\|\*/',
            static function (array $m) use ($map) {
                return $map[$m[2] ?? ''] ?? $map[$m[1]];
            },
            $html
        );
    }
}
