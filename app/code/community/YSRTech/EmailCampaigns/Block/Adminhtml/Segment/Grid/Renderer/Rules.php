<?php
/**
 * A segment's rules in a few words, for the list: "2 conditions, match all".
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Segment_Grid_Renderer_Rules
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    #[Override]
    public function render(Varien_Object $row)
    {
        $h       = Mage::helper('ysrtech_emailcampaigns');
        $segment = Mage::getModel('ysrtech_emailcampaigns/segment')->load((int) $row->getId());
        $lines   = $h->segmentRuleLines($segment);

        if (!$lines) {
            return $this->escapeHtml($h->__('Every subscriber'));
        }

        $conditions = $segment->getConditions();
        $match      = $conditions && $conditions->getAggregator() === 'any' ? $h->__('match any') : $h->__('match all');

        return '<span title="' . $this->escapeHtml(implode("\n", $lines)) . '">'
            . $this->escapeHtml($h->__('%d condition(s), %s', count($lines), $match)) . '</span>';
    }
}
