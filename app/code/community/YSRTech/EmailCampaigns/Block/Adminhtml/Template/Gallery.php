<?php
/**
 * Where a new template starts: the starters, by what they are for.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Gallery extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/template_gallery.phtml');
    }

    /**
     * What the "Design with AI" form needs: whether it can run, and what
     * was typed last time if that attempt failed.
     *
     * @return array{unavailable:?string, brief:string, link:string, tone:string, home:string, tones:array<string, string>}
     */
    public function getAiState(): array
    {
        $session = Mage::getSingleton('adminhtml/session');
        $last    = (array) $session->getData('ysrtech_ec_ai_brief', true);
        $tones   = YSRTech_EmailCampaigns_Model_Ai_Designer::toneLabels();

        return [
            'unavailable' => Mage::getSingleton('ysrtech_emailcampaigns/ai_client')->unavailable(),
            'brief'       => (string) ($last['brief'] ?? ''),
            'link'        => (string) ($last['link'] ?? ''),
            'tone'        => isset($tones[$last['tone'] ?? '']) ? (string) $last['tone'] : 'friendly',
            'home'        => (string) Mage::app()->getDefaultStoreView()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK),
            'tones'       => $tones,
        ];
    }

    /**
     * @return array<string, array<string, array>> category => code => starter, with its html
     */
    public function getStartersByCategory(): array
    {
        /** @var YSRTech_EmailCampaigns_Model_Template_Starter $starter */
        $starter = Mage::getSingleton('ysrtech_emailcampaigns/template_starter');
        $groups  = [];

        foreach ($starter->getStarters() as $code => $definition) {
            // As it will be sent, to a sample reader
            $preview = $starter->template($code);

            $groups[$definition['category']][$code] = $definition + [
                'html' => Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($preview),
            ];
        }

        return $groups;
    }
}
