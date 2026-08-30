<?php
/** Full-screen React Flow automation-flow canvas. */
class YSRTech_EmailCampaigns_Block_Adminhtml_Flow_Editor extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/flow_editor.phtml');
    }

    /** @return YSRTech_EmailCampaigns_Model_Flow */
    public function getFlowModel()
    {
        return Mage::registry('ysrtech_emailcampaigns_flow');
    }

    public function getSaveUrl()
    {
        return $this->getUrl('*/*/save');
    }

    /** Templates available to an Action: Send Email node's picker. */
    public function getTemplatesJson()
    {
        $templates = [];
        foreach (Mage::getResourceModel('ysrtech_emailcampaigns/template_collection') as $template) {
            $templates[] = ['id' => (int) $template->getId(), 'name' => (string) $template->getName()];
        }
        return Mage::helper('core')->jsonEncode($templates);
    }

    /**
     * The same Website > Store Group > Store View tree Campaigns already use
     * (see Block/Adminhtml/Campaign/Edit/Form.php) — a flat array where an
     * entry's `value` is either a store id (a plain option) or an array of
     * child {label, value} options (an optgroup). "All Store Views" (id 0)
     * enrolls regardless of which store an order was placed on; a specific
     * store view only enrolls orders placed on that one.
     */
    public function getStoreOptionsJson()
    {
        return Mage::helper('core')->jsonEncode(
            Mage::getSingleton('adminhtml/system_store')->getStoreValuesForForm(false, true)
        );
    }
}
