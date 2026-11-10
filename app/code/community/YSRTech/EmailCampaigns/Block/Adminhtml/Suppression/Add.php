<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Suppression_Add extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_objectId   = 'id';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_suppression';
        $this->_mode       = 'add';

        $this->_updateButton('save', 'label', Mage::helper('ysrtech_emailcampaigns')->__('Add to Suppressions'));
        $this->_removeButton('reset');
        $this->_removeButton('delete');
    }

    #[Override]
    public function getHeaderText()
    {
        return Mage::helper('ysrtech_emailcampaigns')->__('Add Addresses to Suppressions');
    }
}
