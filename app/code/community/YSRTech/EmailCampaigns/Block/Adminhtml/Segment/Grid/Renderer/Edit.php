<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Grid_Renderer_Edit
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        return '<a href="' . $this->getUrl('*/*/edit', ['id' => (int) $row->getId()]) . '">'
            . $this->escapeHtml(Mage::helper('ysrtech_emailcampaigns')->__('Edit rules')) . '</a>';
    }
}
