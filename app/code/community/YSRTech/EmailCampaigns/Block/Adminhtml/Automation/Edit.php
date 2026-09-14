<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_objectId   = 'id';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_automation';
        $this->_mode       = 'edit';
    }

    public function getHeaderText()
    {
        /** @var YSRTech_EmailCampaigns_Model_Automation $model */
        $model = Mage::registry('ysrtech_emailcampaigns_automation');

        return $model->getId()
            ? Mage::helper('ysrtech_emailcampaigns')->__('Edit Automation "%s"', $this->escapeHtml($model->getName()))
            : Mage::helper('ysrtech_emailcampaigns')->__('New Automation');
    }
}
