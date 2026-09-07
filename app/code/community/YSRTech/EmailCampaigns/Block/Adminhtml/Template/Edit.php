<?php
/**
 * Container for the template edit page.
 *
 * There was an "Open in Designer" button here pointing at a drag and drop
 * editor that was never built: its block referenced a phtml that does not
 * exist, so the button led to a blank page. A control that does nothing is
 * worse than an absent one - somebody clicks it, loses the page they were
 * on, and has no way to tell whether the feature broke or never existed.
 * Content is edited as HTML on the form itself.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_objectId   = 'id';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_template';
        $this->_mode       = 'edit';

    }

    public function getHeaderText()
    {
        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::registry('ysrtech_emailcampaigns_template');
        return $model->getId()
            ? Mage::helper('ysrtech_emailcampaigns')->__('Edit Template "%s"', $this->escapeHtml($model->getName()))
            : Mage::helper('ysrtech_emailcampaigns')->__('New Template');
    }
}
