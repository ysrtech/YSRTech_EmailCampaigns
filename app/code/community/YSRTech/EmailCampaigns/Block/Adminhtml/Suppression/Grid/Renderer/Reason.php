<?php
/**
 * The reason as one of the admin theme's own severity badges, so a complaint
 * stands out from an unsubscribe without the page bringing a palette of its
 * own.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Suppression_Grid_Renderer_Reason
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        $reason = (string) $row->getData('reason');
        $labels = YSRTech_EmailCampaigns_Model_Suppression::reasons();

        $class = [
            YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT   => 'grid-severity-critical',
            YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE => 'grid-severity-major',
            YSRTech_EmailCampaigns_Model_Suppression::REASON_UNSUBSCRIBE => 'grid-severity-minor',
            YSRTech_EmailCampaigns_Model_Suppression::REASON_MANUAL      => 'grid-severity-notice',
        ][$reason] ?? 'grid-severity-notice';

        return '<span class="' . $class . '"><span>' . $this->escapeHtml($labels[$reason] ?? $reason) . '</span></span>';
    }

    #[Override]
    public function renderExport(Varien_Object $row)
    {
        $labels = YSRTech_EmailCampaigns_Model_Suppression::reasons();
        $reason = (string) $row->getData('reason');

        return $labels[$reason] ?? $reason;
    }
}
