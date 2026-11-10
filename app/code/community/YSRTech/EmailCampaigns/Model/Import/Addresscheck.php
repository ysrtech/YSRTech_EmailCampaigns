<?php
/**
 * Import-time checks on an address: is it one at all, is it an obvious typo
 * of a big provider's domain, is it a role address, can its domain receive
 * mail.
 *
 * Each of these is a bounce or a complaint waiting to happen, and a first
 * send to a freshly imported list is exactly when a sending domain's
 * reputation is decided.
 */
class YSRTech_EmailCampaigns_Model_Import_Addresscheck
{
    public const TYPOS = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gamil.com' => 'gmail.com',
        'gnail.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmai.com' => 'gmail.com',
        'gmali.com' => 'gmail.com', 'gmail.om' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com',
        'hotamil.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com',
        'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'yhoo.com' => 'yahoo.com', 'yahoo.cm' => 'yahoo.com',
        'yahoo.om' => 'yahoo.com',
        'outlok.com' => 'outlook.com', 'outllok.com' => 'outlook.com', 'outlook.co' => 'outlook.com',
        'otlook.com' => 'outlook.com',
        'iclod.com' => 'icloud.com', 'icoud.com' => 'icloud.com', 'icloud.co' => 'icloud.com', 'iclould.com' => 'icloud.com',
    ];

    public const ROLES = ['admin', 'administrator', 'info', 'support', 'sales', 'contact', 'help', 'noreply', 'no-reply',
        'donotreply', 'do-not-reply', 'postmaster', 'webmaster', 'hostmaster', 'abuse', 'billing', 'mailer-daemon',
        'root', 'security', 'privacy', 'compliance'];

    /** Domains known to receive mail, so no lookup is spent on them */
    private const KNOWN = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com',
        'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'ymail.com',
        'gmx.com', 'gmx.de', 'yandex.com', 'mail.com', 'zoho.com'];

    /** @var array<string, bool> domain => receives mail, for the life of this run */
    private $_domains = [];

    /** @var bool|null Whether DNS answers at all from here */
    private $_dnsWorks;

    /**
     * @return array{0:string, 1:string|null}|null [stat, suggestion] when the
     *         address should not be imported; null when it is fine
     */
    public function problem(string $email, bool $allowRoles, bool $checkDomain): ?array
    {
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['invalid', null];
        }

        [$local, $domain] = explode('@', $email, 2);

        if (isset(self::TYPOS[$domain])) {
            return ['typo', $local . '@' . self::TYPOS[$domain]];
        }

        if (substr($domain, -4) === '.con') {
            return ['typo', $local . '@' . substr($domain, 0, -4) . '.com'];
        }

        if (!$allowRoles && in_array(strtok($local, '+'), self::ROLES, true)) {
            return ['role', null];
        }

        if ($checkDomain && !$this->receivesMail($domain)) {
            return ['no_mail_server', null];
        }

        return null;
    }

    public function receivesMail(string $domain): bool
    {
        if (in_array($domain, self::KNOWN, true)) {
            return true;
        }

        if (!isset($this->_domains[$domain])) {
            /*
             * If DNS does not answer here at all - a locked-down host, a
             * resolver that is down - every domain would look dead and the
             * whole file would be thrown away. No verdict is better than a
             * wrong one, so the check stands aside.
             */
            if ($this->_dnsWorks === null) {
                $this->_dnsWorks = (bool) @dns_get_record('gmail.com', DNS_MX);
            }

            if (!$this->_dnsWorks) {
                return true;
            }

            // RFC 5321: a domain with no MX is delivered to its A or AAAA
            $this->_domains[$domain] = (bool) (@dns_get_record($domain, DNS_MX)
                ?: @dns_get_record($domain, DNS_A)
                ?: @dns_get_record($domain, DNS_AAAA));
        }

        return $this->_domains[$domain];
    }
}
