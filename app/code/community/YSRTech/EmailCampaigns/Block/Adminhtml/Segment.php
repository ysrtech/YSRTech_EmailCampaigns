<?php
/** Grid container: Segments */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_controller     = 'adminhtml_segment';
        $this->_blockGroup     = 'ysrtech_emailcampaigns';
        $this->_headerText     = Mage::helper('ysrtech_emailcampaigns')->__('Manage Segments');
        $this->_addButtonLabel = Mage::helper('ysrtech_emailcampaigns')->__('New Segment');
    }
}
