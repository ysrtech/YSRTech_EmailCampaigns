<?php
/**
 * Suppression list, independent of whichever transport did the sending.
 *
 * recipientSelect() already excludes any address with unsubscribed_at set
 * here, so writing to this table takes effect on the next queue build with
 * nothing further to wire up. Keeping it separate from
 * newsletter_subscriber.subscriber_status matters because the two answer
 * different questions: the Magento status is the shop's newsletter flag, and
 * this is "the provider told us not to mail this address again". An address
 * suppressed on Mailgun stays suppressed if the store later moves to Resend
 * or to SMTP, where nobody would have thought to look it up.
 */
class YSRTech_EmailCampaigns_Model_Subscriber_Pref extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/subscriber_pref');
    }

    public function loadByEmail(string $email): self
    {
        return $this->load($email, 'email');
    }

    /**
     * Mark an address suppressed: an unsubscribe, a spam complaint, a hard
     * bounce. Idempotent, so a provider retrying its webhook - which they all
     * do - costs nothing.
     *
     * All three places that stop mailing an address go through here, because
     * doing only part of it is the bug. Writing this table alone leaves the
     * subscriber grid showing them as subscribed and leaves whatever is
     * already queued to go out anyway; setting the newsletter status alone
     * loses the record the moment the store changes transport.
     */
    public function suppress(string $email, string $reason = ''): self
    {
        $email = trim($email);

        if ($email === '') {
            return $this;
        }

        $this->loadByEmail($email);

        if (!$this->getId()) {
            // A token of its own, not the token of whichever send prompted
            // this: it identifies the person for a preference centre, and one
            // campaign's send token is the wrong lifetime for that.
            $this->setEmail($email)->setToken(Mage::helper('core')->getRandomString(32));
        }

        $this->setUnsubscribedAt(Varien_Date::now());

        if ($reason !== '') {
            $prefs = $this->getPreferencesArray();
            $prefs['suppression_reason'] = $reason;
            $this->setPreferencesArray($prefs);
        }

        $this->save();

        $this->_syncNewsletterStatus($email);
        $this->_cancelQueued($email, $reason);

        return $this;
    }

    /**
     * Magento's own newsletter status, which is what the rest of the store
     * reads: the admin's Newsletter Subscribers grid, the customer's account
     * page, and this module's own queue builder.
     */
    private function _syncNewsletterStatus(string $email): void
    {
        try {
            $subscriber = Mage::getModel('newsletter/subscriber')->loadByEmail($email);

            if ($subscriber->getId()
                && (int) $subscriber->getStatus() !== Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED
            ) {
                $subscriber->setStatus(Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED)->save();
            }
        } catch (Exception $e) {
            // The record above already stands. An opt-out is not worth losing
            // over a newsletter row that would not save.
            Mage::logException($e);
        }
    }

    /**
     * Anything already queued for this address goes now. Without it the
     * suppression only covers campaigns queued afterwards, and the messages
     * already waiting are delivered to somebody who has just asked not to
     * receive them.
     */
    private function _cancelQueued(string $email, string $reason): void
    {
        try {
            /** @var Mage_Core_Model_Resource $resource */
            $resource = Mage::getSingleton('core/resource');
            /*
             * Pending only. A row in "sending" is claimed by a run that may be
             * posting it to the provider as this writes, and taking it out
             * from underneath that run would have two things deciding what the
             * row says. One more message goes out; the next does not.
             */
            $resource->getConnection('core_write')->update(
                $resource->getTableName('ysrtech_emailcampaigns/queue'),
                [
                    'status'        => 'cancelled',
                    'error_message' => 'Suppressed: ' . ($reason !== '' ? $reason : 'unsubscribed'),
                ],
                ['email = ?' => $email, 'status = ?' => 'pending']
            );
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    public function getPreferencesArray(): array
    {
        $json = (string) $this->getPreferencesJson();

        return $json !== '' ? (json_decode($json, true) ?: []) : [];
    }

    public function setPreferencesArray(array $prefs): self
    {
        return $this->setPreferencesJson(json_encode($prefs));
    }
}
