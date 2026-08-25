<?php
/**
 * Factory: returns the configured transport adapter.
 */
class YSRTech_EmailCampaigns_Model_Transport_Factory
{
    /** @var YSRTech_EmailCampaigns_Model_Transport_Interface[] */
    private $instances = [];

    public function get(?string $code = null): YSRTech_EmailCampaigns_Model_Transport_Interface
    {
        $code = $code ?: (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/transport');
        if (!isset($this->instances[$code])) {
            $model = Mage::getModel('ysrtech_emailcampaigns/transport_' . $code);
            if (!$model instanceof YSRTech_EmailCampaigns_Model_Transport_Interface) {
                Mage::throwException("Unknown email transport '{$code}'.");
            }
            $this->instances[$code] = $model;
        }
        return $this->instances[$code];
    }
}
