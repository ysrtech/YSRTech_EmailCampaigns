<?php
/**
 * The storefront end of the unsubscribe link that goes in every campaign.
 *
 * Every message carries emailcampaigns/preferences/unsubscribe/token/<token>,
 * and until now the module declared the route but shipped no controller behind
 * it, so each one of those links was a 404.
 *
 * The token is the queue row's tracking token: it is per recipient, random and
 * never reused, so it stands in for authentication without asking someone who
 * wants out of a mailing list to sign in first.
 */
class YSRTech_EmailCampaigns_PreferencesController extends Mage_Core_Controller_Front_Action
{
    /**
     * @return YSRTech_EmailCampaigns_Model_Queue|null
     */
    protected function _loadByToken()
    {
        $token = trim((string) $this->getRequest()->getParam('token'));

        // A short or empty token would match a great many rows, or none
        if (strlen($token) < 16) {
            return null;
        }

        /** @var YSRTech_EmailCampaigns_Model_Resource_Queue_Collection $collection */
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/queue_collection')
            ->addFieldToFilter('tracking_token', $token)
            ->setPageSize(1);

        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
    }

    /**
     * Record the unsubscribe and say so. Deliberately a GET: a mail client
     * following the List-Unsubscribe header will not post a form, and a
     * recipient who clicks the link expects it to be done.
     */
    public function unsubscribeAction()
    {
        $helper  = Mage::helper('ysrtech_emailcampaigns');
        $session = Mage::getSingleton('core/session');

        if (!$item = $this->_loadByToken()) {
            $session->addError($helper->__('That unsubscribe link is not valid. It may already have been used.'));
            $this->_redirect('');
            return;
        }

        try {
            $this->_unsubscribe((string) $item->getEmail(), (string) $item->getTrackingToken());
            $session->addSuccess(
                $helper->__('%s has been unsubscribed. You will not receive further campaign emails.', $item->getEmail())
            );
        } catch (Exception $e) {
            Mage::logException($e);
            $session->addError($helper->__('We could not record that. Please try again later.'));
        }

        $this->_redirect('');
    }

    /**
     * @param string $email
     * @param string $token
     */
    protected function _unsubscribe($email, $token)
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/subscriber_pref');

        /*
         * insertOnDuplicate rather than a read then a write: the same address
         * can hold links from several campaigns, and two of them clicked
         * together should not race into two rows.
         */
        $adapter->insertOnDuplicate(
            $table,
            array(
                'email'           => $email,
                'token'           => $token,
                'unsubscribed_at' => Varien_Date::now(),
            ),
            array('unsubscribed_at')
        );

        /*
         * Anything still queued for this address goes now. Without this the
         * unsubscribe only takes effect for campaigns queued afterwards, and
         * the messages already waiting are still delivered.
         */
        $adapter->update(
            $resource->getTableName('ysrtech_emailcampaigns/queue'),
            array('status' => 'cancelled'),
            array('email = ?' => $email, 'status = ?' => 'pending')
        );
    }
}
