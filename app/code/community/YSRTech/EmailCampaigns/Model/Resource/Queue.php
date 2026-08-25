<?php
class YSRTech_EmailCampaigns_Model_Resource_Queue extends YSRTech_EmailCampaigns_Model_Resource_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/queue', 'queue_id');
    }
}
