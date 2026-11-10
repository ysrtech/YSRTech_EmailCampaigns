<?php
/**
 * Actions behind the buttons in System > Configuration > Email Campaigns.
 */
class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_SendingController extends Mage_Adminhtml_Controller_Action
{
    /**
     * The same permission as the configuration section the button sits in.
     */
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config/ysrtech_emailcampaigns');
    }

    /**
     * Check the saved Mailgun settings. Reads only; sends nothing.
     */
    public function testAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHttpResponseCode(405);

            return;
        }

        try {
            $result = Mage::getModel('ysrtech_emailcampaigns/mailgun_connection')->test();
        } catch (Throwable $e) {
            Mage::logException($e);
            $result = ['ok' => false, 'lines' => [['level' => 'error', 'text' => $e->getMessage()]]];
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($result));
    }
}
