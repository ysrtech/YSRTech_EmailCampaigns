<?php
/**
 * An automation ("drip") flow: a trigger plus a graph of delay/condition/
 * send-email nodes, walked per-enrolled-customer by Flow/Engine.php.
 */
class YSRTech_EmailCampaigns_Model_Flow extends Mage_Core_Model_Abstract
{
    public const STATUS_DRAFT  = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/flow');
    }
}
