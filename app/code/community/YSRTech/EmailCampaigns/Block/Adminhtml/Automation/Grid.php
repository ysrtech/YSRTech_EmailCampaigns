<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsAutomationGrid');
        $this->setDefaultSort('automation_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    protected function _prepareCollection()
    {
        $this->setCollection(Mage::getResourceModel('ysrtech_emailcampaigns/automation_collection'));
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('automation_id', [
            'header' => $h->__('ID'), 'index' => 'automation_id', 'width' => '60px',
        ]);
        $this->addColumn('name', ['header' => $h->__('Name'), 'index' => 'name']);
        $this->addColumn('event', [
            'header'  => $h->__('Trigger'),
            'index'   => 'event',
            'type'    => 'options',
            'options' => Mage::getSingleton('ysrtech_emailcampaigns/system_config_source_automation_event')->toArray(),
        ]);
        $this->addColumn('chain', [
            'header'   => $h->__('Messages'),
            'index'    => 'automation_id',
            'width'    => '220px',
            'filter'   => false,
            'sortable' => false,
            'renderer' => 'ysrtech_emailcampaigns/adminhtml_automation_grid_renderer_chain',
        ]);
        $this->addColumn('cancel_on', [
            'header'  => $h->__('Stops If'),
            'index'   => 'cancel_on',
            'type'    => 'options',
            'width'   => '140px',
            'options' => ['never' => $h->__('Never'), 'order_placed' => $h->__('They order')],
        ]);
        $this->addColumn('respect_subscription', [
            'header'  => $h->__('Kind'),
            'index'   => 'respect_subscription',
            'type'    => 'options',
            'width'   => '120px',
            'options' => [0 => $h->__('Transactional'), 1 => $h->__('Marketing')],
        ]);
        $this->addColumn('is_active', [
            'header'  => $h->__('Active'),
            'index'   => 'is_active',
            'type'    => 'options',
            'width'   => '80px',
            'options' => [0 => $h->__('No'), 1 => $h->__('Yes')],
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
