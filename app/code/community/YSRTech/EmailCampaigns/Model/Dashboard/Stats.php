<?php
/**
 * The numbers behind the dashboard, read from the send queue.
 *
 * Everything is counted per message, by the day it was sent:
 *
 *   sent       accepted by the provider
 *   bounced    the provider later reported a permanent failure
 *   delivered  sent, less bounced
 *   opened     opened at least once by something that was not a mail client
 *              fetching images on its own
 *   machine    opened only by such a client
 *   clicked    at least one click
 *   complained reported as spam
 *
 * Rates follow what the mailbox providers themselves use: bounces over sent,
 * and opens, clicks and complaints over delivered.
 *
 * The queue is cleaned up after the configured number of days, ninety unless
 * changed, so the longest view here - thirty days and the thirty before it -
 * stays inside what is kept.
 */
class YSRTech_EmailCampaigns_Model_Dashboard_Stats
{
    public const TYPE_ALL        = '';
    public const TYPE_CAMPAIGN   = 'campaign';
    public const TYPE_AUTOMATION = 'automation';

    /** Google's published ceiling for bulk senders, and the level to stay under */
    public const COMPLAINT_LIMIT = 0.003;
    public const COMPLAINT_WARN  = 0.001;

    /** Where mailbox providers and ESPs start treating a list as unclean */
    public const BOUNCE_LIMIT = 0.05;
    public const BOUNCE_WARN  = 0.02;

    /** Below this many sends the rates swing too much to judge */
    public const MIN_SENT_TO_JUDGE = 100;

    private const KEYS = ['sent', 'delivered', 'opened', 'machine', 'clicked', 'bounced', 'complained'];

    /**
     * @param  int    $days 7, 14 or 30
     * @param  string $type One of the TYPE_ constants
     * @return array{
     *     days:int, type:string, totals:array, previous:array,
     *     series:array<int, array>, providers:array<int, array>, insight:array
     * }
     */
    public function compute(int $days, string $type = self::TYPE_ALL): array
    {
        $days = in_array($days, [7, 14, 30], true) ? $days : 14;

        // Whole days in the store's timezone, today included
        $today = Mage::app()->getLocale()->date()->setTime('00:00:00');
        $start = (clone $today)->subDay($days - 1);
        $prev  = (clone $start)->subDay($days);
        $end   = (clone $today)->addDay(1);

        $toUtc = static function (Zend_Date $date): string {
            return gmdate('Y-m-d H:i:s', $date->getTimestamp());
        };

        // Seconds east of UTC for the store's timezone, as of today
        $timezone = (string) Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE);
        $offset   = (new DateTime('now', new DateTimeZone($timezone ?: 'UTC')))->getOffset();

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = (clone $start)->addDay($i)->toString('yyyy-MM-dd');
            $series[$day] = ['date' => $day] + array_fill_keys(self::KEYS, 0);
        }

        $previous = array_fill_keys(self::KEYS, 0);
        $totals   = array_fill_keys(self::KEYS, 0);

        foreach ($this->_byDay($toUtc($prev), $toUtc($end), $offset, $type) as $row) {
            $day = (string) $row['day'];

            foreach (self::KEYS as $key) {
                if (isset($series[$day])) {
                    $series[$day][$key] = (int) $row[$key];
                    $totals[$key]      += (int) $row[$key];
                } else {
                    $previous[$key] += (int) $row[$key];
                }
            }
        }

        return [
            'days'      => $days,
            'type'      => $type,
            'totals'    => $totals,
            'previous'  => $previous,
            'series'    => array_values($series),
            'providers' => $this->_byProvider($toUtc($start), $toUtc($end), $type),
            'insight'   => $this->insight($totals),
        ];
    }

    /**
     * The one-line verdict at the top of the page.
     *
     * @return array{level:string, title:string, text:string}
     */
    public function insight(array $totals): array
    {
        $h    = Mage::helper('ysrtech_emailcampaigns');
        $sent = (int) $totals['sent'];

        if ($sent === 0) {
            return [
                'level' => 'notice',
                'title' => $h->__('Nothing was sent in this period.'),
                'text'  => $h->__('Figures appear here as campaigns and automations go out and the provider reports back.'),
            ];
        }

        if ($sent < self::MIN_SENT_TO_JUDGE) {
            return [
                'level' => 'notice',
                'title' => $h->__('Early days: %s sent in this period.', number_format($sent)),
                'text'  => $h->__('Rates swing a lot on small numbers, so there is no verdict until a few hundred have gone out.'),
            ];
        }

        $bounce    = self::rate($totals['bounced'], $totals['sent']);
        $complaint = self::rate($totals['complained'], $totals['delivered']);
        $parts     = [];

        if ($complaint >= self::COMPLAINT_LIMIT || $bounce >= self::BOUNCE_LIMIT) {
            if ($complaint >= self::COMPLAINT_LIMIT) {
                $parts[] = $h->__('Spam complaints are %s, above the 0.3%% limit Google and Yahoo set for bulk senders.', self::percent($complaint, 2));
            }

            if ($bounce >= self::BOUNCE_LIMIT) {
                $parts[] = $h->__('Bounces are %s; above 5%% a sending account is put under review.', self::percent($bounce));
            }

            $parts[] = $h->__('Stop sending to people who have not opened in months, and check the latest import.');

            return ['level' => 'error', 'title' => $h->__('Sender reputation needs attention.'), 'text' => implode(' ', $parts)];
        }

        if ($complaint >= self::COMPLAINT_WARN || $bounce >= self::BOUNCE_WARN) {
            if ($complaint >= self::COMPLAINT_WARN) {
                $parts[] = $h->__('Spam complaints (%s) are above the 0.1%% worth staying under.', self::percent($complaint, 2));
            }

            if ($bounce >= self::BOUNCE_WARN) {
                $parts[] = $h->__('Bounces (%s) are above 2%%.', self::percent($bounce));
            }

            return ['level' => 'warning', 'title' => $h->__('Worth a look.'), 'text' => implode(' ', $parts)];
        }

        return [
            'level' => 'success',
            'title' => $h->__('Delivery is healthy.'),
            'text'  => $h->__(
                '%s delivered, bounces %s and spam complaints %s, well inside what mailbox providers allow.',
                self::percent(self::rate($totals['delivered'], $totals['sent'])),
                self::percent($bounce),
                self::percent($complaint, 2)
            ),
        ];
    }

    public static function rate($part, $whole): float
    {
        return $whole > 0 ? $part / $whole : 0.0;
    }

    public static function percent(float $rate, int $decimals = 1): string
    {
        return number_format($rate * 100, $decimals) . '%';
    }

    /**
     * Which mailbox provider a domain belongs to, as far as its name says.
     *
     * By name only, so a company domain hosted on Google or Microsoft counts
     * as "Other": telling those apart means looking up where each domain's
     * mail is delivered, which nothing here does. Matched on the first label
     * for the providers that have a domain per country (yahoo.co.uk,
     * hotmail.fr, live.nl).
     */
    public static function providerFor(string $domain): string
    {
        $domain = strtolower($domain);
        $first  = strstr($domain, '.', true) ?: $domain;

        if ($first === 'gmail' || $first === 'googlemail') {
            return 'Gmail';
        }

        if (in_array($first, ['outlook', 'hotmail', 'live', 'msn'], true)) {
            return 'Outlook / Hotmail';
        }

        if (in_array($first, ['yahoo', 'ymail', 'rocketmail'], true)) {
            return 'Yahoo';
        }

        if ($first === 'icloud' || $domain === 'me.com' || $domain === 'mac.com') {
            return 'Apple iCloud';
        }

        if ($first === 'aol') {
            return 'AOL';
        }

        return 'Other';
    }

    /**
     * The aggregate expressions shared by both queries.
     */
    private function _columns(): array
    {
        return [
            'sent'         => new Zend_Db_Expr('COUNT(*)'),
            'delivered'    => new Zend_Db_Expr('SUM(q.bounced_at IS NULL)'),
            'opened'       => new Zend_Db_Expr('SUM(q.bounced_at IS NULL AND q.open_count > q.machine_open_count)'),
            'machine'      => new Zend_Db_Expr('SUM(q.open_count > 0 AND q.open_count <= q.machine_open_count)'),
            'clicked'      => new Zend_Db_Expr('SUM(q.click_count > 0)'),
            'bounced'      => new Zend_Db_Expr('SUM(q.bounced_at IS NOT NULL)'),
            'complained'   => new Zend_Db_Expr('SUM(q.complained_at IS NOT NULL)'),
        ];
    }

    private function _select(string $from, string $to, string $type): Varien_Db_Select
    {
        $resource = Mage::getSingleton('core/resource');
        $select   = $resource->getConnection('core_read')->select()
            ->from(['q' => $resource->getTableName('ysrtech_emailcampaigns/queue')], [])
            ->where('q.sent_at >= ?', $from)
            ->where('q.sent_at < ?', $to)
            ->where('q.status = ?', 'sent');

        if ($type === self::TYPE_CAMPAIGN) {
            $select->where('q.automation_id IS NULL');
        } elseif ($type === self::TYPE_AUTOMATION) {
            $select->where('q.automation_id IS NOT NULL');
        }

        return $select;
    }

    private function _byDay(string $from, string $to, int $offsetSeconds, string $type): array
    {
        // sent_at is UTC; shift it so a day is the store's day
        $day = new Zend_Db_Expr(sprintf('DATE(DATE_ADD(q.sent_at, INTERVAL %d SECOND))', $offsetSeconds));

        $select = $this->_select($from, $to, $type)
            ->columns(['day' => $day] + $this->_columns())
            ->group($day);

        return Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll($select);
    }

    /**
     * @return array<int, array> One row per provider, largest first
     */
    private function _byProvider(string $from, string $to, string $type): array
    {
        $domain = new Zend_Db_Expr("LOWER(SUBSTRING_INDEX(q.email, '@', -1))");

        $select = $this->_select($from, $to, $type)
            ->columns(['domain' => $domain] + $this->_columns())
            ->group($domain);

        $providers = [];

        foreach (Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll($select) as $row) {
            $name = self::providerFor((string) $row['domain']);

            if (!isset($providers[$name])) {
                $providers[$name] = ['name' => $name] + array_fill_keys(self::KEYS, 0);
            }

            foreach (self::KEYS as $key) {
                $providers[$name][$key] += (int) $row[$key];
            }
        }

        usort($providers, static fn ($a, $b) => $b['sent'] <=> $a['sent']);

        return $providers;
    }
}
