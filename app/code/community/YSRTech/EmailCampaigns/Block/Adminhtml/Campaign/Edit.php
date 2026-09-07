<?php
/** Container for campaign edit page. */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        parent::__construct();
        $this->_objectId   = 'id';
        $this->_blockGroup = 'ysrtech_emailcampaigns';
        $this->_controller = 'adminhtml_campaign';
        $this->_mode       = 'edit';

        /** @var YSRTech_EmailCampaigns_Model_Campaign $model */
        $model = Mage::registry('ysrtech_emailcampaigns_campaign');

        if ($model && $model->getId()) {
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
        /** @var YSRTech_EmailCampaigns_Model_Campaign $model */
        $model = Mage::registry('ysrtech_emailcampaigns_campaign');
        return $model->getId()
            ? Mage::helper('ysrtech_emailcampaigns')->__('Edit Campaign "%s"', $this->escapeHtml($model->getName()))
            : Mage::helper('ysrtech_emailcampaigns')->__('New Campaign');
    }
}
