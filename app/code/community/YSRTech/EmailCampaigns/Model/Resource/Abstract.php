<?php
abstract class YSRTech_EmailCampaigns_Model_Resource_Abstract extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init($this->_resourceName, $this->_idFieldName);
    }
}
