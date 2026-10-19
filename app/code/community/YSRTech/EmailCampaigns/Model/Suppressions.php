<?php
/**
 * Brings Mailgun's suppression lists back into the store.
 *
 * Recipients unsubscribe on Mailgun's hosted page and never touch the site, so
 * nothing here would otherwise know they had gone: the queue would carry on
 * enqueueing them every campaign, and the admin's Newsletter Subscribers grid
 * would keep showing them as subscribed. This is the return path - server to
 * server, with no part played by the customer.
 *
 * Bounces and spam complaints are pulled with the unsubscribes. All three mean
 * the same thing for a sending list, and continuing to mail an address after
 * any of them is what damages a domain's reputation.
 */
class YSRTech_EmailCampaigns_Model_Suppressions
{
    private const API_BASE = 'https://api.mailgun.net/v3/';

    /** Mailgun's page size cap for these endpoints */
    private const PAGE_SIZE = 1000;

    /** Guards against an unexpected paging loop */
    private const MAX_PAGES = 200;

    /**
     * @return array Counts, by list, of addresses newly marked unsubscribed
     */
    public function sync(): array
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');

        if (strtolower((string) $helper->getConfig('sending/transport')) !== 'mailgun') {
            return [];
        }

        $counts = [];

        foreach (['unsubscribes', 'bounces', 'complaints'] as $list) {
            try {
                $counts[$list] = $this->_applyList($list);
            } catch (Throwable $e) {
                // One list failing should not stop the others
                Mage::logException($e);
                $counts[$list] = 0;

                /*
                 * Except when the credential is the problem: the other lists
                 * are behind the same key and will fail identically. Trying
                 * them anyway is what turned one misconfigured key into three
                 * exceptions an hour, 438 of them over six days, all saying
                 * the same thing.
                 */
                if ($e instanceof YSRTech_EmailCampaigns_Exception_Auth) {
                    break;
                }
            }
        }

        return $counts;
    }

    /**
     * @param  string $list
     * @return int
     */
    protected function _applyList(string $list): int
    {
        $addresses = [];
        $url       = self::API_BASE . $this->_getDomain() . '/' . $list . '?limit=' . self::PAGE_SIZE;
        $pages     = 0;

        while ($url && $pages < self::MAX_PAGES) {
            $pages++;
            $response = $this->_get($url);

            foreach ($response['items'] ?? [] as $item) {
                if (!empty($item['address'])) {
                    $addresses[] = strtolower(trim((string) $item['address']));
                }
            }

            // Mailgun hands back the same "next" url once the list is done
            $next = $response['paging']['next'] ?? null;
            $url  = ($next && $next !== $url && !empty($response['items'])) ? $next : null;
        }

        return $addresses ? $this->_markUnsubscribed(array_unique($addresses)) : 0;
    }

    /**
     * @param  string[] $addresses
     * @return int Rows changed
     */
    protected function _markUnsubscribed(array $addresses): int
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('newsletter/subscriber');

        $changed = 0;

        // In blocks: the list can run to thousands and an IN() of all of them
        // is a query no database enjoys
        foreach (array_chunk($addresses, 500) as $chunk) {
            $changed += $adapter->update(
                $table,
                [
                    'subscriber_status' => Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED,
                    'change_status_at'  => Varien_Date::now(),
                ],
                [
                    'subscriber_email IN (?)' => $chunk,
                    // Only those still marked subscribed, so the count means
                    // "newly suppressed" rather than "seen again"
                    'subscriber_status = ?'   => Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED,
                ]
            );
        }

        return $changed;
    }

    /**
     * The key these lists are read with.
     *
     * Separate from the sending key on purpose. Reading suppressions needs
     * account-level access, and making that the one credential on the box
     * would mean the key posted with every campaign is also the key that can
     * administer the whole Mailgun account. The sending key stays the narrow
     * one; this is the only thing that needs the broad one.
     *
     * @return string
     */
    protected function _getKey(): string
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');
        $key    = trim((string) $helper->getSecret('sending/suppression_api_key'));

        if ($key === '') {
            // Not set: an account key may well be in the sending field already,
            // which is how this worked before the two were told apart.
            $key = trim((string) $helper->getSecret('sending/api_key'));
        }

        if ($key === '') {
            Mage::throwException('Mailgun API key is not configured.');
        }

        return $key;
    }

    /**
     * Whether a dedicated suppression key is configured, for the error message.
     */
    protected function _usingOwnKey(): bool
    {
        return trim((string) Mage::helper('ysrtech_emailcampaigns')
            ->getSecret('sending/suppression_api_key')) !== '';
    }

    /**
     * @return string
     */
    protected function _getDomain(): string
    {
        $domain = trim((string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/mailgun_domain'));

        if ($domain === '') {
            Mage::throwException('Mailgun sending domain is not configured.');
        }

        return $domain;
    }

    /**
     * @param  string $url
     * @return array
     * @throws Mage_Core_Exception
     */
    protected function _get(string $url): array
    {
        $key = $this->_getKey();

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $key,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Mage::throwException('Mailgun request failed: ' . $error);
        }

        if ($status === 401 || $status === 403) {
            /*
             * Worth spelling out, because the key is usually not wrong - it is
             * the wrong kind of key. Mailgun's domain sending keys reach the
             * sending endpoints and nothing else, so one of them answers 200
             * on /v3/domains/<domain> and 401 on the suppression lists, which
             * reads exactly like a bad credential.
             */
            throw new YSRTech_EmailCampaigns_Exception_Auth(sprintf(
                'Mailgun refused the %s key for the suppression lists (HTTP %d): %s. '
                . 'These lists are outside a domain sending key\'s scope: set a '
                . 'Suppression API Key under Email Campaigns > Sending using an '
                . 'account key, or a role key with suppression permissions.',
                $this->_usingOwnKey() ? 'suppression' : 'sending',
                $status,
                substr((string) $body, 0, 200)
            ));
        }

        if ($status < 200 || $status >= 300) {
            Mage::throwException("Mailgun error (HTTP {$status}): " . substr((string) $body, 0, 300));
        }

        $decoded = Mage::helper('core')->jsonDecode((string) $body);

        return is_array($decoded) ? $decoded : [];
    }
}
