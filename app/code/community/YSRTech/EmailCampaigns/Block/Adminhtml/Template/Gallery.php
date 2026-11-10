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
     * @return array<string, array<string, array>> category => code => starter, with its html
     */
    public function getStartersByCategory(): array
    {
        /** @var YSRTech_EmailCampaigns_Model_Template_Starter $starter */
        $starter = Mage::getSingleton('ysrtech_emailcampaigns/template_starter');
        $groups  = [];

        foreach ($starter->getStarters() as $code => $definition) {
            // As it will be sent: inside the store's header and footer when
            // those are on, in its colours either way
            $preview = Mage::getModel('ysrtech_emailcampaigns/template')->setData([
                'html'      => $starter->build($code),
                'subject'   => $definition['subject'],
                'use_brand' => $starter->getFrame($code),
            ]);

            $groups[$definition['category']][$code] = $definition + [
                'html' => Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($preview),
            ];
        }

        return $groups;
    }
}
