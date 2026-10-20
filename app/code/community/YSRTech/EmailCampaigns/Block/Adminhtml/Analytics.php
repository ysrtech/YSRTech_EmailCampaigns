<?php
/**
 * Per-campaign send results, counted from the queue.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Analytics extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_analytics';
        $this->_headerText = Mage::helper('ysrtech_emailcampaigns')->__('Campaign Analytics');
        parent::__construct();
        $this->_removeButton('add');
    }
}
