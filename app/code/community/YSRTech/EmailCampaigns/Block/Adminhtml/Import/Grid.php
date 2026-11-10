<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsImportGrid');
        $this->setDefaultSort('import_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
        $this->setVarNameFilter('import_filter');
    }

    #[Override]
    protected function _prepareCollection()
    {
        $this->setCollection(Mage::getResourceModel('ysrtech_emailcampaigns/import_collection'));

        return parent::_prepareCollection();
    }

    #[Override]
    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');
        $stat = 'ysrtech_emailcampaigns/adminhtml_import_grid_renderer_stat';

        $this->addColumn('import_id', ['header' => $h->__('ID'), 'index' => 'import_id', 'type' => 'number', 'width' => '50px']);
        $this->addColumn('type', [
            'header' => $h->__('From'), 'index' => 'type', 'type' => 'options', 'width' => '110px',
            'options' => ['csv' => $h->__('CSV file'), 'provider' => $h->__('Provider')],
            'renderer' => 'ysrtech_emailcampaigns/adminhtml_import_grid_renderer_source',
        ]);
        $this->addColumn('source_name', ['header' => $h->__('File / Account'), 'index' => 'source_name']);
        $this->addColumn('status', [
            'header' => $h->__('Status'), 'index' => 'status', 'type' => 'options', 'width' => '110px',
            'options' => YSRTech_EmailCampaigns_Model_Import::statuses(),
        ]);
        $this->addColumn('processed_rows', [
            'header' => $h->__('Rows Read'), 'index' => 'processed_rows', 'type' => 'number', 'width' => '90px',
        ]);

        foreach ([
            'created'            => $h->__('Created'),
            'updated'            => $h->__('Updated'),
            'suppressions_added' => $h->__('Suppressions'),
            'templates_created'  => $h->__('Templates'),
        ] as $key => $label) {
            $this->addColumn('stat_' . $key, [
                'header' => $label, 'renderer' => $stat, 'stat' => $key,
                'filter' => false, 'sortable' => false, 'align' => 'right', 'width' => '90px',
            ]);
        }

        $this->addColumn('admin_user', ['header' => $h->__('Started By'), 'index' => 'admin_user', 'width' => '110px']);
        $this->addColumn('created_at', [
            'header' => $h->__('When'), 'index' => 'created_at', 'type' => 'datetime', 'width' => '160px',
        ]);

        return parent::_prepareColumns();
    }

    #[Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }

    #[Override]
    public function getRowUrl($row)
    {
        return $this->getUrl('*/*/view', ['id' => $row->getId()]);
    }
}
