<?php
/**
 * Shared admin controller helpers: ACL checks + grid registration.
 */
abstract class YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
    extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('ysrtech_emailcampaigns');
    }
}
