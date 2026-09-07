<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsSegmentGrid');
        $this->setDefaultSort('segment_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    protected function _prepareCollection()
    {
        $this->setCollection(
            Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')
        );
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('segment_id', [
            'header' => $h->__('ID'), 'index' => 'segment_id', 'width' => '60px',
        ]);
        $this->addColumn('name', [
            'header' => $h->__('Name'), 'index' => 'name',
        ]);
        $this->addColumn('customer_count', [
            'header' => $h->__('Subscribers'), 'index' => 'customer_count', 'width' => '100px',
        ]);
        $this->addColumn('last_reindexed_at', [
            'header' => $h->__('Last Reindexed'), 'index' => 'last_reindexed_at',
            'type'   => 'datetime', 'width' => '160px',
        ]);
        $this->addColumn('is_active', [
            'header'  => $h->__('Active'), 'index' => 'is_active',
            'type'    => 'options',
            'options' => [0 => $h->__('No'), 1 => $h->__('Yes')],
            'width'   => '80px',
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('*/*/edit', ['id' => $row->getId()]);
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }
}
