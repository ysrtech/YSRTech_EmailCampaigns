<?php
/**
 * One figure out of an import's counts, which are kept together as JSON.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Import_Grid_Renderer_Stat
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        $stats = json_decode((string) $row->getData('stats_json'), true);
        $key   = (string) $this->getColumn()->getData('stat');

        return isset($stats[$key]) && $stats[$key] ? number_format((int) $stats[$key]) : '';
    }
}
