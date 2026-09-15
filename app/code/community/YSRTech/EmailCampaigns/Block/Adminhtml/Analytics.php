<?php
/** Grid container: Analytics (read-only, no "add" action). */
class YSRTech_EmailCampaigns_Block_Adminhtml_Analytics extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_controller = 'adminhtml_analytics';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_headerText = Mage::helper('ysrtech_emailcampaigns')->__('Campaign Analytics');
        $this->_removeButton('add');
    }
}
