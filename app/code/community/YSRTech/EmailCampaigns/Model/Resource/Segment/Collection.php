<?php
class YSRTech_EmailCampaigns_Model_Resource_Segment_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/segment');
    }
}
