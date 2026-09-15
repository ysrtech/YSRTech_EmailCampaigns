<?php
/**
 * Per-campaign send/open/click stats. Open/click counts only ever populate for
 * campaigns sent through Mailgun (see WebhookController::mailgunAction) — other
 * transports have no tracking source yet, so those columns stay at zero.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Analytics_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsAnalyticsGrid');
        $this->setDefaultSort('campaign_id');
        $this->setDefaultDir('DESC');
        $this->setUseAjax(true);
        $this->setFilterVisibility(false);
    }

    protected function _prepareCollection()
    {
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/campaign_collection')->addQueueStats();
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('campaign_id', [
            'header' => $h->__('ID'), 'index' => 'campaign_id', 'width' => '60px', 'filter' => false,
        ]);
        $this->addColumn('name', [
            'header' => $h->__('Campaign'), 'index' => 'name', 'filter' => false,
        ]);
        $this->addColumn('status', [
            'header' => $h->__('Status'), 'index' => 'status', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('sent_at', [
            'header' => $h->__('Sent At'), 'index' => 'sent_at',
            'type'   => 'datetime', 'width' => '160px', 'filter' => false,
        ]);
        $this->addColumn('recipients_count', [
            'header' => $h->__('Recipients'), 'index' => 'recipients_count',
            'type' => 'number', 'width' => '100px', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('sent_count', [
            'header' => $h->__('Sent'), 'index' => 'sent_count',
            'type' => 'number', 'width' => '90px', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('failed_count', [
            'header' => $h->__('Failed'), 'index' => 'failed_count',
            'type' => 'number', 'width' => '90px', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('opened_count', [
            'header' => $h->__('Opened'), 'index' => 'opened_count',
            'type' => 'number', 'width' => '90px', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('open_rate', [
            'header' => $h->__('Open Rate %'), 'index' => 'open_rate',
            'type' => 'number', 'width' => '110px', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('clicked_count', [
            'header' => $h->__('Clicked'), 'index' => 'clicked_count',
            'type' => 'number', 'width' => '90px', 'filter' => false, 'sortable' => false,
        ]);
        $this->addColumn('click_rate', [
            'header' => $h->__('Click Rate %'), 'index' => 'click_rate',
            'type' => 'number', 'width' => '110px', 'filter' => false, 'sortable' => false,
        ]);

        return parent::_prepareColumns();
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('*/ysrtech_campaign/edit', ['id' => $row->getId()]);
    }

    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }
}
