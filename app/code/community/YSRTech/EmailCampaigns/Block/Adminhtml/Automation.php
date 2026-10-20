<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_controller     = 'adminhtml_automation';
        $this->_blockGroup     = 'ysrtech_emailcampaigns';
        $this->_headerText     = Mage::helper('ysrtech_emailcampaigns')->__('Automations');
        $this->_addButtonLabel = Mage::helper('ysrtech_emailcampaigns')->__('Add New');
        parent::__construct();
    }
}
