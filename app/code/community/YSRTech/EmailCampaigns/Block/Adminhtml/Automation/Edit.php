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

        /*
         * The two editors are two views of one automation, not two features.
         * The canvas draws the trigger, the waits and the messages; the form
         * holds what the canvas has no nodes for. Offered only once there is
         * something to draw, because a graph for a record that does not exist
         * yet would have nowhere to save to.
         */
        if (Mage::registry('ysrtech_emailcampaigns_automation')->getId()) {
            $this->_addButton('canvas', [
                'label'   => Mage::helper('ysrtech_emailcampaigns')->__('Open Visual Editor'),
                'onclick' => 'setLocation(\'' . $this->getUrl('*/*/canvas', ['id' => $this->getRequest()->getParam('id')]) . '\')',
            ], 0, 10);
        }
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
