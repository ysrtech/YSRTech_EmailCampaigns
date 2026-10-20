<?php
/**
 * Container for the template edit page.
 *
 * Content can be edited two ways: as HTML on the form itself, or visually in
 * the designer. Both write the same html column, so a template can be roughed
 * out in one and finished in the other.
 *
 * An earlier "Open in Designer" button pointed at an editor that was never
 * built and led to a blank page; it was removed rather than left as a trap.
 * This one goes somewhere.
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

        /** @var YSRTech_EmailCampaigns_Model_Template $model */
        $model = Mage::registry('ysrtech_emailcampaigns_template');

        if ($model && $model->getId()) {
            $this->_addButton('designer', [
                'label'   => Mage::helper('ysrtech_emailcampaigns')->__('Open in Designer'),
                'onclick' => "setLocation('" . $this->getUrl('*/*/designer', ['id' => $model->getId()]) . "')",
                'class'   => 'go',
            ], 0);

            $this->_addButton('preview', [
                'label'   => Mage::helper('ysrtech_emailcampaigns')->__('Preview'),
                // A new tab, so an edit in progress is not lost to it
                'onclick' => "window.open('" . $this->getUrl('*/*/preview', ['id' => $model->getId()]) . "')",
                'class'   => 'go',
            ], 0);
        }
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
