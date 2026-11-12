<?php
/**
 * Everyone on the newsletter list. A row opens that person's page.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Subscriber_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsSubscriberGrid');
        $this->setDefaultSort('subscriber_id');
        $this->setDefaultDir('DESC');
        $this->setUseAjax(true);
        $this->setSaveParametersInSession(true);
    }

    #[Override]
    protected function _prepareCollection()
    {
        $resource = Mage::getSingleton('core/resource');

        /** @var Mage_Newsletter_Model_Resource_Subscriber_Collection $collection */
        $collection = Mage::getResourceModel('newsletter/subscriber_collection');

        $collection->getSelect()
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

        $this->addColumn('subscriber_id', [
            'header' => $h->__('ID'), 'index' => 'subscriber_id', 'filter_index' => 'main_table.subscriber_id',
            'type'   => 'number', 'width' => '70px',
        ]);
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

        if (!Mage::app()->isSingleStoreMode()) {
            $this->addColumn('store_id', [
                'header'  => $h->__('Store View'), 'index' => 'store_id', 'filter_index' => 'main_table.store_id',
                'type'    => 'options', 'options' => Mage::getSingleton('adminhtml/system_store')->getStoreOptionHash(),
                'width'   => '160px',
            ]);
        }

        $this->addColumn('last_opened_at', [
            'header' => $h->__('Last Opened'), 'index' => 'last_opened_at', 'filter_index' => 'e.last_opened_at',
            'type'   => 'datetime', 'width' => '160px',
        ]);
        $this->addColumn('change_status_at', [
            'header' => $h->__('Status Since'), 'index' => 'change_status_at', 'filter_index' => 'main_table.change_status_at',
            'type'   => 'datetime', 'width' => '160px',
        ]);

        $this->addExportType('*/*/exportCsv', $h->__('CSV'));

        return parent::_prepareColumns();
    }

    #[Override]
    protected function _prepareMassaction()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->setMassactionIdField('subscriber_id');
        $this->setMassactionIdFilter('main_table.subscriber_id');
        $this->getMassactionBlock()->setFormFieldName('subscriber');

        $this->getMassactionBlock()->addItem('unsubscribe', [
            'label'   => $h->__('Unsubscribe'),
            'url'     => $this->getUrl('*/*/massUnsubscribe'),
            'confirm' => $h->__('Unsubscribe the selected addresses? They will get no more campaigns. No email is sent to tell them.'),
        ]);
        $this->getMassactionBlock()->addItem('delete', [
            'label'   => $h->__('Delete'),
            'url'     => $this->getUrl('*/*/massDelete'),
            'confirm' => $h->__('Delete the selected subscribers from the list? An address that unsubscribed is forgotten too and could be signed up again. To stop mail for good, unsubscribe instead.'),
        ]);

        return $this;
    }

    #[Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }

    #[Override]
    public function getRowUrl($row)
    {
        return $this->getUrl('*/ysrtech_subscriber/view', ['id' => (int) $row->getId()]);
    }
}
