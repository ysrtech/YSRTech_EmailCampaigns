<?php
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_DashboardController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    protected $_aclNode = 'dashboard';

    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Dashboard'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/dashboard');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_dashboard'));
        // Per campaign, underneath: what the Analytics page used to be
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_analytics'));
        $this->renderLayout();
    }

    /**
     * The per-campaign grid reloads itself over ajax when sorted or paged.
     */
    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_analytics_grid')->toHtml()
        );
    }
}
