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
        $key = (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/api_key');

        if ($key === '') {
            Mage::throwException('Mailgun API key is not configured.');
        }

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

        if ($status < 200 || $status >= 300) {
            Mage::throwException("Mailgun error (HTTP {$status}): " . substr((string) $body, 0, 300));
        }

        $decoded = Mage::helper('core')->jsonDecode((string) $body);

        return is_array($decoded) ? $decoded : [];
    }
}
