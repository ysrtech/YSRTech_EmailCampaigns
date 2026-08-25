<?php
class YSRTech_EmailCampaigns_Model_Resource_Campaign extends YSRTech_EmailCampaigns_Model_Resource_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/campaign', 'campaign_id');
    }
}
