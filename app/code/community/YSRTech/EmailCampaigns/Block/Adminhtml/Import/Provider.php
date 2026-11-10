<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_Provider extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_objectId   = 'id';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_import';
        $this->_mode       = 'provider';

        $this->_updateButton('save', 'label', Mage::helper('ysrtech_emailcampaigns')->__('Connect'));
        $this->_removeButton('reset');
        $this->_removeButton('delete');
    }

    #[Override]
    public function getHeaderText()
    {
        return Mage::helper('ysrtech_emailcampaigns')->__('Migrate from a Provider: Step 1 of 2');
    }
}
