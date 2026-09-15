<?php
/**
 * One customer's progress through one Flow — which node they're at, and
 * (for a Delay node) when they're due to advance past it.
 */
class YSRTech_EmailCampaigns_Model_Flow_Enrollment extends Mage_Core_Model_Abstract
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXITED    = 'exited';

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/flow_enrollment');
    }
}
