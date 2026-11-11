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
        return $this->getUrl('*/*/index', array_filter(['p' => $page > 1 ? $page : null, 'status' => $filter ?? $this->getFilter(), 'q' => $this->getSearch()]));
    }
}
