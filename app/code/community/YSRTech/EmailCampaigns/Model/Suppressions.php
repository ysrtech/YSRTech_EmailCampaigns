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
    /** Mailgun's page size cap for these endpoints */
    private const PAGE_SIZE = 1000;

    /** Guards against an unexpected paging loop */
    private const MAX_PAGES = 200;

    /**
     * @return array Counts, by list, of addresses newly suppressed
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
     * Read one of Mailgun's lists into the suppression list.
     *
     * @param  string $list unsubscribes, bounces or complaints
     * @return int How many addresses were not already suppressed here
     */
    protected function _applyList(string $list): int
    {
        $addresses = [];
        $url       = rawurlencode($this->_getDomain()) . '/' . $list . '?limit=' . self::PAGE_SIZE;
        $pages     = 0;

        while ($url && $pages < self::MAX_PAGES) {
            $pages++;
            $response = $this->_get($url);

            foreach ($response['items'] ?? [] as $item) {
                if (!empty($item['address'])) {
                    // A bounce carries the SMTP code and the receiving
                    // server's words; the other two lists carry nothing.
                    $addresses[strtolower(trim((string) $item['address']))] = trim(
                        (string) ($item['code'] ?? '') . ' ' . (string) ($item['error'] ?? '')
                    );
                }
            }

            // Mailgun hands back the same "next" url once the list is done
            $next = $response['paging']['next'] ?? null;
            $url  = ($next && $next !== $url && !empty($response['items'])) ? $next : null;
        }

        if (!$addresses) {
            return 0;
        }

        $result = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression')
            ->addMany($addresses, $list, 'mailgun_sync');

        return $result['added'];
    }

    /**
     * Take addresses back off Mailgun's own lists.
     *
     * Removing one from the list here and leaving it on Mailgun's achieves
     * nothing twice over: Mailgun goes on dropping mail to it, and the next
     * hourly sync reads it straight back in.
     *
     * @param  array<int, array{email:string, reason:string}> $removed
     * @return int How many could not be lifted
     */
    public function liftOnMailgun(array $removed): int
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');

        if (!$removed || strtolower((string) $helper->getConfig('sending/transport')) !== 'mailgun') {
            return 0;
        }

        $lists = [
            YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE => ['bounces'],
            YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT   => ['complaints'],
            YSRTech_EmailCampaigns_Model_Suppression::REASON_UNSUBSCRIBE => ['unsubscribes'],
            // Added by hand here, so on none of Mailgun's lists that we know
            // of - but an import may have brought it from any of them.
            YSRTech_EmailCampaigns_Model_Suppression::REASON_MANUAL      => [],
        ];

        $failed = 0;

        try {
            $key    = $this->_getKey();
            $domain = rawurlencode($this->_getDomain());
        } catch (Exception $e) {
            return count($removed);
        }

        /** @var YSRTech_EmailCampaigns_Model_Mailgun_Api $api */
        $api = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_api');

        foreach ($removed as $row) {
            foreach ($lists[$row['reason']] ?? [] as $list) {
                try {
                    $response = $api->request('DELETE', "{$domain}/{$list}/" . rawurlencode($row['email']), $key);

                    // 404 is the address not being on that list, which is the
                    // state being asked for.
                    if ($response['status'] !== 200 && $response['status'] !== 404) {
                        $failed++;
                    }
                } catch (Exception $e) {
                    $failed++;
                }
            }
        }

        return $failed;
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
     * @param  string $url Relative to the regional API base, or one of
     *                     Mailgun's own paging links
     * @return array
     * @throws Mage_Core_Exception
     */
    protected function _get(string $url): array
    {
        $response = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_api')
            ->request('GET', $url, $this->_getKey());

        $status = $response['status'];
        $body   = $response['body'];

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
                substr($body, 0, 200)
            ));
        }

        if ($status < 200 || $status >= 300) {
            Mage::throwException("Mailgun error (HTTP {$status}): " . substr($body, 0, 300));
        }

        return $response['json'];
    }
}
