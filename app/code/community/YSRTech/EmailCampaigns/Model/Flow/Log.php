<?php
/** One row per node an enrollment passed through — the "why did/didn't this send" trail. */
class YSRTech_EmailCampaigns_Model_Flow_Log extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/flow_log');
    }
}
