<?php
/**
 * Self-hosted unsubscribe fallback for transports with no hosted unsubscribe
 * click of their own (Resend's transactional /emails API and generic SMTP —
 * unlike Mailgun's %recipient.unsubscribe_url%, see Model/Sender.php).
 */
class YSRTech_EmailCampaigns_PreferencesController extends Mage_Core_Controller_Front_Action
{
    public function unsubscribeAction()
    {
        $email = $this->_resolveEmailFromToken((string) $this->getRequest()->getParam('_token'));
        if ($email !== null) {
            Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')->suppress($email, 'unsubscribed');
        }
        Mage::register('ysrtech_emailcampaigns_preferences_email', $email);

        $this->loadLayout();
        $this->getLayout()->getBlock('head')->setTitle($this->__('Unsubscribe'));
        $this->getLayout()->getBlock('content')->append(
            $this->getLayout()->createBlock('ysrtech_emailcampaigns/unsubscribe')
        );
        $this->renderLayout();
    }

    /**
     * The link embeds one specific send's tracking_token (Queue::tracking_token),
     * not a subscriber-level token — resolving through the queue row is what ties
     * the click back to the actual recipient address.
     */
    private function _resolveEmailFromToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        /** @var YSRTech_EmailCampaigns_Model_Queue $queueItem */
        $queueItem = Mage::getModel('ysrtech_emailcampaigns/queue')->load($token, 'tracking_token');
        return $queueItem->getId() ? (string) $queueItem->getEmail() : null;
    }
}
