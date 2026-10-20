<?php
/*
 * Magento 1 does not autoload controller classes - they sit outside the
 * autoloader's class path - so a controller extending another one has to
 * pull it in itself, or the class is simply not there when the router
 * instantiates this file.
 */
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

/**
 * The Analytics menu entry pointed here and nothing answered, so it 404'd.
 *
 * What it reports is what the module actually records: the state of each
 * campaign's send queue. Opens and clicks are not in it, because nothing
 * collects them yet - the queue table has no column to put them in.
 */
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

    /**
     * The grid reloads itself over ajax when sorted or paged.
     */
    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_analytics_grid')->toHtml()
        );
    }
}
