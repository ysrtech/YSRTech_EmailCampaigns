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
     */
    public function suppress(string $email, string $reason = ''): self
    {
        $email = trim($email);

        if ($email === '') {
            return $this;
        }

        $this->loadByEmail($email);

        if (!$this->getId()) {
            $this->setEmail($email)->setToken(Mage::helper('core')->getRandomString(32));
        }

        $this->setUnsubscribedAt(Varien_Date::now());

        if ($reason !== '') {
            $prefs = $this->getPreferencesArray();
            $prefs['suppression_reason'] = $reason;
            $this->setPreferencesArray($prefs);
        }

        $this->save();

        return $this;
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
