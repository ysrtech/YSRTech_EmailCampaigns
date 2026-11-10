<?php
/**
 * The fonts worth offering for email: the ones a mail client already has.
 * Anything else is replaced by whatever the client falls back to.
 */
class YSRTech_EmailCampaigns_Model_System_Config_Source_Brand_Font
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Arial,Helvetica,sans-serif', 'label' => 'Arial'],
            ['value' => 'Helvetica,Arial,sans-serif', 'label' => 'Helvetica'],
            ['value' => 'Verdana,Geneva,sans-serif', 'label' => 'Verdana'],
            ['value' => 'Tahoma,Geneva,sans-serif', 'label' => 'Tahoma'],
            ['value' => 'Trebuchet MS,Helvetica,sans-serif', 'label' => 'Trebuchet MS'],
            ['value' => 'Georgia,Times New Roman,serif', 'label' => 'Georgia'],
            ['value' => 'Times New Roman,Times,serif', 'label' => 'Times New Roman'],
        ];
    }
}
