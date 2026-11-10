<?php
/**
 * The same fonts as for text, with "the same as the text" first.
 */
class YSRTech_EmailCampaigns_Model_System_Config_Source_Brand_Headingfont
    extends YSRTech_EmailCampaigns_Model_System_Config_Source_Brand_Font
{
    public function toOptionArray(): array
    {
        return array_merge(
            [['value' => '', 'label' => Mage::helper('ysrtech_emailcampaigns')->__('Same as the text font')]],
            parent::toOptionArray()
        );
    }
}
