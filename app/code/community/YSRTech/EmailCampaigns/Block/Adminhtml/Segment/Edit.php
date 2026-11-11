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

        $this->_updateButton('save', 'label', Mage::helper('ysrtech_emailcampaigns')->__('Save Segment'));
    }

    /**
     * Back to the segment's subscribers when there is a segment to go back to.
     */
    public function getBackUrl()
    {
        $model = Mage::registry('ysrtech_emailcampaigns_segment');

        return $model->getId() ? $this->getUrl('*/*/view', ['id' => $model->getId()]) : $this->getUrl('*/*/index');
    }

    public function getPreviewUrl(): string
    {
        return $this->getUrl('*/*/preview');
    }

    public function getHeaderText()
    {
        /** @var YSRTech_EmailCampaigns_Model_Segment $model */
        $model = Mage::registry('ysrtech_emailcampaigns_segment');
        return $model->getId()
            ? Mage::helper('ysrtech_emailcampaigns')->__('Edit Segment Rules: %s', $this->escapeHtml($model->getName()))
            : Mage::helper('ysrtech_emailcampaigns')->__('New Segment');
    }
}
