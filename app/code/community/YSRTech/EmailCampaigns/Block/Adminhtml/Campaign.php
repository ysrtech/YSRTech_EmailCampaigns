<?php
/** Grid container: Campaigns */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_controller      = 'adminhtml_campaign';
        $this->_blockGroup      = 'ysrtech_emailcampaigns';
        $this->_headerText      = Mage::helper('ysrtech_emailcampaigns')->__('Manage Campaigns');
        $this->_addButtonLabel  = Mage::helper('ysrtech_emailcampaigns')->__('New Campaign');
    }
}
