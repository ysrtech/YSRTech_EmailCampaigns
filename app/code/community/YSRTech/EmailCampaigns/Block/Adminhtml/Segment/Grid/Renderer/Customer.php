<?php
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Grid_Renderer_Customer
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return (int) $row->getData('customer_id')
            ? $this->escapeHtml($h->__('Yes (#%d)', (int) $row->getData('customer_id')))
            : $this->escapeHtml($h->__('No'));
    }
}
