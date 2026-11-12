<?php
/**
 * Where a link in an email leads first: the provider's tracking address,
 * which counts the click and passes the reader on, or the store itself.
 *
 * The values are 0 and 1 because this began as a yes/no setting, and a
 * store that had chosen keeps its choice.
 */
class YSRTech_EmailCampaigns_Model_System_Config_Source_Linkmode
{
    public function toOptionArray(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            ['value' => 0, 'label' => $h->__('Through the provider (it counts the clicks)')],
            ['value' => 1, 'label' => $h->__('Straight to the store over https (clicks counted here)')],
        ];
    }
}
