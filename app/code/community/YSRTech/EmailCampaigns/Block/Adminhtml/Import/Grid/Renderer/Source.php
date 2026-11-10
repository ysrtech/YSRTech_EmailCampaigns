<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_Grid_Renderer_Source
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        if ($row->getData('type') === YSRTech_EmailCampaigns_Model_Import::TYPE_CSV) {
            return $this->escapeHtml(Mage::helper('ysrtech_emailcampaigns')->__('CSV file'));
        }

        $providers = YSRTech_EmailCampaigns_Model_Import::providers();

        return $this->escapeHtml($providers[$row->getData('provider')] ?? (string) $row->getData('provider'));
    }
}
