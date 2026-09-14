<?php
/**
 * The chain as a sentence: "Day 0, day 2, day 7" rather than a step count.
 *
 * The shape of a sequence is what somebody scanning the list wants to know -
 * three messages could be three days or three months.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Grid_Renderer_Chain
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    public function render(Varien_Object $row)
    {
        $steps = Mage::getResourceModel('ysrtech_emailcampaigns/automation_step_collection')
            ->addAutomationFilter((int) $row->getAutomationId());

        if (!count($steps)) {
            return '<span style="color:#999;font-style:italic">' . $this->__('No messages') . '</span>';
        }

        $parts = [];

        foreach ($steps as $step) {
            $hours = (int) $step->getAfterDays() * 24 + (int) $step->getAfterHours();

            if ($hours === 0) {
                $parts[] = $this->__('at once');
            } elseif ($hours % 24 === 0) {
                $parts[] = $this->__('day %d', $hours / 24);
            } else {
                $parts[] = $this->__('%dh', $hours);
            }
        }

        return $this->__('%d message(s)', count($steps))
            . '<br /><span style="color:#777">' . $this->escapeHtml(implode(', ', $parts)) . '</span>';
    }
}
