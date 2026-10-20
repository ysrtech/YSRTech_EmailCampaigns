<?php
/**
 * The page somebody lands on after clicking unsubscribe.
 *
 * A page rather than a redirect to the shop with a flash message: whoever
 * clicked this came from an email and wants one thing confirmed. Dropping them
 * on the homepage with a green banner above the fold asks them to go looking
 * for the answer, and leaves them wondering whether it worked.
 */
class YSRTech_EmailCampaigns_Block_Unsubscribe extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/unsubscribe.phtml');
    }

    /**
     * @return string|null the address that was unsubscribed, or null when the
     *                     link did not resolve to one
     */
    public function getEmail()
    {
        return Mage::registry('ysrtech_emailcampaigns_preferences_email');
    }

    public function wasUnsubscribed(): bool
    {
        return $this->getEmail() !== null;
    }
}
