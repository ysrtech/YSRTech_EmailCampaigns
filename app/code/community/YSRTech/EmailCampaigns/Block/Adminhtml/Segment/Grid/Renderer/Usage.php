<?php
/**
 * Which campaigns a segment is used by, and how.
 *
 * A segment nothing points at is safe to delete; one a campaign targets is
 * not, and the difference is worth seeing before clicking Delete rather than
 * after.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Grid_Renderer_Usage
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    /**
     * @param  Varien_Object $row
     * @return string
     */
    public function render(Varien_Object $row)
    {
        $names = trim((string) $row->getCampaignNames());

        if ($names === '') {
            // Plain and grey rather than a severity badge: nothing is wrong
            // with an unused segment, and a full width green banner reading
            // NOT USED says otherwise
            return '<span style="color:#999;font-style:italic">' . $this->__('Not used') . '</span>';
        }

        $lines = array_map(
            fn($name) => $this->escapeHtml($name),
            array_filter(explode("\n", $names), 'strlen')
        );

        return implode('<br />', $lines);
    }
}
