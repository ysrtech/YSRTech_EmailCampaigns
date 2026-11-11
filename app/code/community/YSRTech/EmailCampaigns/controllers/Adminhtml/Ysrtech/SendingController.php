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

    /**
     * Check the saved AI settings with a one-word request.
     */
    public function testAiAction()
    {
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->getResponse()->setHttpResponseCode(405);

            return;
        }

        try {
            $result = Mage::getSingleton('ysrtech_emailcampaigns/ai_client')->test();
        } catch (Throwable $e) {
            Mage::logException($e);
            $result = ['ok' => false, 'lines' => [['level' => 'error', 'text' => $e->getMessage()]]];
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($result));
    }

    /**
     * The provider's models, for the selector beside the Model field. The
     * provider is the one chosen on the page, saved or not.
     */
    public function aiModelsAction()
    {
        $result = ['ok' => false];

        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            $result = ['ok' => true] + Mage::getSingleton('ysrtech_emailcampaigns/ai_client')
                ->listModels((string) $this->getRequest()->getPost('provider'));

            $result['note'] = $this->__('Recommended are the newest everyday models from each company: any of them writes subject lines and drafts well. Prices are the provider\'s; a suggestion or a design uses a few thousand tokens.');
        } catch (Mage_Core_Exception $e) {
            $result['message'] = $e->getMessage();
        } catch (Throwable $e) {
            Mage::logException($e);
            $result['message'] = $this->__('The list could not be loaded. Type the model id instead.');
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($result));
    }

    /**
     * Put every existing template and draft campaign into the store's
     * header and footer. Behind the button in the Brand settings.
     */
    public function applyBrandAction()
    {
        $response = ['ok' => false];

        try {
            if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
                Mage::throwException($this->__('This page has been open a while. Reload it and try again.'));
            }

            $counts = Mage::getSingleton('ysrtech_emailcampaigns/brand')->applyToExisting();

            $response = [
                'ok'      => true,
                'message' => $this->__(
                    '%d template(s) and %d draft campaign(s) updated; %d old footer(s) removed.',
                    $counts['templates'],
                    $counts['campaigns'],
                    $counts['footers']
                ),
            ];
        } catch (Throwable $e) {
            Mage::logException($e);
            $response['message'] = $e->getMessage();
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode($response));
    }
}
