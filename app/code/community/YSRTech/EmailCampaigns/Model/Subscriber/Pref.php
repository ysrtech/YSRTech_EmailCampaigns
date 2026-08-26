<?php
/**
 * Suppression list, transport-independent. buildQueue() excludes anyone with
 * unsubscribed_at set here regardless of which transport actually sent to them,
 * so a Mailgun-side unsubscribe/bounce still suppresses future sends even if
 * the store later switches to Resend/SMTP.
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
     * Mark an address suppressed (unsubscribe, spam complaint, hard bounce, ...).
     * Idempotent — safe to call repeatedly for the same address/reason.
     */
    public function suppress(string $email, string $reason = ''): self
    {
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
