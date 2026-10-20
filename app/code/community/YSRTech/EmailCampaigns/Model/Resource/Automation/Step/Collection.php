<?php
class YSRTech_EmailCampaigns_Model_Resource_Automation_Step_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/automation_step');
    }

    /**
     * @param  int $automationId
     * @return $this
     */
    public function addAutomationFilter(int $automationId)
    {
        return $this->addFieldToFilter('automation_id', $automationId)
            ->setOrder('sort_order', 'ASC')
            ->setOrder('step_id', 'ASC');
    }
}
