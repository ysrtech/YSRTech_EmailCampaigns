<?php
/**
 * The subscribers in one segment.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_View_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsSegmentMembersGrid');
        $this->setDefaultSort('subscriber_email');
        $this->setDefaultDir('ASC');
        $this->setUseAjax(true);
        $this->setVarNameFilter('segment_member_filter');
    }

    protected function _segmentId(): int
    {
        return (int) Mage::registry('ysrtech_emailcampaigns_segment')->getId();
    }

    #[Override]
    protected function _prepareCollection()
    {
        $resource = Mage::getSingleton('core/resource');

        /** @var Mage_Newsletter_Model_Resource_Subscriber_Collection $collection */
        $collection = Mage::getResourceModel('newsletter/subscriber_collection');

        $collection->getSelect()
            ->join(
                ['m' => $resource->getTableName('ysrtech_emailcampaigns/segment_subscriber')],
                'm.subscriber_id = main_table.subscriber_id',
                []
            )
            ->where('m.segment_id = ?', $this->_segmentId())
            // When they last opened anything sent from here: one grouped
            // read of the queue, joined, rather than a lookup per row
            ->joinLeft(
                ['e' => new Zend_Db_Expr('(' . $resource->getConnection('core_read')->select()
                    ->from($resource->getTableName('ysrtech_emailcampaigns/queue'), ['subscriber_id', 'last_opened_at' => new Zend_Db_Expr('MAX(opened_at)')])
                    ->where('subscriber_id IS NOT NULL')
                    ->group('subscriber_id') . ')')],
                'e.subscriber_id = main_table.subscriber_id',
                ['last_opened_at']
            )
            ->joinLeft(
                ['s' => $resource->getTableName('ysrtech_emailcampaigns/suppression')],
                's.email = main_table.subscriber_email',
                ['suppression_reason' => 'reason']
            );

        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    #[Override]
    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('subscriber_email', [
            'header' => $h->__('Email'), 'index' => 'subscriber_email', 'filter_index' => 'main_table.subscriber_email',
        ]);
        $this->addColumn('subscriber_firstname', [
            'header' => $h->__('First Name'), 'index' => 'subscriber_firstname', 'filter_index' => 'main_table.subscriber_firstname',
        ]);
        $this->addColumn('subscriber_lastname', [
            'header' => $h->__('Last Name'), 'index' => 'subscriber_lastname', 'filter_index' => 'main_table.subscriber_lastname',
        ]);
        $this->addColumn('customer_id', [
            'header'       => $h->__('Customer'),
            'index'        => 'customer_id',
            'filter_index' => 'main_table.customer_id',
            'renderer'     => 'ysrtech_emailcampaigns/adminhtml_segment_grid_renderer_customer',
            'filter'       => false,
            'width'        => '110px',
        ]);
        $this->addColumn('subscriber_status', [
            'header'       => $h->__('Status'),
            'index'        => 'subscriber_status',
            'filter_index' => 'main_table.subscriber_status',
            'type'         => 'options',
            'options'      => [
                Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED   => $h->__('Subscribed'),
                Mage_Newsletter_Model_Subscriber::STATUS_NOT_ACTIVE   => $h->__('Not activated'),
                Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED => $h->__('Unsubscribed'),
                Mage_Newsletter_Model_Subscriber::STATUS_UNCONFIRMED  => $h->__('Unconfirmed'),
            ],
            'width'        => '120px',
        ]);
        $this->addColumn('suppression_reason', [
            'header'       => $h->__('Suppressed'),
            'index'        => 'suppression_reason',
            'filter_index' => 's.reason',
            'type'         => 'options',
            'options'      => YSRTech_EmailCampaigns_Model_Suppression::reasons(),
            'width'        => '130px',
        ]);
        $this->addColumn('last_opened_at', [
            'header' => $h->__('Last Opened'), 'index' => 'last_opened_at', 'filter_index' => 'e.last_opened_at',
            'type'   => 'datetime', 'width' => '160px',
        ]);
        $this->addColumn('change_status_at', [
            'header' => $h->__('Status Since'), 'index' => 'change_status_at', 'filter_index' => 'main_table.change_status_at',
            'type'   => 'datetime', 'width' => '160px',
        ]);

        $this->addExportType('*/*/exportMembersCsv', $h->__('CSV'));

        return parent::_prepareColumns();
    }

    #[Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/membersGrid', ['id' => $this->_segmentId()]);
    }

    /**
     * A row opens that person's page: every email and what they did with it.
     */
    #[Override]
    public function getRowUrl($row)
    {
        return Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns/subscriber')
            ? $this->getUrl('*/ysrtech_subscriber/view', ['id' => (int) $row->getId()])
            : '';
    }
}
