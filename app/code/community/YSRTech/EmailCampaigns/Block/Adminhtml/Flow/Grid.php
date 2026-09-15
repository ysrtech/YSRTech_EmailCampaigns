<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Flow_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsFlowGrid');
        $this->setDefaultSort('flow_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    protected function _prepareCollection()
    {
        $this->setCollection(
            Mage::getResourceModel('ysrtech_emailcampaigns/flow_collection')
        );
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('flow_id', [
            'header' => $h->__('ID'), 'index' => 'flow_id', 'width' => '60px',
        ]);
        $this->addColumn('name', [
            'header' => $h->__('Name'), 'index' => 'name',
        ]);
        $this->addColumn('trigger_type', [
            'header'  => $h->__('Trigger'), 'index' => 'trigger_type', 'width' => '160px',
            'type'    => 'options',
            'options' => ['order_placed' => $h->__('Order Placed')],
        ]);
        $this->addColumn('status', [
            'header'  => $h->__('Status'), 'index' => 'status', 'width' => '100px',
            'type'    => 'options',
            'options' => [
                YSRTech_EmailCampaigns_Model_Flow::STATUS_DRAFT  => $h->__('Draft'),
                YSRTech_EmailCampaigns_Model_Flow::STATUS_ACTIVE => $h->__('Active'),
                YSRTech_EmailCampaigns_Model_Flow::STATUS_PAUSED => $h->__('Paused'),
            ],
        ]);
        $this->addColumn('updated_at', [
            'header' => $h->__('Updated'), 'index' => 'updated_at',
            'type'   => 'datetime', 'width' => '160px',
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
