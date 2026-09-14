<?php
/**
 * One message in a chain.
 *
 * The delay is measured from the moment the chain was triggered rather than
 * from the step before it, so "day 2, day 7, day 30" reads the same in the
 * form as it does in somebody's inbox, and changing one step does not quietly
 * move everything after it.
 */
class YSRTech_EmailCampaigns_Model_Automation_Step extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/automation_step');
    }

    /**
     * @return int Seconds after the trigger
     */
    public function getDelaySeconds(): int
    {
        return ((int) $this->getAfterDays() * 86400) + ((int) $this->getAfterHours() * 3600);
    }

    /**
     * @param  int $triggeredAt
     * @return string
     */
    public function getSendAt(int $triggeredAt): string
    {
        // gmdate: the queue stores and compares UTC
        return gmdate('Y-m-d H:i:s', $triggeredAt + $this->getDelaySeconds());
    }

    /**
     * @return string
     */
    public function getDelayLabel(): string
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        if (!$this->getDelaySeconds()) {
            return $h->__('Straight away');
        }

        $parts = [];

        if ((int) $this->getAfterDays()) {
            $parts[] = $h->__('%d day(s)', (int) $this->getAfterDays());
        }

        if ((int) $this->getAfterHours()) {
            $parts[] = $h->__('%d hour(s)', (int) $this->getAfterHours());
        }

        return $h->__('%s later', implode(', ', $parts));
    }
}
