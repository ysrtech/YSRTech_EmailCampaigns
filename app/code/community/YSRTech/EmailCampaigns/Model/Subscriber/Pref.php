<?php
/**
 * Suppression list, independent of whichever transport did the sending.
 *
 * The list itself moved to the suppression table in 0.10.0, which records a
 * reason, a detail and a date per address; this model is the door the
 * webhooks and the unsubscribe page come through. Keeping it separate from
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
     * Kept as the entry point the webhooks and the unsubscribe page already
     * call. The work is the suppression list's now: it writes the row with
     * its reason, turns the newsletter status the rest of the store reads,
     * and cancels whatever was already queued for the address.
     *
     * @param string $reason A provider's event name or one of
     *                       Model_Suppression's reasons
     */
    public function suppress(string $email, string $reason = '', string $detail = '', string $source = 'webhook'): self
    {
        if (trim($email) !== '') {
            Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression')
                ->add($email, $reason, $detail, $source);
        }

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
