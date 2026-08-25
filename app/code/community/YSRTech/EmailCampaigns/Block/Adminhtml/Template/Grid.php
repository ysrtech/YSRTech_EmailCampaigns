<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('ysrtechEmailcampaignsTemplateGrid');
        $this->setDefaultSort('template_id');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    protected function _prepareCollection()
    {
        $this->setCollection(
            Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')
        );
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $this->addColumn('template_id', [
            'header' => $h->__('ID'), 'index' => 'template_id', 'width' => '60px',
        ]);
        $this->addColumn('name', [
            'header' => $h->__('Name'), 'index' => 'name',
        ]);
        $this->addColumn('subject', [
            'header' => $h->__('Subject'), 'index' => 'subject',
        ]);
        $this->addColumn('is_active', [
            'header'  => $h->__('Active'), 'index' => 'is_active',
            'type'    => 'options',
            'options' => [0 => $h->__('No'), 1 => $h->__('Yes')],
            'width'   => '80px',
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
