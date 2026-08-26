<?php
/** Full-screen drag & drop email designer. */
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Editor extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/editor.phtml');
    }

    /** @return YSRTech_EmailCampaigns_Model_Template */
    public function getTemplateModel()
    {
        return Mage::registry('ysrtech_emailcampaigns_template');
    }

    public function getSaveUrl()
    {
        return $this->getUrl('*/*/save', [
            'id'   => $this->getTemplateModel()->getId(),
            'back' => 'editor',
        ]);
    }

    public function getCatalogSearchUrl()
    {
        return $this->getUrl('*/*/catalogSearch');
    }
}
