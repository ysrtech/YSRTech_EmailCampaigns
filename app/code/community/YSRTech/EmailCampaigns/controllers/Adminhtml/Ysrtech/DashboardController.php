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
     * Run the sender check: DNS lookups and a question to Mailgun, so on a
     * button rather than on every load of the dashboard.
     */
    public function senderCheckAction()
    {
        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            Mage::getSingleton('ysrtech_emailcampaigns/deliverability')->refresh();
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->_getSession()->addError($e->getMessage());
        }

        $this->_redirect('*/*/index', ['_fragment' => 'ysrtech-ec-sender']);
    }

    /**
     * A fix offered by the sender check that can be made on Mailgun from
     * here: removing its own unsubscribe footer, or moving tracked links to
     * https. The check is run again afterwards so the page shows where
     * things now stand.
     */
    public function mailgunFixAction()
    {
        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            /** @var YSRTech_EmailCampaigns_Model_Mailgun_Tracking $tracking */
            $tracking = Mage::getSingleton('ysrtech_emailcampaigns/mailgun_tracking');
            $what     = (string) $this->getRequest()->getPost('fix');

            if ($what === 'footer') {
                $result = $tracking->clearFooter();
            } elseif ($what === 'https') {
                $result = $tracking->useHttps();
            } else {
                Mage::throwException($this->__('Nothing to fix was named.'));
            }

            if (!$result['ok']) {
                $this->_getSession()->addError($result['message']);
            } elseif (!empty($result['pending'])) {
                $this->_getSession()->addNotice($result['message']);
            } else {
                $this->_getSession()->addSuccess($result['message']);
            }

            Mage::getSingleton('ysrtech_emailcampaigns/deliverability')->refresh();
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('Could not make the change on Mailgun. See the exception log.'));
        }

        $this->_redirect('*/*/index', ['_fragment' => 'ysrtech-ec-sender']);
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
