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
     * Record the unsubscribe and confirm it on a page of its own.
     *
     * Deliberately a GET: a mail client following the List-Unsubscribe header
     * will not post a form, and a recipient who clicks the link expects it to
     * be done rather than to be asked again.
     */
    public function unsubscribeAction()
    {
        $email = null;
        $item  = $this->_loadByToken();

        if ($item) {
            try {
                /*
                 * The same call the provider webhooks make, so a click here
                 * and an unsubscribe recorded on Mailgun's own page have the
                 * same effect rather than two thirds of it each.
                 */
                Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')
                    ->suppress((string) $item->getEmail(), 'unsubscribed');
                $email = (string) $item->getEmail();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        // Null tells the page the link did not resolve, which reads the same
        // to the recipient whether the token was wrong or the write failed:
        // either way they are owed an honest "that did not work".
        Mage::register('ysrtech_emailcampaigns_preferences_email', $email);

        $this->loadLayout();

        if ($head = $this->getLayout()->getBlock('head')) {
            $head->setTitle(Mage::helper('ysrtech_emailcampaigns')->__('Unsubscribe'));
        }

        if ($content = $this->getLayout()->getBlock('content')) {
            $content->append($this->getLayout()->createBlock('ysrtech_emailcampaigns/unsubscribe'));
        }

        $this->renderLayout();
    }

}
