<?php
/**
 * "Straight away" or "2 days later", rather than the three columns it takes
 * to store that.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Automation_Grid_Renderer_Delay
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    public function render(Varien_Object $row)
    {
        if ($row->getSendMoment() !== 'after') {
            return $this->__('Straight away');
        }

        $parts = [];
        $days  = (int) $row->getAfterDays();
        $hours = (int) $row->getAfterHours();

        if ($days) {
            $parts[] = $this->__('%d day(s)', $days);
        }

        if ($hours) {
            $parts[] = $this->__('%d hour(s)', $hours);
        }

        return $parts ? $this->__('%s later', implode(', ', $parts)) : $this->__('Straight away');
    }
}
