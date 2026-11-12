<?php
/**
 * The campaigns, as cards: a picture of each email, its status, and how it
 * did once it has gone out.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Cards extends Mage_Adminhtml_Block_Template
{
    public const PER_PAGE = 24;

    /** @var YSRTech_EmailCampaigns_Model_Resource_Campaign_Collection|null */
    private $_collection;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/campaign_cards.phtml');
    }

    /**
     * @return array<string, string> filter code => label
     */
    public function getFilters(): array
    {
        return [
            ''          => $this->__('All'),
            'draft'     => $this->__('Drafts'),
            'scheduled' => $this->__('Scheduled'),
            'active'    => $this->__('Sending'),
            'sent'      => $this->__('Sent'),
        ];
    }

    public function getFilter(): string
    {
        $filter = (string) $this->getRequest()->getParam('status');

        return isset($this->getFilters()[$filter]) ? $filter : '';
    }

    public function getSearch(): string
    {
        return trim((string) $this->getRequest()->getParam('q'));
    }

    /** "cards" or "calendar" */
    public function getView(): string
    {
        return $this->getRequest()->getParam('view') === 'calendar' ? 'calendar' : 'cards';
    }

    private function _timezone(): DateTimeZone
    {
        return new DateTimeZone((string) Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE) ?: 'UTC');
    }

    /**
     * The first day of the month the calendar shows, in the store's
     * timezone: the one asked for, or this one.
     */
    public function getMonth(): DateTimeImmutable
    {
        $asked = (string) $this->getRequest()->getParam('m');
        $zone  = $this->_timezone();

        if (preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $asked)) {
            return new DateTimeImmutable($asked . '-01 00:00:00', $zone);
        }

        return (new DateTimeImmutable('now', $zone))->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * The month as weeks of days, each day with the campaigns that go out
     * or went out on it. A scheduled campaign sits on the day it is due; one
     * that has gone sits on the day it was sent. Days are the store's days,
     * not UTC's.
     *
     * @return array{weeks:array<int, array<int, array{date:DateTimeImmutable, in:bool, today:bool, items:array}>>, weekdays:string[], count:int}
     */
    public function getCalendar(): array
    {
        $zone  = $this->_timezone();
        $utc   = new DateTimeZone('UTC');
        $month = $this->getMonth();
        $first = (int) Mage::getStoreConfig('general/locale/firstday');

        // Back to the start of the week the month begins in, on to the end
        // of the one it finishes in
        $start = $month->modify('-' . ((7 + (int) $month->format('w') - $first) % 7) . ' days');
        $last  = $month->modify('last day of this month');
        $end   = $last->modify('+' . ((6 - (int) $last->format('w') + $first) % 7 + 1) . ' days');

        $when = new Zend_Db_Expr("CASE WHEN main_table.status IN ('sent', 'failed', 'cancelled') THEN COALESCE(main_table.sent_at, main_table.scheduled_at) ELSE main_table.scheduled_at END");

        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/campaign_collection');
        $collection->getSelect()
            ->columns(['calendar_at' => $when])
            ->where('main_table.status <> ?', 'draft')
            ->where($when . ' >= ?', $start->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->where($when . ' < ?', $end->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->order('calendar_at ASC');

        if ($this->getFilter() !== '') {
            $collection->addFieldToFilter('status', ['in' => $this->_statuses($this->getFilter())]);
        }

        if ($this->getSearch() !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->getSearch()) . '%';
            $collection->addFieldToFilter(['name', 'subject'], [['like' => $like], ['like' => $like]]);
        }

        $byDay = [];

        foreach ($collection as $campaign) {
            $at = (new DateTimeImmutable((string) $campaign->getData('calendar_at'), $utc))->setTimezone($zone);

            $byDay[$at->format('Y-m-d')][] = [
                'id'     => (int) $campaign->getId(),
                'name'   => (string) $campaign->getName(),
                'status' => (string) $campaign->getStatus(),
                'time'   => Mage::app()->getLocale()->date($at->getTimestamp())->toString(Mage::app()->getLocale()->getTimeFormat(Mage_Core_Model_Locale::FORMAT_TYPE_SHORT)),
            ];
        }

        $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
        $weeks = [];

        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');

            $weeks[(int) floor($start->diff($day)->days / 7)][] = [
                'date'  => $day,
                'in'    => $day->format('Y-m') === $month->format('Y-m'),
                'today' => $key === $today,
                'items' => $byDay[$key] ?? [],
            ];
        }

        $names    = Mage::app()->getLocale()->getTranslationList('days', ['gregorian', 'format', 'abbreviated']);
        $order    = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $weekdays = [];

        for ($i = 0; $i < 7; $i++) {
            $weekdays[] = (string) ($names[$order[($first + $i) % 7]] ?? ucfirst($order[($first + $i) % 7]));
        }

        return ['weeks' => $weeks, 'weekdays' => $weekdays, 'count' => $collection->getSize()];
    }

    public function getMonthLabel(): string
    {
        return Mage::app()->getLocale()->date($this->getMonth()->getTimestamp() + 86400 * 14)->toString('MMMM yyyy');
    }

    /**
     * This page with something about it changed: the view, the month, the
     * status. Null removes a parameter.
     */
    public function getViewUrl(array $change = []): string
    {
        $params = $change + [
            'view'   => $this->getView() === 'calendar' ? 'calendar' : null,
            'm'      => $this->getView() === 'calendar' ? $this->getRequest()->getParam('m') : null,
            'status' => $this->getFilter(),
            'q'      => $this->getSearch(),
        ];

        if (($params['view'] ?? null) !== 'calendar') {
            unset($params['m'], $params['view']);
        }

        return $this->getUrl('*/*/index', array_filter($params, static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Which statuses a filter shows.
     *
     * @return string[]
     */
    private function _statuses(string $filter): array
    {
        return ['draft' => ['draft'], 'scheduled' => ['scheduled'], 'active' => ['sending', 'paused'], 'sent' => ['sent', 'failed', 'cancelled']][$filter] ?? [];
    }

    /**
     * How many campaigns each filter holds.
     *
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');
        $byStatus = $adapter->fetchPairs(
            $adapter->select()->from($resource->getTableName('ysrtech_emailcampaigns/campaign'), ['status', new Zend_Db_Expr('COUNT(*)')])->group('status')
        );

        $counts = ['' => (int) array_sum($byStatus)];

        foreach (array_keys($this->getFilters()) as $filter) {
            if ($filter !== '') {
                $counts[$filter] = (int) array_sum(array_intersect_key($byStatus, array_flip($this->_statuses($filter))));
            }
        }

        return $counts;
    }

    public function getCollection()
    {
        if ($this->_collection === null) {
            $this->_collection = Mage::getResourceModel('ysrtech_emailcampaigns/campaign_collection')
                ->setOrder('campaign_id', 'DESC')
                ->setPageSize(self::PER_PAGE)
                ->setCurPage(max(1, (int) $this->getRequest()->getParam('p')));

            if ($this->getFilter() !== '') {
                $this->_collection->addFieldToFilter('status', ['in' => $this->_statuses($this->getFilter())]);
            }

            if ($this->getSearch() !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->getSearch()) . '%';
                $this->_collection->addFieldToFilter(['name', 'subject'], [['like' => $like], ['like' => $like]]);
            }
        }

        return $this->_collection;
    }

    /**
     * Sent, opened and clicked for the campaigns on this page, in one query.
     *
     * @return array<int, array{sent:int, opened:int, clicked:int}>
     */
    public function getStats(): array
    {
        $ids = $this->getCollection()->getAllIds();

        if (!$ids) {
            return [];
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');
        $stats    = [];

        foreach ($adapter->fetchAll(
            $adapter->select()
                ->from($resource->getTableName('ysrtech_emailcampaigns/queue'), [
                    'campaign_id',
                    'sent'    => new Zend_Db_Expr("SUM(status = 'sent')"),
                    'opened'  => new Zend_Db_Expr('SUM(open_count > machine_open_count)'),
                    'clicked' => new Zend_Db_Expr('SUM(click_count > 0)'),
                ])
                ->where('campaign_id IN (?)', $ids)
                ->group('campaign_id')
        ) as $row) {
            $stats[(int) $row['campaign_id']] = ['sent' => (int) $row['sent'], 'opened' => (int) $row['opened'], 'clicked' => (int) $row['clicked']];
        }

        return $stats;
    }

    public function getPreviewHtml($campaign): string
    {
        try {
            $template = $campaign->getContentTemplate();

            return $template ? Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template, $campaign->getStoreId() ?: null) : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    public function getStatusLabel(string $status): string
    {
        return [
            'draft' => $this->__('Draft'), 'scheduled' => $this->__('Scheduled'), 'sending' => $this->__('Sending'), 'sent' => $this->__('Sent'),
            'paused' => $this->__('Paused'), 'cancelled' => $this->__('Cancelled'), 'failed' => $this->__('Failed'),
        ][$status] ?? $status;
    }

    public function getPageUrl(int $page, ?string $filter = null): string
    {
        return $this->getViewUrl(['p' => $page > 1 ? $page : null, 'status' => $filter ?? $this->getFilter()]);
    }
}
