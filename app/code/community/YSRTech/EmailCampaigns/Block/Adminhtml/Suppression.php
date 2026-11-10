<?php
/**
 * Suppressions: the grid, with a row of per-reason counts above it that
 * filter it.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Suppression extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    /** The grid's filter parameter; the grid block sets the same name */
    public const FILTER_VAR = 'suppression_filter';

    public function __construct()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        // Before the parent runs: it builds the Add button from the label as
        // it constructs, and one set afterwards is never read.
        $this->_controller     = 'adminhtml_suppression';
        $this->_blockGroup     = 'ysrtech_emailcampaigns';
        $this->_headerText     = $h->__('Suppressions');
        $this->_addButtonLabel = $h->__('Add Addresses');

        parent::__construct();

        $this->setTemplate('ysrtech_emailcampaigns/suppression.phtml');
    }

    #[Override]
    public function getCreateUrl()
    {
        return $this->getUrl('*/*/add');
    }

    /**
     * The reasons with how many addresses each holds, "all" first.
     *
     * @return array<int, array{label:string, count:int, url:string, active:bool}>
     */
    public function getReasonFilters(): array
    {
        $counts  = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression')->countByReason();
        $current = $this->_currentReason();
        $h       = Mage::helper('ysrtech_emailcampaigns');

        $filters = [[
            'label'  => $h->__('All'),
            'count'  => array_sum($counts),
            'url'    => $this->_filterUrl(''),
            'active' => $current === '',
        ]];

        foreach (YSRTech_EmailCampaigns_Model_Suppression::reasons() as $reason => $label) {
            $filters[] = [
                'label'  => $label,
                'count'  => (int) ($counts[$reason] ?? 0),
                'url'    => $this->_filterUrl($reason),
                'active' => $current === $reason,
            ];
        }

        return $filters;
    }

    /**
     * The grid reads its filter as a base64 query string, in the URL or from
     * the session it saved it to. Setting it the same way means the counts
     * and the grid's own Reason dropdown are one filter, not two that can
     * disagree.
     */
    protected function _filterUrl(string $reason): string
    {
        return $this->getUrl('*/*/index', [self::FILTER_VAR => base64_encode('reason=' . $reason)]);
    }

    protected function _currentReason(): string
    {
        $grid = $this->getChild('grid');

        if (!$grid) {
            return '';
        }

        $filter = $grid->getParam($grid->getVarNameFilter());

        if (!is_string($filter)) {
            return '';
        }

        $data = Mage::helper('adminhtml')->prepareFilterString($filter);

        return isset($data['reason']) ? (string) $data['reason'] : '';
    }
}
