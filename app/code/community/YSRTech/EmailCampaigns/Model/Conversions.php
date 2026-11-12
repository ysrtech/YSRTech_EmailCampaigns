<?php
/**
 * Which orders an email earned.
 *
 * Three moving parts:
 *
 *  1. When an email goes out, every link to the store is tagged with the
 *     message's own token (and, optionally, the usual utm_ labels).
 *  2. When somebody arrives on the store through such a link, the token is
 *     kept in a cookie for the attribution window.
 *  3. When an order is stored, it is credited to that message. With no
 *     cookie - a click on the phone, the order on a laptop - it is credited
 *     to the last email that address clicked inside the window instead, and
 *     marked as the weaker evidence it is.
 *
 * One order, one email: the last one clicked. Opens earn nothing; mail apps
 * load images on their own, and an open says very little about a sale.
 *
 * Revenue is what the goods sold for: subtotal less discounts, without
 * shipping or tax, less refunds, read from the order as it stands so a
 * refund or a cancellation is reflected the next time anyone looks.
 */
class YSRTech_EmailCampaigns_Model_Conversions
{
    public const PARAM  = 'ec';
    public const COOKIE = 'ec_click';

    /** How the order was tied to the email */
    public const VIA_LINK    = 'link';     // arrived through a tagged link, same browser
    public const VIA_ADDRESS = 'address';  // same email address clicked, then ordered elsewhere

    /** A new order is looked at for this long after it was created, then left alone */
    private const FRESH_SECONDS = 900;

    /** @var array<int, bool> orders already looked at in this request */
    private $_seen = [];

    /** @var string[]|null */
    private $_hosts;

    public function isEnabled($storeId = null): bool
    {
        return (bool) Mage::helper('ysrtech_emailcampaigns')->getConfig('tracking/conversions', $storeId);
    }

    public function windowDays($storeId = null): int
    {
        return max(1, min(90, (int) Mage::helper('ysrtech_emailcampaigns')->getConfig('tracking/conversion_days', $storeId) ?: 5));
    }

    // ------------------------------------------------------------------
    // 1. Tagging the links of an email
    // ------------------------------------------------------------------

    /**
     * Tag every link to the store in an email's HTML.
     *
     * @param string      $html
     * @param string      $token  the message's tracking token, or the provider's per-recipient
     *                            placeholder for it where one body is shared by many people
     * @param string      $label  what the email is called, for utm_campaign
     * @param int|null    $storeId
     */
    public function tagLinks(string $html, string $token, string $label = '', $storeId = null): string
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        // The token is also how a click is counted when links go straight to the store
        if ($html === '' || (!$this->isEnabled($storeId) && !$h->linksGoDirect($storeId))) {
            return $html;
        }

        $utm   = (bool) $h->getConfig('tracking/utm', $storeId);
        $extra = [];

        if ($token !== '') {
            // Not urlencoded: a provider's placeholder has to reach it as written
            $extra[self::PARAM] = $token;
        }

        if ($utm) {
            $extra['utm_source']   = rawurlencode((string) ($h->getConfig('tracking/utm_source', $storeId) ?: 'newsletter'));
            $extra['utm_medium']   = rawurlencode((string) ($h->getConfig('tracking/utm_medium', $storeId) ?: 'email'));
            $extra['utm_campaign'] = rawurlencode($this->_slug($label));
        }

        if (!$extra) {
            return $html;
        }

        $hosts = $this->_storeHosts();

        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i',
            function (array $m) use ($extra, $hosts) {
                $url  = html_entity_decode($m[3], ENT_QUOTES, 'UTF-8');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                // Only the store's own pages: nobody else's site reads the tag,
                // and the unsubscribe page is not somewhere to shop from
                if ($host === '' || !isset($hosts[$host]) || stripos($url, 'emailcampaigns/preferences') !== false) {
                    return $m[0];
                }

                $fragment = '';

                if (($at = strpos($url, '#')) !== false) {
                    $fragment = substr($url, $at);
                    $url      = substr($url, 0, $at);
                }

                $query = (string) parse_url($url, PHP_URL_QUERY);
                parse_str($query, $present);
                $add = [];

                foreach ($extra as $key => $value) {
                    // A label somebody put on the link by hand is theirs to keep
                    if (!array_key_exists($key, $present) && $value !== '') {
                        $add[] = $key . '=' . $value;
                    }
                }

                if (!$add) {
                    return $m[0];
                }

                $url .= (strpos($url, '?') === false ? '?' : '&') . implode('&', $add) . $fragment;

                return $m[1] . $m[2] . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $m[2];
            },
            $html
        );
    }

    /**
     * The hosts the store answers on, across every store view.
     *
     * @return array<string, bool>
     */
    protected function _storeHosts(): array
    {
        if ($this->_hosts === null) {
            $this->_hosts = [];

            foreach (Mage::app()->getStores(true) as $store) {
                foreach ([Mage_Core_Model_Store::XML_PATH_UNSECURE_BASE_URL, Mage_Core_Model_Store::XML_PATH_SECURE_BASE_URL] as $path) {
                    $host = strtolower((string) parse_url((string) $store->getConfig($path), PHP_URL_HOST));

                    if ($host !== '') {
                        $this->_hosts[$host] = true;
                    }
                }
            }
        }

        return $this->_hosts;
    }

    private function _slug(string $label): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');

        return $slug !== '' ? substr($slug, 0, 80) : 'email';
    }

    // ------------------------------------------------------------------
    // 2. Arriving on the store through a tagged link
    // ------------------------------------------------------------------

    /**
     * Remember the email a visitor came from.
     *
     * @param Mage_Core_Controller_Request_Http $request
     */
    public function remember($request): void
    {
        $token = $request->getParam(self::PARAM);
        $token = is_string($token) ? $token : '';

        $h      = Mage::helper('ysrtech_emailcampaigns');
        $direct = $h->linksGoDirect();

        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token) || (!$this->isEnabled() && !$direct)) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $queue    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        $id = (int) $adapter->fetchOne(
            $adapter->select()->from($queue, ['queue_id'])->where('tracking_token = ?', $token)->limit(1)
        );

        // A token that is nobody's: a mistyped or made-up address, not a click
        if (!$id) {
            return;
        }

        // The cookie is for crediting an order, and only set where orders are tracked
        if ($this->isEnabled()) {
            Mage::getSingleton('core/cookie')->set(self::COOKIE, $token, $this->windowDays() * 86400, '/', null, null, true);
        }

        /*
         * Which link it was - but only where the provider does not say. One
         * that tracks clicks reports the link as it was written in the email;
         * what arrives here may have been redirected on the way, and the same
         * click would be listed twice under two addresses.
         */
        $here = $direct || strtolower((string) $h->getConfig('sending/transport')) !== 'mailgun';

        if ($here) {
            $row = $adapter->fetchRow($adapter->select()->from($queue, ['queue_id', 'campaign_id', 'automation_id'])->where('queue_id = ?', $id));
            $this->recordLinkClick($row, $request->getScheme() . "://" . $request->getHttpHost(false) . $request->getRequestUri(), Varien_Date::now());
        }

        /*
         * The click itself. Where the provider counts clicks too, this only
         * makes sure there is one on record; where this is the only count
         * (links straight to the store), every arrival is a click.
         */
        $adapter->update($queue, [
            'clicked_at'  => new Zend_Db_Expr('COALESCE(clicked_at, ' . $adapter->quote(Varien_Date::now()) . ')'),
            'click_count' => new Zend_Db_Expr($direct ? 'click_count + 1' : 'GREATEST(click_count, 1)'),
        ], ['queue_id = ?' => $id]);
    }

    /**
     * Note that the person behind a message clicked a link.
     *
     * One row per person per link. The link is kept as it was written in the
     * email: the token and the utm_ labels this module added are taken off
     * again, so everybody who clicked "Shop the sale" is counted together.
     *
     * @param array  $queueRow queue_id, campaign_id, automation_id
     * @param string $url
     * @param string $at       UTC
     */
    public function recordLinkClick(array $queueRow, string $url, string $at): void
    {
        $url = $this->linkAsWritten($url);

        if ($url === '' || empty($queueRow['queue_id'])) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');

        $adapter->insertOnDuplicate($resource->getTableName('ysrtech_emailcampaigns/link_click'), [
            'queue_id'         => (int) $queueRow['queue_id'],
            'campaign_id'      => !empty($queueRow['campaign_id']) ? (int) $queueRow['campaign_id'] : null,
            'automation_id'    => !empty($queueRow['automation_id']) ? (int) $queueRow['automation_id'] : null,
            'url_hash'         => sha1($url),
            'url'              => $url,
            'clicks'           => 1,
            'first_clicked_at' => $at,
        ], ['clicks' => new Zend_Db_Expr('clicks + 1')]);
    }

    /**
     * A clicked address with this module's own additions removed.
     */
    public function linkAsWritten(string $url): string
    {
        $url = trim($url);

        if (!preg_match('#^https?://#i', $url)) {
            return '';
        }

        if (($at = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $at);
        }

        if (($at = strpos($url, '?')) !== false) {
            $kept = [];

            foreach (explode('&', substr($url, $at + 1)) as $pair) {
                $key = strtolower((string) strtok($pair, '='));

                if ($pair !== '' && $key !== self::PARAM && strpos($key, 'utm_') !== 0) {
                    $kept[] = $pair;
                }
            }

            $url = substr($url, 0, $at) . ($kept ? '?' . implode('&', $kept) : '');
        }

        return mb_substr($url, 0, 2000);
    }

    /**
     * The links of a campaign, by how many people clicked each.
     *
     * @return array<int, array{url:string, people:int, clicks:int}>
     */
    public function linksForCampaign(int $campaignId, int $limit = 30): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        return array_map(
            static fn (array $row) => ['url' => (string) $row['url'], 'people' => (int) $row['people'], 'clicks' => (int) $row['clicks']],
            $adapter->fetchAll(
                $adapter->select()
                    ->from($resource->getTableName('ysrtech_emailcampaigns/link_click'), [
                        'url'    => new Zend_Db_Expr('MAX(url)'),
                        'people' => new Zend_Db_Expr('COUNT(*)'),
                        'clicks' => new Zend_Db_Expr('SUM(clicks)'),
                    ])
                    ->where('campaign_id = ?', $campaignId)
                    ->group('url_hash')
                    ->order(['people DESC', 'clicks DESC'])
                    ->limit($limit)
            )
        );
    }

    // ------------------------------------------------------------------
    // 3. Crediting an order
    // ------------------------------------------------------------------

    /**
     * Credit a newly stored order to the email behind it, if there is one.
     *
     * Called on every save of every order, so the cheap refusals come first.
     *
     * @return string|null how it was credited, or null if it was not
     */
    public function credit(Mage_Sales_Model_Order $order): ?string
    {
        $orderId = (int) $order->getId();

        if (!$orderId || isset($this->_seen[$orderId])) {
            return null;
        }

        $this->_seen[$orderId] = true;

        $created = strtotime((string) $order->getCreatedAt() . ' UTC');

        if (!$created || time() - $created > self::FRESH_SECONDS || !$this->isEnabled($order->getStoreId())) {
            return null;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/conversion');

        if ($adapter->fetchOne($adapter->select()->from($table, ['conversion_id'])->where('order_id = ?', $orderId))) {
            return null;
        }

        $queue = $resource->getTableName('ysrtech_emailcampaigns/queue');
        $cols  = ['queue_id', 'campaign_id', 'automation_id', 'step_order', 'email', 'clicked_at'];
        $via   = self::VIA_LINK;
        $row   = null;

        // The browser that clicked is the browser that ordered
        $token = (string) Mage::getSingleton('core/cookie')->get(self::COOKIE);

        if (preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
            $row = $adapter->fetchRow($adapter->select()->from($queue, $cols)->where('tracking_token = ?', $token)->limit(1));
        }

        // Otherwise: the last email this address clicked, inside the window
        if (!$row) {
            $email = strtolower(trim((string) $order->getCustomerEmail()));

            if ($email !== '') {
                $via = self::VIA_ADDRESS;
                $row = $adapter->fetchRow(
                    $adapter->select()
                        ->from($queue, $cols)
                        ->where('email = ?', $email)
                        ->where("status = 'sent'")
                        ->where('clicked_at >= ?', gmdate('Y-m-d H:i:s', $created - $this->windowDays($order->getStoreId()) * 86400))
                        ->where('clicked_at <= ?', gmdate('Y-m-d H:i:s', $created + 60))
                        ->order('clicked_at DESC')
                        ->limit(1)
                );
            }
        }

        if (!$row) {
            return null;
        }

        $adapter->insertIgnore($table, [
            'order_id'      => $orderId,
            'queue_id'      => (int) $row['queue_id'],
            'campaign_id'   => $row['campaign_id'] ? (int) $row['campaign_id'] : null,
            'automation_id' => $row['automation_id'] ? (int) $row['automation_id'] : null,
            'step_order'    => $row['step_order'] !== null ? (int) $row['step_order'] : null,
            'email'         => strtolower((string) $row['email']),
            'via'           => $via,
            'revenue'       => $this->revenueOf($order),
            'currency'      => (string) $order->getBaseCurrencyCode(),
            'clicked_at'    => $row['clicked_at'] ?: null,
            'created_at'    => Varien_Date::now(),
        ]);

        return $via;
    }

    /**
     * What the goods sold for: subtotal less discounts, no shipping, no tax.
     */
    public function revenueOf(Mage_Sales_Model_Order $order): float
    {
        // base_discount_amount is stored as a negative number
        return max(0.0, round((float) $order->getBaseSubtotal() + (float) $order->getBaseDiscountAmount(), 4));
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Revenue as the order stands today, so refunds and cancellations show.
     * A cancelled order counts for nothing; one that has gone from the store
     * altogether keeps the figure taken when it was placed.
     */
    private function _liveRevenue(): string
    {
        return "CASE WHEN o.entity_id IS NULL THEN c.revenue WHEN o.state = 'canceled' THEN 0 ELSE GREATEST(0,"
            . ' o.base_subtotal + IFNULL(o.base_discount_amount, 0) - IFNULL(o.base_subtotal_refunded, 0) + IFNULL(o.base_discount_refunded, 0)) END';
    }

    private function _select(): Varien_Db_Select
    {
        $resource = Mage::getSingleton('core/resource');

        return $resource->getConnection('core_read')->select()
            ->from(['c' => $resource->getTableName('ysrtech_emailcampaigns/conversion')], [])
            ->joinLeft(['o' => $resource->getTableName('sales/order')], 'o.entity_id = c.order_id', []);
    }

    /**
     * @return array{orders:int, by_link:int, by_address:int, revenue:float, currency:string}
     */
    private function _totals(Varien_Db_Select $select): array
    {
        $live = $this->_liveRevenue();
        $row  = Mage::getSingleton('core/resource')->getConnection('core_read')->fetchRow($select->columns([
            // An order since cancelled is no longer a sale
            'orders'     => new Zend_Db_Expr("COALESCE(SUM(o.entity_id IS NULL OR o.state != 'canceled'), 0)"),
            'by_address' => new Zend_Db_Expr("COALESCE(SUM((o.entity_id IS NULL OR o.state != 'canceled') AND c.via = 'address'), 0)"),
            'revenue'    => new Zend_Db_Expr("COALESCE(SUM({$live}), 0)"),
            'currency'   => new Zend_Db_Expr('MAX(c.currency)'),
        ])) ?: [];

        $orders = (int) ($row['orders'] ?? 0);

        return [
            'orders'     => $orders,
            'by_address' => (int) ($row['by_address'] ?? 0),
            'by_link'    => $orders - (int) ($row['by_address'] ?? 0),
            'revenue'    => (float) ($row['revenue'] ?? 0),
            'currency'   => (string) ($row['currency'] ?? '') ?: (string) Mage::app()->getBaseCurrencyCode(),
        ];
    }

    public function forCampaign(int $campaignId): array
    {
        return $this->_totals($this->_select()->where('c.campaign_id = ?', $campaignId));
    }

    public function forAutomation(int $automationId, ?int $stepOrder = null): array
    {
        $select = $this->_select()->where('c.automation_id = ?', $automationId);

        if ($stepOrder !== null) {
            $select->where('c.step_order = ?', $stepOrder);
        }

        return $this->_totals($select);
    }

    /**
     * Orders and revenue for several campaigns or automations at once, for a
     * page of cards.
     *
     * @param  string $column campaign_id or automation_id
     * @param  int[]  $ids
     * @return array<int, array{orders:int, revenue:float, label:string}>
     */
    public function grouped(string $column, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids || !in_array($column, ['campaign_id', 'automation_id'], true)) {
            return [];
        }

        $live = $this->_liveRevenue();
        $out  = [];

        foreach (Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll(
            $this->_select()->where("c.{$column} IN (?)", $ids)->group("c.{$column}")->columns([
                'id'       => "c.{$column}",
                'orders'   => new Zend_Db_Expr("COALESCE(SUM(o.entity_id IS NULL OR o.state != 'canceled'), 0)"),
                'revenue'  => new Zend_Db_Expr("COALESCE(SUM({$live}), 0)"),
                'currency' => new Zend_Db_Expr('MAX(c.currency)'),
            ])
        ) as $row) {
            $out[(int) $row['id']] = [
                'orders'  => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'label'   => $this->money((float) $row['revenue'], (string) $row['currency']),
            ];
        }

        return $out;
    }

    /**
     * Everything credited since a point in time (UTC), optionally to one kind of email.
     */
    public function since(string $fromUtc, ?string $toUtc = null, ?string $kind = null): array
    {
        $select = $this->_select()->where('c.created_at >= ?', $fromUtc);

        if ($toUtc !== null) {
            $select->where('c.created_at < ?', $toUtc);
        }

        if ($kind === 'campaign') {
            $select->where('c.campaign_id IS NOT NULL');
        } elseif ($kind === 'automation') {
            $select->where('c.automation_id IS NOT NULL');
        }

        return $this->_totals($select);
    }

    /**
     * One address's credited orders, for their page: totals, and each order
     * by the message that earned it.
     *
     * @return array{totals: array, by_queue: array<int, array{order_id:int, increment_id:?string, revenue:float, currency:string, via:string}>}
     */
    public function forEmail(string $email): array
    {
        $email  = strtolower(trim($email));
        $live   = $this->_liveRevenue();
        $rows   = Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll(
            $this->_select()->where('c.email = ?', $email)->columns([
                'queue_id' => 'c.queue_id', 'order_id' => 'c.order_id', 'via' => 'c.via', 'currency' => 'c.currency',
                'increment_id' => 'o.increment_id', 'state' => 'o.state',
                'revenue' => new Zend_Db_Expr($live),
            ])
        );
        $byQueue = [];

        foreach ($rows as $row) {
            if ($row['state'] === 'canceled') {
                continue;
            }

            $byQueue[(int) $row['queue_id']] = [
                'order_id'     => (int) $row['order_id'],
                'increment_id' => $row['increment_id'],
                'revenue'      => (float) $row['revenue'],
                'currency'     => (string) $row['currency'],
                'via'          => (string) $row['via'],
            ];
        }

        return ['totals' => $this->_totals($this->_select()->where('c.email = ?', $email)), 'by_queue' => $byQueue];
    }

    public function money(float $amount, string $currency): string
    {
        return $currency !== ''
            ? (string) Mage::app()->getLocale()->currency($currency)->toCurrency($amount)
            : number_format($amount, 2);
    }
}
