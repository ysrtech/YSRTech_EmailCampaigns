<?php
/**
 * The sender check on the dashboard: is this store set up the way mailbox
 * providers require of a bulk sender, and is what it sends being received
 * well.
 *
 * Every finding is computed from something real: the domain's published DNS,
 * Mailgun's own record of the domain where the key may read it, the saved
 * settings, and the last 30 days of this module's own send log. The limits
 * are the ones Google and Yahoo publish for bulk senders. Nothing here is a
 * guess and no AI is involved; where something cannot be checked it says so
 * rather than passing or failing it.
 *
 * Score = 100 less each finding's weight.
 */
class YSRTech_EmailCampaigns_Model_Deliverability
{
    public const CACHE_KEY = 'ysrtech_emailcampaigns_deliverability';
    public const DAYS      = 30;

    /** Selectors Mailgun has issued over the years, tried when its API cannot be asked */
    private const DKIM_SELECTORS = ['k1', 'pic', 'mx', 'smtp', 'mailo', 'krs', 'email', 's1', 'mg', 'pdk1', 'pdk2'];

    /** Suffixes under which a registered domain has three labels, not two */
    private const TWO_PART_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.za', 'co.il', 'org.il', 'ac.il',
        'com.br', 'com.mx', 'co.jp', 'co.in', 'com.sg', 'com.hk', 'com.tr', 'com.ar', 'co.kr',
    ];

    /** @var callable|null fn(string $host): ?string[] */
    private $_resolver;

    /** @var callable|null fn(string $domain): ?array */
    private $_mailgun;

    /**
     * Replace the DNS lookup: fn(host) returns the TXT records there, an
     * empty array for none, null when the lookup itself failed. For tests.
     */
    public function setResolver(?callable $resolver): self
    {
        $this->_resolver = $resolver;

        return $this;
    }

    /**
     * Replace the question put to Mailgun about the domain: fn(domain)
     * returns its sending_dns_records, or null when they cannot be read.
     */
    public function setMailgunLookup(?callable $lookup): self
    {
        $this->_mailgun = $lookup;

        return $this;
    }

    /**
     * The last result, if a check has been run in the past day.
     */
    public function latest(): ?array
    {
        $cached = Mage::app()->getCache()->load(self::CACHE_KEY);
        $result = $cached ? json_decode((string) $cached, true) : null;

        return is_array($result) ? $result : null;
    }

    /**
     * Run the check now and keep the result for the dashboard.
     */
    public function refresh(): array
    {
        $result = $this->run();

        Mage::app()->getCache()->save((string) json_encode($result), self::CACHE_KEY, [], 86400);

        return $result;
    }

    /**
     * @return array{score:int, checked_at:string, from_domain:string, sending_domain:string,
     *               findings:array<int, array{level:string, title:string, text:string, fix:string, weight:int}>,
     *               passed:string[], unknown:string[]}
     */
    public function run(): array
    {
        $h         = Mage::helper('ysrtech_emailcampaigns');
        $findings  = [];
        $passed    = [];
        $unknown   = [];
        // $action: a fix that can be made from here with one press (see Mailgun_Tracking)
        $add       = static function (string $level, int $weight, string $title, string $text, string $fix = '', string $action = '') use (&$findings): void {
            $findings[] = ['level' => $level, 'weight' => $weight, 'title' => $title, 'text' => $text, 'fix' => $fix, 'action' => $action];
        };

        $transport = (string) $h->getConfig('sending/transport');
        $from      = YSRTech_EmailCampaigns_Model_Campaign::senderDomain();
        $sending   = $transport === 'mailgun' ? strtolower(trim((string) $h->getConfig('sending/mailgun_domain'))) : $from;
        $sending   = $sending !== '' ? $sending : $from;

        if ($from === '') {
            $add('bad', 40, $h->__('No sender address is set.'), $h->__('There is no Default From Email in the Sending settings, so nothing can be checked and nothing should be sent.'), $h->__('Set Default From Email under System > Configuration > Email Campaigns > Sending.'));

            return $this->_result($findings, $passed, $unknown, $from, $sending);
        }

        // ---- the From address has to belong with the domain that signs
        if (self::registeredDomain($from) !== self::registeredDomain($sending)) {
            $add('bad', 25, $h->__('The From address is on a different domain from the one that sends.'),
                $h->__('Emails say they are from %s but are signed and sent by %s. DMARC needs the two to be the same organisation\'s domain, and Gmail and Yahoo reject bulk mail that fails it.', $from, $sending),
                $h->__('Use a From address at %s or one of its subdomains, or change the Mailgun sending domain to match.', self::registeredDomain($sending)));
        } else {
            $passed[] = $h->__('The From address (%s) matches the sending domain (%s).', $from, $sending);
        }

        // ---- SPF, on the domain the mail is actually sent as
        $spf = $this->_txt($sending);

        if ($spf === null) {
            $unknown[] = $h->__('SPF: the DNS lookup for %s did not answer.', $sending);
        } else {
            $records = array_values(array_filter($spf, static fn ($r) => stripos($r, 'v=spf1') === 0));

            if (!$records) {
                $add('bad', 15, $h->__('No SPF record on %s.', $sending),
                    $h->__('SPF tells mailbox providers which servers may send for the domain. Without it mail is more likely to be filed as spam.'),
                    $transport === 'mailgun'
                        ? $h->__('Add the TXT record Mailgun shows for this domain under Sending > Domains > DNS records (it reads v=spf1 include:mailgun.org ~all).')
                        : $h->__('Add a TXT record starting v=spf1 that lists the server this store sends through.'));
            } elseif (count($records) > 1) {
                $add('bad', 10, $h->__('%s has %d SPF records.', $sending, count($records)),
                    $h->__('A domain may have only one. With more than one, providers treat SPF as failed.'),
                    $h->__('Merge them into a single v=spf1 record.'));
            } elseif ($transport === 'mailgun' && stripos($records[0], 'mailgun.org') === false) {
                $add('warn', 10, $h->__('The SPF record on %s does not include Mailgun.', $sending),
                    $h->__('It reads: %s', mb_substr($records[0], 0, 200)),
                    $h->__('Add include:mailgun.org to it, before the ~all or -all at the end.'));
            } else {
                $passed[] = $h->__('SPF is published on %s.', $sending);
            }
        }

        // ---- DKIM
        $dkim = $transport === 'mailgun' ? $this->_dkim($sending) : null;

        if ($dkim === true) {
            $passed[] = $h->__('DKIM signing is set up for %s.', $sending);
        } elseif ($dkim === false) {
            $add('bad', 25, $h->__('DKIM is not verified for %s.', $sending),
                $h->__('Mailgun reports the domain\'s DKIM record as missing or wrong, so mail goes out unsigned. Gmail and Yahoo require a valid signature from bulk senders.'),
                $h->__('Publish the DKIM TXT record from Mailgun under Sending > Domains > DNS records, then press Verify there.'));
        } else {
            $unknown[] = $transport === 'mailgun'
                ? $h->__('DKIM: could not be confirmed from here. The sending key may not read the domain\'s records; check that Mailgun shows the domain as Verified.')
                : $h->__('DKIM: only checked when Mailgun is the transport.');
        }

        // ---- DMARC, on the From domain or the organisation's domain above it
        $dmarcHost = '_dmarc.' . $from;
        $dmarc     = $this->_dmarc($from);

        if ($dmarc === null) {
            $unknown[] = $h->__('DMARC: the DNS lookup for %s did not answer.', $dmarcHost);
        } elseif ($dmarc === '') {
            $add('bad', 20, $h->__('No DMARC record for %s.', $from),
                $h->__('Since February 2024 Gmail and Yahoo require a DMARC record from anyone sending in bulk. Without one, mail is throttled or rejected.'),
                $h->__('Add a TXT record at _dmarc.%s reading: v=DMARC1; p=none; rua=mailto:dmarc@%s', self::registeredDomain($from), self::registeredDomain($from)));
        } else {
            $policy = strtolower(self::tag($dmarc, 'p') ?? '');

            if (!in_array($policy, ['none', 'quarantine', 'reject'], true)) {
                $add('bad', 15, $h->__('The DMARC record for %s has no valid policy.', $from), $h->__('It reads: %s', mb_substr($dmarc, 0, 200)),
                    $h->__('It needs p=none, p=quarantine or p=reject.'));
            } else {
                $passed[] = $h->__('DMARC is published for %s (policy: %s).', $from, $policy);

                if ($policy === 'none') {
                    $add('info', 3, $h->__('DMARC is in monitoring mode (p=none).'),
                        $h->__('That meets the requirement, but it does not stop anyone else sending as %s.', $from),
                        $h->__('Once SPF and DKIM have passed for a few weeks, move to p=quarantine.'));
                }

                if (self::tag($dmarc, 'rua') === null) {
                    $add('info', 2, $h->__('DMARC reports are not being sent anywhere.'),
                        $h->__('The record has no rua address, so nobody is told when mail fails authentication.'),
                        $h->__('Add rua=mailto:an-address-you-read to the record.'));
                }
            }
        }

        // ---- the webhook: without it bounces and complaints are never heard
        if ($transport === 'mailgun') {
            if (trim((string) $h->getSecret('sending/mailgun_webhook_signing_key')) === '') {
                $add('bad', 15, $h->__('No Mailgun webhook signing key is saved.'),
                    $h->__('Every webhook is refused without it, so bounces and spam complaints are not recorded and those addresses keep being mailed.'),
                    $h->__('Copy the HTTP webhook signing key from Mailgun into the Sending settings.'));
            } else {
                $passed[] = $h->__('Webhooks are signed, so bounces and complaints can be recorded.');
            }
        } else {
            $unknown[] = $h->__('Bounces and complaints: the %s transport does not report them back, so the rates below cannot be measured.', $transport ?: 'smtp');
        }

        // ---- two settings of the Mailgun domain that show in every email
        if ($transport === 'mailgun') {
            $tracking = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_tracking')->state();

            if ($tracking === null) {
                $unknown[] = $h->__('Mailgun\'s tracking settings for %s could not be read with the saved API key.', $sending);
            } else {
                if ($tracking['footer']) {
                    $add('warn', 5, $h->__('Mailgun adds a second unsubscribe link to every email.'),
                        $h->__('The domain has an unsubscribe footer of its own, which Mailgun appends under your design: a plain "unsubscribe" at the bottom left, on top of the link already in your footer.'),
                        $h->__('Remove Mailgun\'s footer. Unsubscribe tracking stays on, so the link in your design keeps working.'), 'footer');
                } else {
                    $passed[] = $h->__('Mailgun adds no unsubscribe footer of its own: the only link is the one in your design.');
                }

                if ($h->linksGoDirect()) {
                    $passed[] = $h->__('Links go straight to your store over https, and the unsubscribe link is your store\'s own page. Clicks are counted on arrival.');
                } elseif ($tracking['scheme'] !== 'https') {
                    $add('warn', 10, $h->__('Links in your emails open over http, not https.'),
                        $h->__('Mailgun passes every link, and the unsubscribe link, through its tracking address for %s, and that address is set to http. Browsers warn before opening it, and some mail apps mark the email as unsafe.', $sending),
                        $h->__('Either send links straight to your store, which needs nothing from Mailgun: links are https, clicks are counted when the visitor arrives, and only clicks on links to other websites go uncounted. Or switch Mailgun\'s tracking address to https, which needs a Mailgun plan that issues certificates and two presses a few minutes apart.'), 'https');
                } else {
                    $passed[] = $h->__('Tracked links and the unsubscribe link use https.');
                }
            }
        }

        // ---- how the last 30 days were received
        $S      = YSRTech_EmailCampaigns_Model_Dashboard_Stats::class;
        $totals = Mage::getSingleton('ysrtech_emailcampaigns/dashboard_stats')->compute(self::DAYS)['totals'];
        $sent   = (int) $totals['sent'];

        if ($sent < $S::MIN_SENT_TO_JUDGE) {
            $unknown[] = $h->__('Bounce and complaint rates: only %s sent in the last %d days, too few to judge.', number_format($sent), self::DAYS);
        } else {
            $bounce    = $S::rate($totals['bounced'], $totals['sent']);
            $complaint = $S::rate($totals['complained'], $totals['delivered']);

            if ($complaint >= $S::COMPLAINT_LIMIT) {
                $add('bad', 30, $h->__('Spam complaints are %s.', $S::percent($complaint, 2)),
                    $h->__('Google and Yahoo set 0.3%% as the limit for bulk senders; above it mail goes to spam for everyone.'),
                    $h->__('Send only to people who opened or ordered recently until this falls, and make the unsubscribe link easy to find.'));
            } elseif ($complaint >= $S::COMPLAINT_WARN) {
                $add('warn', 10, $h->__('Spam complaints are %s.', $S::percent($complaint, 2)),
                    $h->__('Under the 0.3%% limit but above the 0.1%% providers ask senders to stay below.'),
                    $h->__('Exclude subscribers who have not opened in six months from the next campaign.'));
            } else {
                $passed[] = $h->__('Spam complaints are %s over the last %d days, under 0.1%%.', $S::percent($complaint, 2), self::DAYS);
            }

            if ($bounce >= $S::BOUNCE_LIMIT) {
                $add('bad', 25, $h->__('Bounces are %s.', $S::percent($bounce)),
                    $h->__('Above 5%% a sending account is put under review, and providers read it as a list that was not collected properly.'),
                    $h->__('Check the most recent import, and remove addresses that have never opened.'));
            } elseif ($bounce >= $S::BOUNCE_WARN) {
                $add('warn', 10, $h->__('Bounces are %s.', $S::percent($bounce)),
                    $h->__('Above the 2%% a healthy list stays under.'),
                    $h->__('Bounced addresses are suppressed automatically; if this stays high, look at where new addresses come from.'));
            } else {
                $passed[] = $h->__('Bounces are %s over the last %d days, under 2%%.', $S::percent($bounce), self::DAYS);
            }
        }

        return $this->_result($findings, $passed, $unknown, $from, $sending);
    }

    private function _result(array $findings, array $passed, array $unknown, string $from, string $sending): array
    {
        usort($findings, static fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return [
            'score'          => max(0, 100 - array_sum(array_column($findings, 'weight'))),
            'checked_at'     => gmdate('Y-m-d H:i:s'),
            'from_domain'    => $from,
            'sending_domain' => $sending,
            'findings'       => $findings,
            'passed'         => $passed,
            'unknown'        => $unknown,
        ];
    }

    /**
     * The DMARC record that applies to a domain: its own, or failing that
     * the one on the registered domain above it. '' for none, null when DNS
     * did not answer.
     */
    private function _dmarc(string $domain): ?string
    {
        $failed = false;

        foreach (array_unique([$domain, self::registeredDomain($domain)]) as $candidate) {
            $records = $this->_txt('_dmarc.' . $candidate);

            if ($records === null) {
                $failed = true;
                continue;
            }

            foreach ($records as $record) {
                if (stripos($record, 'v=DMARC1') === 0) {
                    return $record;
                }
            }
        }

        return $failed ? null : '';
    }

    /**
     * Whether DKIM is in place: true, false, or null for "cannot tell".
     * Mailgun's own verdict where the key may read it; otherwise a look for
     * a key under the selectors Mailgun issues, which can confirm one exists
     * but cannot prove one does not.
     */
    private function _dkim(string $domain): ?bool
    {
        $records = $this->_mailgunRecords($domain);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (is_array($record) && stripos((string) ($record['name'] ?? ''), '_domainkey') !== false) {
                    return ($record['valid'] ?? '') === 'valid';
                }
            }
        }

        foreach (self::DKIM_SELECTORS as $selector) {
            foreach ($this->_txt($selector . '._domainkey.' . $domain) ?? [] as $record) {
                if (stripos($record, 'p=') !== false && (stripos($record, 'k=rsa') !== false || stripos($record, 'v=DKIM1') !== false)) {
                    return true;
                }
            }
        }

        return null;
    }

    private function _mailgunRecords(string $domain): ?array
    {
        if ($this->_mailgun) {
            return call_user_func($this->_mailgun, $domain);
        }

        $h = Mage::helper('ysrtech_emailcampaigns');

        // The account-level key if there is one: a domain sending key is not
        // allowed to read the domain's record
        foreach (array_unique(array_filter([$h->getSecret('sending/suppression_api_key'), $h->getSecret('sending/api_key')])) as $key) {
            try {
                $response = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_api')->request('GET', 'domains/' . rawurlencode($domain), $key);

                if ($response['status'] === 200 && is_array($response['json']['sending_dns_records'] ?? null)) {
                    return $response['json']['sending_dns_records'];
                }
            } catch (Throwable $e) {
                // falls through to the DNS look
            }
        }

        return null;
    }

    /**
     * @return string[]|null TXT records at a host; null when the lookup failed
     */
    private function _txt(string $host): ?array
    {
        if ($this->_resolver) {
            return call_user_func($this->_resolver, $host);
        }

        $records = @dns_get_record($host, DNS_TXT);

        if ($records === false) {
            return null;
        }

        return array_values(array_map(
            static fn ($r) => isset($r['entries']) ? implode('', (array) $r['entries']) : (string) ($r['txt'] ?? ''),
            $records
        ));
    }

    /**
     * One tag out of a DMARC record: tag('v=DMARC1; p=none', 'p') is 'none'.
     */
    public static function tag(string $record, string $tag): ?string
    {
        foreach (explode(';', $record) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) === 2 && strtolower(trim($pair[0])) === strtolower($tag)) {
                return trim($pair[1]);
            }
        }

        return null;
    }

    /**
     * The domain somebody registered: news.example.com is example.com,
     * mail.example.co.uk is example.co.uk.
     */
    public static function registeredDomain(string $domain): string
    {
        $labels = explode('.', strtolower(trim($domain, '. ')));
        $count  = count($labels);

        if ($count <= 2) {
            return implode('.', $labels);
        }

        $keep = in_array($labels[$count - 2] . '.' . $labels[$count - 1], self::TWO_PART_SUFFIXES, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }
}
