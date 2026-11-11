<?php
class YSRTech_EmailCampaigns_Model_System_Config_Source_Aiprovider
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openrouter', 'label' => 'OpenRouter'],
            ['value' => 'openai', 'label' => 'OpenAI'],
        ];
    }
}
