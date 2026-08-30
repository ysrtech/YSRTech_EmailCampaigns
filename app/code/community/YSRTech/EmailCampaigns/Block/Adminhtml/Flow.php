<?php
/** Grid container: Automation Flows */
class YSRTech_EmailCampaigns_Block_Adminhtml_Flow extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_controller     = 'adminhtml_flow';
        $this->_blockGroup     = 'ysrtech_emailcampaigns';
        $this->_headerText     = Mage::helper('ysrtech_emailcampaigns')->__('Manage Automation Flows');
        $this->_addButtonLabel = Mage::helper('ysrtech_emailcampaigns')->__('New Flow');
    }
}
