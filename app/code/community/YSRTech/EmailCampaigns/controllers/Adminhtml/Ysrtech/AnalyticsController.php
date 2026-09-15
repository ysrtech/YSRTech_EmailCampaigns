<?php
// Not autoloadable (lives under controllers/, which the classname-to-path
// convention doesn't cover), so the shared base class needs an explicit include.
require_once __DIR__ . '/../Controller/Abstract.php';

class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_AnalyticsController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Analytics'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/analytics');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_analytics'));
        $this->renderLayout();
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_analytics_grid')->toHtml()
        );
    }
}
