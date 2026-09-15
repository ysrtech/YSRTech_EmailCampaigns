<?php
class YSRTech_EmailCampaigns_Block_Unsubscribe extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/unsubscribe.phtml');
    }

    public function getEmail(): ?string
    {
        return Mage::registry('ysrtech_emailcampaigns_preferences_email');
    }

    public function wasUnsubscribed(): bool
    {
        return $this->getEmail() !== null;
    }
}
