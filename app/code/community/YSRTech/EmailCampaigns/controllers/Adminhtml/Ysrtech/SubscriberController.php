<?php
require_once Mage::getModuleDir('controllers', 'YSRTech_EmailCampaigns')
    . DS . 'Adminhtml' . DS . 'Controller' . DS . 'Abstract.php';

/**
 * Subscribers: the newsletter list, and one page per person on it.
 */
class YSRTech_EmailCampaigns_Adminhtml_Ysrtech_SubscriberController
    extends YSRTech_EmailCampaigns_Adminhtml_Controller_Abstract
{
    protected $_aclNode = 'subscriber';

    public function indexAction()
    {
        $this->_title($this->__('Email Campaigns'))->_title($this->__('Subscribers'));
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/subscriber');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_subscriber'));
        $this->renderLayout();
    }

    public function gridAction()
    {
        $this->loadLayout();
        $this->getResponse()->setBody(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_subscriber_grid')->toHtml()
        );
    }

    public function exportCsvAction()
    {
        $this->_prepareDownloadResponse(
            'subscribers.csv',
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_subscriber_grid')->getCsvFile()
        );
    }

    /**
     * One subscriber, all round.
     */
    public function viewAction()
    {
        $subscriber = $this->_initSubscriber();

        if (!$subscriber) {
            return;
        }

        $profile = Mage::getModel('ysrtech_emailcampaigns/subscriber_profile')->setSubscriber($subscriber);
        Mage::register('ysrtech_emailcampaigns_subscriber_profile', $profile);

        $this->_title($this->__('Email Campaigns'))->_title($this->__('Subscribers'))->_title($profile->getEmail());
        $this->loadLayout();
        $this->_setActiveMenu('ysrtech_emailcampaigns/subscriber');
        $this->_addContent($this->getLayout()->createBlock('ysrtech_emailcampaigns/adminhtml_subscriber_view'));
        $this->renderLayout();
    }

    /**
     * Unsubscribe one address from its page.
     */
    public function unsubscribeAction()
    {
        $subscriber = $this->_initSubscriber();

        if (!$subscriber) {
            return;
        }

        if ($this->getRequest()->isPost() && $this->_validateFormKey()) {
            try {
                $this->_unsubscribe($subscriber);
                $this->_getSession()->addSuccess($this->__('%s is unsubscribed. No email was sent to tell them.', $subscriber->getSubscriberEmail()));
            } catch (Exception $e) {
                Mage::logException($e);
                $this->_getSession()->addError($this->__('Could not unsubscribe this address. See the exception log.'));
            }
        }

        $this->_redirect('*/*/view', ['id' => (int) $subscriber->getId()]);
    }

    public function massUnsubscribeAction()
    {
        $done = 0;

        try {
            foreach ($this->_selected() as $subscriber) {
                $this->_unsubscribe($subscriber);
                $done++;
            }

            $this->_getSession()->addSuccess($this->__('%d address(es) unsubscribed.', $done));
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('Stopped after %d: see the exception log.', $done));
        }

        $this->_redirect('*/*/index');
    }

    public function massDeleteAction()
    {
        $done = 0;

        try {
            foreach ($this->_selected() as $subscriber) {
                $subscriber->delete();
                $done++;
            }

            $this->_getSession()->addSuccess($this->__('%d subscriber(s) deleted.', $done));
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_getSession()->addError($this->__('Stopped after %d: see the exception log.', $done));
        }

        $this->_redirect('*/*/index');
    }

    /**
     * The newsletter model's own unsubscribe() emails the person a notice.
     * Somebody taken off the list by staff has not asked for a message, so
     * the status is turned and nothing is sent.
     */
    protected function _unsubscribe(Mage_Newsletter_Model_Subscriber $subscriber): void
    {
        if ((int) $subscriber->getSubscriberStatus() === Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED) {
            return;
        }

        $subscriber
            ->setSubscriberStatus(Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED)
            ->setChangeStatusAt(Varien_Date::now())
            ->setIsStatusChanged(true)
            ->save();
    }

    /**
     * The subscribers ticked in the grid. Only a POST carrying the session's
     * key counts: these change the list.
     *
     * @return Mage_Newsletter_Model_Subscriber[]
     */
    protected function _selected(): array
    {
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            return [];
        }

        $ids = array_filter(array_map('intval', (array) $this->getRequest()->getParam('subscriber')));

        if (!$ids) {
            $this->_getSession()->addError($this->__('Tick the subscribers first.'));

            return [];
        }

        return Mage::getResourceModel('newsletter/subscriber_collection')
            ->addFieldToFilter('subscriber_id', ['in' => $ids])
            ->getItems();
    }

    protected function _initSubscriber(): ?Mage_Newsletter_Model_Subscriber
    {
        $subscriber = Mage::getModel('newsletter/subscriber')->load((int) $this->getRequest()->getParam('id'));

        if (!$subscriber->getId()) {
            $this->_getSession()->addError($this->__('That subscriber is no longer on the list.'));
            $this->_redirect('*/*/index');

            return null;
        }

        return $subscriber;
    }
}
