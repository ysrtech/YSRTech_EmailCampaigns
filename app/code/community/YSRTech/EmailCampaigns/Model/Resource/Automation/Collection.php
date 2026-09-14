<?php
class YSRTech_EmailCampaigns_Model_Resource_Automation_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/automation');
    }

    /**
     * The automations that should act on a given event right now.
     *
     * @param  string   $event
     * @param  int      $storeId
     * @return $this
     */
    public function addEventFilter(string $event, $storeId = null)
    {
        $today = Mage::getSingleton('core/date')->date('Y-m-d');

        $this->addFieldToFilter('event', $event)
            ->addFieldToFilter('is_active', 1)
            // store_id 0 means every store
            ->addFieldToFilter('store_id', ['in' => [0, (int) $storeId]])
            ->addFieldToFilter('active_from', [['null' => true], ['lteq' => $today]])
            ->addFieldToFilter('active_to', [['null' => true], ['gteq' => $today]]);

        return $this;
    }
}
