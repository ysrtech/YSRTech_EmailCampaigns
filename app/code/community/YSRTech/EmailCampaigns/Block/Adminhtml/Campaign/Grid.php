<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsCampaignGrid');
        $this->setDefaultSort('campaign_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
        $this->setVarNameFilter('campaign_filter');
    }

    protected function _prepareCollection()
    {
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/campaign_collection');
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('campaign_id', [
            'header' => $h->__('ID'), 'index' => 'campaign_id', 'width' => '60px',
        ]);
        $this->addColumn('name', [
            'header' => $h->__('Name'), 'index' => 'name',
        ]);
        $this->addColumn('subject', [
            'header' => $h->__('Subject'), 'index' => 'subject',
        ]);
        $this->addColumn('status', [
            'header'  => $h->__('Status'),
            'index'   => 'status',
            'type'    => 'options',
            'options' => [
                'draft'     => $h->__('Draft'),
                'scheduled' => $h->__('Scheduled'),
                'sending'   => $h->__('Sending'),
                'sent'      => $h->__('Sent'),
                'paused'    => $h->__('Paused'),
                'cancelled' => $h->__('Cancelled'),
            ],
        ]);
        $this->addColumn('scheduled_at', [
            'header' => $h->__('Scheduled At'), 'index' => 'scheduled_at',
            'type'   => 'datetime', 'width' => '160px',
        ]);
        $this->addColumn('sent_at', [
            'header' => $h->__('Sent At'), 'index' => 'sent_at',
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
