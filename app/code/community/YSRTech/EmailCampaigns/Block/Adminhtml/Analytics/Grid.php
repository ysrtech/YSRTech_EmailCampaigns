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
                /*
                 * COALESCE because the rows do not last for ever. Once the
                 * retention sweep has rolled a campaign's totals onto it and
                 * removed its queue, the sums are all zero and the stored
                 * figures are the only record; until then the stored figures
                 * are null and the sums are the live truth. Pending is counted
                 * either way: a purged campaign has none by definition.
                 */
                array(
                    'queued'  => new Zend_Db_Expr('COALESCE(main_table.stat_queued, COUNT(q.queue_id))'),
                    'sent'    => new Zend_Db_Expr("COALESCE(main_table.stat_sent, SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END))"),
                    'pending' => new Zend_Db_Expr("SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END)"),
                    'failed'  => new Zend_Db_Expr("COALESCE(main_table.stat_failed, SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END))"),
                    'cancelled' => new Zend_Db_Expr("COALESCE(main_table.stat_cancelled, SUM(CASE WHEN q.status = 'cancelled' THEN 1 ELSE 0 END))"),
                    /*
                     * People, not events: somebody who opens the same email
                     * six times is one person who read it, and a rate built
                     * on the event count would read above a hundred per cent.
                     * Not rolled onto the campaign by the retention sweep, so
                     * these go blank once a campaign's rows are purged - the
                     * figures that survive are the ones about delivery.
                     */
                    'opened'  => new Zend_Db_Expr('SUM(CASE WHEN q.open_count > 0 THEN 1 ELSE 0 END)'),
                    'clicked' => new Zend_Db_Expr('SUM(CASE WHEN q.click_count > 0 THEN 1 ELSE 0 END)'),
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
            'opened'    => $helper->__('Opened'),
            'clicked'   => $helper->__('Clicked'),
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
