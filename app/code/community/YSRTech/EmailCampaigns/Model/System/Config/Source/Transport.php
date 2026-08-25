<?php
class YSRTech_EmailCampaigns_Model_System_Config_Source_Transport
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'mailgun', 'label' => Mage::helper('ysrtech_emailcampaigns')->__('Mailgun')],
            ['value' => 'resend',  'label' => Mage::helper('ysrtech_emailcampaigns')->__('Resend')],
        ];
    }
}
