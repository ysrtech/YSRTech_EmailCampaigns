<?php
/**
 * One row per campaign, with its queue counted by status.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Analytics_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtech_emailcampaigns_analytics_grid');
        $this->setDefaultSort('campaign_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    #[Override]
    protected function _prepareCollection()
    {
        /** @var YSRTech_EmailCampaigns_Model_Resource_Campaign_Collection $collection */
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/campaign_collection');

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $queue    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        /*
         * Counted with conditional sums over one join rather than a subquery
         * per column, so the grid stays one query however many campaigns there
         * are. The left join keeps campaigns that have never been queued.
         */
        $collection->getSelect()
            ->joinLeft(
                array('q' => $queue),
                'q.campaign_id = main_table.campaign_id',
                array(
                    'queued'  => new Zend_Db_Expr('COUNT(q.queue_id)'),
                    'sent'    => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END)"),
                    'pending' => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END)"),
                    'failed'  => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END)"),
                    'cancelled' => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'cancelled' THEN 1 ELSE 0 END)"),
                )
            )
            ->group('main_table.campaign_id');

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    #[Override]
    protected function _prepareColumns()
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('campaign_id', array(
            'header' => $helper->__('ID'), 'index' => 'campaign_id', 'type' => 'number', 'width' => '60px',
        ));
        $this->addColumn('name', array('header' => $helper->__('Campaign'), 'index' => 'name'));
        $this->addColumn('status', array('header' => $helper->__('Status'), 'index' => 'status', 'width' => '90px'));
        $this->addColumn('sent_at', array(
            'header' => $helper->__('Sent At'), 'index' => 'sent_at', 'type' => 'datetime', 'width' => '140px',
        ));

        foreach (array(
            'queued'    => $helper->__('Recipients'),
            'sent'      => $helper->__('Sent'),
            'pending'   => $helper->__('Pending'),
            'failed'    => $helper->__('Failed'),
            'cancelled' => $helper->__('Unsubscribed'),
        ) as $index => $label) {
            $this->addColumn($index, array(
                'header'   => $label,
                'index'    => $index,
                'type'     => 'number',
                'width'    => '80px',
                // Computed in the select, so there is nothing to filter or sort on
                'filter'   => false,
                'sortable' => false,
            ));
        }

        return parent::_prepareColumns();
    }

    #[Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', array('_current' => true));
    }

    #[Override]
    public function getRowUrl($row)
    {
        return $this->getUrl('*/ysrtech_campaign/edit', array('id' => $row->getId()));
    }
}
