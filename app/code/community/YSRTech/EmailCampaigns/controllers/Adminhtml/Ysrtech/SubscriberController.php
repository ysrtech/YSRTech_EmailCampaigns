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

    /**
     * Put one address back on the list from its page.
     *
     * Nothing is emailed: the newsletter model's subscribe() would send a
     * confirmation, and this is staff acting on the person's own request.
     * An unsubscribe recorded on the suppression list is lifted with it, or
     * the address would be subscribed in name and still never mailed. A
     * hard bounce or a spam complaint is not: those are lifted, by somebody
     * allowed to, on the Suppressions page.
     */
    public function resubscribeAction()
    {
        $subscriber = $this->_initSubscriber();

        if (!$subscriber) {
            return;
        }

        if ($this->getRequest()->isPost() && $this->_validateFormKey()) {
            try {
                $email    = (string) $subscriber->getSubscriberEmail();
                $resource = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression');
                $reason   = $resource->reasonOf($email);
                $blocking = [
                    YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE,
                    YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT,
                ];

                if ($reason && in_array($reason, $blocking, true)) {
                    Mage::throwException($this->__('This address is suppressed for a hard bounce or a spam complaint. Remove it on the Suppressions page first.'));
                }

                if ($reason) {
                    $adapter = Mage::getSingleton('core/resource')->getConnection('core_read');
                    $ids     = $adapter->fetchCol(
                        $adapter->select()->from($resource->getMainTable(), ['suppression_id'])->where('email = ?', strtolower(trim($email)))
                    );
                    $removed = $resource->removeByIds(array_map('intval', $ids));

                    if (strtolower((string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/transport')) === 'mailgun') {
                        try {
                            Mage::getSingleton('ysrtech_emailcampaigns/suppressions')->liftOnMailgun($removed);
                        } catch (Exception $e) {
                            Mage::logException($e);
                            $this->_getSession()->addNotice($this->__('Mailgun could not be told to lift its own unsubscribe for this address. Remove it there, or Mailgun will go on refusing mail to it.'));
                        }
                    }
                }

                $subscriber->load($subscriber->getId())
                    ->setSubscriberStatus(Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED)
                    ->setChangeStatusAt(Varien_Date::now())
                    ->setIsStatusChanged(true)
                    ->save();

                $this->_getSession()->addSuccess($this->__('%s is subscribed again. No email was sent to tell them.', $email));
            } catch (Mage_Core_Exception $e) {
                $this->_getSession()->addError($e->getMessage());
            } catch (Exception $e) {
                Mage::logException($e);
                $this->_getSession()->addError($this->__('Could not subscribe this address. See the exception log.'));
            }
        }

        $this->_redirect('*/*/view', ['id' => (int) $subscriber->getId()]);
    }

    /**
     * The "Sync Names With Customers" button: the list takes its names from
     * the customer accounts.
     */
    public function syncNamesAction()
    {
        if ($this->getRequest()->isPost() && $this->_validateFormKey()) {
            try {
                $result = Mage::getSingleton('ysrtech_emailcampaigns/subscriber_names')->sync();

                $this->_getSession()->addSuccess($result['named'] || $result['linked']
                    ? $this->__('Names synced: %s subscriber(s) now have the name on their customer account. %s subscriber(s) were matched to an account for the first time.',
                        number_format($result['named']), number_format($result['linked']))
                    : $this->__('Everything was already in step: no subscriber needed a name from their account.'));
            } catch (Exception $e) {
                Mage::logException($e);
                $this->_getSession()->addError($this->__('Could not sync the names. See the exception log.'));
            }
        }

        $this->_redirect('*/*/index');
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
