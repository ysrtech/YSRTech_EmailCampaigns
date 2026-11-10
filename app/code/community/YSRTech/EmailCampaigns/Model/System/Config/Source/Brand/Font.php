<?php
/**
 * The fonts worth offering for email: the ones a mail client already has,
 * and two web fonts with a safe fallback behind each.
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
            // Web fonts: shown where the mail client loads them (Apple Mail,
            // some Android), the fallback after the comma everywhere else
            ['value' => "'Source Sans 3','Source Sans Pro',Arial,sans-serif", 'label' => 'Source Sans 3 (web font; Arial elsewhere)'],
            ['value' => "'Frank Ruhl Libre',Georgia,serif", 'label' => 'Frank Ruhl Libre (web font; Georgia elsewhere)'],
        ];
    }

    /**
     * The stylesheet a web font is loaded from, for the fonts above that
     * are not already on the reader's machine.
     *
     * @return array<string, string> first family name => Google Fonts query
     */
    public static function webFonts(): array
    {
        return [
            'Source Sans 3'    => 'Source+Sans+3:wght@400;600;700',
            'Frank Ruhl Libre' => 'Frank+Ruhl+Libre:wght@500;700',
        ];
    }
}
