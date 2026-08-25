<?php
/**
 * Container block for the segment edit page.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_objectId   = 'id';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_segment';
        $this->_mode       = 'edit';

        $this->_addButton('save_and_continue', [
            'label'   => Mage::helper('ysrtech_emailcampaigns')->__('Save and Continue Edit'),
            'onclick' => 'editForm.submit(\'' . $this->getUrl('*/*/save', ['_current' => true, 'back' => null]) . '\')',
        ], -100);

        $this->_updateButton('save', 'label', Mage::helper('ysrtech_emailcampaigns')->__('Save Segment'));
    }

    public function getHeaderText()
    {
        /** @var YSRTech_EmailCampaigns_Model_Segment $model */
        $model = Mage::registry('ysrtech_emailcampaigns_segment');
        return $model->getId()
            ? Mage::helper('ysrtech_emailcampaigns')->__('Edit Segment "%s"', $this->escapeHtml($model->getName()))
            : Mage::helper('ysrtech_emailcampaigns')->__('New Segment');
    }
}
