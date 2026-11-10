<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Suppression_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsSuppressionGrid');
        $this->setDefaultSort('created_at');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
        $this->setVarNameFilter(YSRTech_EmailCampaigns_Block_Adminhtml_Suppression::FILTER_VAR);
    }

    #[Override]
    protected function _prepareCollection()
    {
        $this->setCollection(Mage::getResourceModel('ysrtech_emailcampaigns/suppression_collection'));

        return parent::_prepareCollection();
    }

    #[Override]
    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('email', [
            'header' => $h->__('Address'), 'index' => 'email',
        ]);
        $this->addColumn('reason', [
            'header'   => $h->__('Reason'),
            'index'    => 'reason',
            'type'     => 'options',
            'options'  => YSRTech_EmailCampaigns_Model_Suppression::reasons(),
            'renderer' => 'ysrtech_emailcampaigns/adminhtml_suppression_grid_renderer_reason',
            'width'    => '150px',
        ]);
        $this->addColumn('detail', [
            'header' => $h->__('Detail'), 'index' => 'detail',
        ]);
        $this->addColumn('source', [
            'header'  => $h->__('Source'),
            'index'   => 'source',
            'type'    => 'options',
            'options' => [
                'webhook'      => $h->__('Provider webhook'),
                'mailgun_sync' => $h->__('Mailgun sync'),
                'admin'        => $h->__('Admin'),
                'import'       => $h->__('Import'),
                'migrated'     => $h->__('Before 0.10.0'),
            ],
            'width'   => '140px',
        ]);
        $this->addColumn('created_at', [
            'header' => $h->__('Added'), 'index' => 'created_at', 'type' => 'datetime', 'width' => '160px',
        ]);

        // Left out of an export, where a column of links is noise
        if (!$this->_isExport) {
            $this->addColumn('action', [
                'header'   => $h->__('Action'),
                'renderer' => 'ysrtech_emailcampaigns/adminhtml_suppression_grid_renderer_remove',
                'filter'   => false,
                'sortable' => false,
                'width'    => '80px',
            ]);
        }

        $this->addExportType('*/*/exportCsv', $h->__('CSV'));
        $this->addExportType('*/*/exportXml', $h->__('Excel XML'));

        return parent::_prepareColumns();
    }

    #[Override]
    protected function _prepareMassaction()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->setMassactionIdField('suppression_id');
        $this->getMassactionBlock()->setFormFieldName('suppression_ids');
        $this->getMassactionBlock()->addItem('remove', [
            'label'   => $h->__('Remove from suppressions'),
            'url'     => $this->getUrl('*/*/massRemove'),
            'confirm' => $h->__('These addresses will be mailed again. Anybody who bounces or complains again is added back automatically. Spam complaints among them are only removed if your role allows it. Continue?'),
        ]);

        return $this;
    }

    #[Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }

    /**
     * Rows go nowhere: there is nothing to edit about a suppression.
     */
    #[Override]
    public function getRowUrl($row)
    {
        return '';
    }
}
