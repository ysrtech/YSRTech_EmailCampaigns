<?php
/**
 * Two settings of a Mailgun sending domain that show in every email, read
 * and put right from here.
 *
 * The unsubscribe footer. With unsubscribe tracking on - and it has to be
 * on, or the %unsubscribe_url% in a template is delivered as those very
 * characters - Mailgun can also append a footer of its own to every message:
 * a second, unstyled "unsubscribe" under the design. The template already
 * has the link where it belongs, so the footer is emptied and tracking left
 * on.
 *
 * The scheme of tracked links. Mailgun rewrites every link (and its
 * unsubscribe link) to pass through the domain's tracking host, and that
 * address is http unless the domain is told otherwise. Browsers warn about
 * an http link opened from an https mailbox. https needs a certificate for
 * the tracking host, which Mailgun issues itself once asked; the scheme is
 * switched when the certificate is there.
 */
class YSRTech_EmailCampaigns_Model_Mailgun_Tracking
{
    /** @var callable|null fn(string $method, string $url, ?array $fields): array{status:int, json:array, body:string} */
    protected $_requester;

    /**
     * Replace the calls to Mailgun, for a test.
     */
    public function setRequester(?callable $requester): self
    {
        $this->_requester = $requester;

        return $this;
    }

    public function domain(): string
    {
        return strtolower(trim((string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/mailgun_domain')));
    }

    /**
     * What the domain is set to now.
     *
     * @return array{footer:bool, scheme:string, unsubscribe:bool}|null null when Mailgun could not be asked
     */
    public function state(): ?array
    {
        $domain = $this->domain();

        if ($domain === '') {
            return null;
        }

        $response = $this->_request('GET', $this->_base() . 'v3/domains/' . rawurlencode($domain) . '/tracking');

        if ($response === null || $response['status'] !== 200 || !is_array($response['json']['tracking'] ?? null)) {
            return null;
        }

        $tracking    = $response['json']['tracking'];
        $unsubscribe = (array) ($tracking['unsubscribe'] ?? []);

        return [
            'unsubscribe' => !empty($unsubscribe['active']),
            'footer'      => trim((string) ($unsubscribe['html_footer'] ?? '')) !== '' || trim((string) ($unsubscribe['text_footer'] ?? '')) !== '',
            'scheme'      => strtolower((string) ($tracking['web_scheme'] ?? 'http')) === 'https' ? 'https' : 'http',
        ];
    }

    /**
     * Empty Mailgun's own unsubscribe footer, leaving unsubscribe tracking on.
     *
     * @return array{ok:bool, message:string}
     */
    public function clearFooter(): array
    {
        $h      = Mage::helper('ysrtech_emailcampaigns');
        $domain = $this->domain();
        $url    = $this->_base() . 'v3/domains/' . rawurlencode($domain) . '/tracking/unsubscribe';

        // Tracking stays on: it is what fills in the link in the template
        $response = $this->_request('PUT', $url, ['active' => 'true', 'html_footer' => '', 'text_footer' => '']);

        if ($response === null) {
            return ['ok' => false, 'message' => $h->__('Mailgun could not be reached with the saved API key.')];
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return ['ok' => false, 'message' => $h->__('Mailgun refused the change (HTTP %d): %s', $response['status'], $this->_reason($response))];
        }

        // Asked again rather than believed: what matters is what Mailgun now holds
        $state = $this->state();

        if ($state === null) {
            return ['ok' => false, 'message' => $h->__('The change was sent, but the setting could not be read back to confirm it.')];
        }

        if ($state['footer']) {
            return ['ok' => false, 'message' => $h->__('Mailgun accepted the request but still has a footer set. Clear it by hand: Mailgun > Sending > Domain settings > %s > Tracking > Unsubscribes, and empty both footer boxes.', $domain)];
        }

        if (!$state['unsubscribe']) {
            return ['ok' => false, 'message' => $h->__('The footer is gone, but unsubscribe tracking is now off for %s. Turn it back on in Mailgun before sending, or the unsubscribe link in your emails will not work.', $domain)];
        }

        return ['ok' => true, 'message' => $h->__('Mailgun\'s own unsubscribe footer is removed for %s. Emails sent from now on have only the unsubscribe link in your design.', $domain)];
    }

    /**
     * Move the domain's tracked links to https.
     *
     * In two presses if the tracking host has no certificate yet: the first
     * asks Mailgun to issue one, which takes it a few minutes; the second,
     * once it is there, switches the scheme.
     *
     * @return array{ok:bool, pending:bool, message:string}
     */
    public function useHttps(): array
    {
        $h      = Mage::helper('ysrtech_emailcampaigns');
        $domain = $this->domain();
        $fail   = static fn (string $message) => ['ok' => false, 'pending' => false, 'message' => $message];

        $info = $this->_request('GET', $this->_base() . 'v4/domains/' . rawurlencode($domain));

        if ($info === null || $info['status'] !== 200) {
            return $fail($h->__('Mailgun could not be asked about %s with the saved API key. An account-level key is needed for this: save one as the Suppression Sync API Key in the Sending settings.', $domain));
        }

        $prefix = trim((string) ($info['json']['domain']['web_prefix'] ?? '')) ?: 'email';
        $host   = $prefix . '.' . $domain;
        $x509   = $this->_base() . 'v2/x509/' . rawurlencode($host);

        $status = $this->_request('GET', $x509 . '/status');
        $have   = $status !== null && $status['status'] === 200 ? strtolower((string) ($status['json']['status'] ?? '')) : '';

        if ($have === 'processing') {
            return ['ok' => true, 'pending' => true, 'message' => $h->__('Mailgun is still issuing the certificate for %s. Give it a few minutes and press the button again.', $host)];
        }

        if ($have !== 'active') {
            // None yet, or one that expired or failed: ask for one
            $made = $this->_request($have === 'expired' ? 'PUT' : 'POST', $x509);

            // Not a fault to chase in DNS: the account's plan does not include it
            if ($made !== null && ($made['status'] === 402 || stripos($this->_reason($made), 'upgrade') !== false)) {
                return $fail($h->__('Mailgun does not issue https certificates on this account\'s plan ("%s"). Either upgrade the Mailgun plan, or set "Links In Emails Go" to "Straight to the store" in the Tracking settings: links then go directly to your store over https and need no certificate.', $this->_reason($made)));
            }

            if ($made === null || $made['status'] < 200 || $made['status'] >= 300) {
                return $fail($h->__('Mailgun could not issue a certificate for %s: %s Check that %s is a CNAME to mailgun.org and, if it goes through Cloudflare, that it is set to "DNS only" (grey cloud).',
                    $host, $made === null ? '' : $this->_reason($made), $host));
            }

            return ['ok' => true, 'pending' => true, 'message' => $h->__('Mailgun has been asked for a certificate for %s. It takes a few minutes: press the button again then, and the links are switched to https.', $host)];
        }

        $set = $this->_request('PUT', $this->_base() . 'v4/domains/' . rawurlencode($domain), ['web_scheme' => 'https']);

        if ($set === null || $set['status'] < 200 || $set['status'] >= 300) {
            return $fail($h->__('Mailgun refused to switch %s to https: %s', $domain, $set === null ? '' : $this->_reason($set)));
        }

        $state = $this->state();

        if ($state === null || $state['scheme'] !== 'https') {
            return $fail($h->__('Mailgun accepted the request but the links of %s are still http. Set it by hand: Mailgun > Sending > Domain settings > %s > Tracking, protocol https.', $domain, $domain));
        }

        return ['ok' => true, 'pending' => false, 'message' => $h->__('Tracked links and the unsubscribe link of %s now use https. This applies to emails sent from now on; links in emails already delivered keep working as they were.', $domain)];
    }

    protected function _base(): string
    {
        // "https://api.mailgun.net/v3/" without its version: these calls span v2, v3 and v4
        return (string) preg_replace('#v3/$#', '', Mage::helper('ysrtech_emailcampaigns')->getMailgunApiBase());
    }

    private function _reason(array $response): string
    {
        return mb_substr((string) ($response['json']['message'] ?? $response['body'] ?? ''), 0, 200);
    }

    /**
     * With the account-level key first: a domain's sending key is not allowed
     * to read or change the domain's settings.
     *
     * @return array{status:int, json:array, body:string}|null null when no key could make the call
     */
    protected function _request(string $method, string $url, ?array $fields = null): ?array
    {
        if ($this->_requester) {
            return call_user_func($this->_requester, $method, $url, $fields);
        }

        $h    = Mage::helper('ysrtech_emailcampaigns');
        $last = null;

        foreach (array_unique(array_filter([$h->getSecret('sending/suppression_api_key'), $h->getSecret('sending/api_key')])) as $key) {
            try {
                $last = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_api')->request($method, $url, $key, null, $fields);

                // Not this key's to do: try the next
                if (!in_array($last['status'], [401, 403], true)) {
                    return $last;
                }
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }

        return $last;
    }
}
