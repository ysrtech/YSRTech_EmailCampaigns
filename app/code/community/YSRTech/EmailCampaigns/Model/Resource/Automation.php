<?php
class YSRTech_EmailCampaigns_Model_Resource_Automation extends YSRTech_EmailCampaigns_Model_Resource_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/automation', 'automation_id');
    }
}
