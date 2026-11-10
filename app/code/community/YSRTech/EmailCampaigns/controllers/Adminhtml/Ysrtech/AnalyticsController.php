<?php
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

/**
 * Analytics became the Dashboard in 0.10.0. Kept so that a bookmark or a
 * link in an old email to the team still lands somewhere.
 */
class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_AnalyticsController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    protected $_aclNode = 'dashboard';

    public function indexAction()
    {
        $this->_redirect('*/ysrtech_dashboard/index');
    }

    public function gridAction()
    {
        $this->_forward('grid', 'ysrtech_dashboard');
    }
}
