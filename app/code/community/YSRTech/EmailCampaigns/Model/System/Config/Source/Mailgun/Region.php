<?php
class YSRTech_EmailCampaigns_Model_System_Config_Source_Mailgun_Region
{
    public function toOptionArray(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            ['value' => 'us', 'label' => $h->__('US (api.mailgun.net)')],
            ['value' => 'eu', 'label' => $h->__('EU (api.eu.mailgun.net)')],
        ];
    }
}
