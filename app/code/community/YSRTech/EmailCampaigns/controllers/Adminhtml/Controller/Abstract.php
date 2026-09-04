<?php
/**
 * Shared admin controller helpers: ACL checks + grid registration.
 */
abstract class YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
    extends Mage_Adminhtml_Controller_Action
{
    /**
     * OpenMage 20 types this on Mage_Adminhtml_Controller_Action, and PHP 8
     * treats an override that drops the return type as an incompatible
     * signature - a fatal, not a notice.
     */
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns');
    }
}
