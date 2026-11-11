<?php
/**
 * The email builder, pointed at a campaign's own copy of its email.
 *
 * Same builder, same blocks and sections; only where it saves to and goes
 * back to differ, and it can send a test, which a template cannot.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Designer
    extends YSRTech_EmailCampaigns_Block_Adminhtml_Template_Designer
{
    /**
     * @return YSRTech_EmailCampaigns_Model_Campaign
     */
    public function getCampaign()
    {
        return Mage::registry('ysrtech_emailcampaigns_campaign');
    }

    #[Override]
    public function getStoreIdForBrand()
    {
        return (int) $this->getCampaign()->getStoreId() ?: null;
    }

    #[Override]
    public function getKind(): string
    {
        return 'campaign';
    }

    #[Override]
    public function getUrls(): array
    {
        $id = ['id' => $this->getCampaign()->getId()];

        return [
            'save' => $this->getUrl('*/ysrtech_campaign/saveDesign', $id),
            'back' => $this->getUrl('*/ysrtech_campaign/edit', $id),
            'test' => $this->getUrl('*/ysrtech_campaign/sendTest', $id),
        ] + parent::getUrls();
    }

    #[Override]
    public function getConfig(): array
    {
        $campaign = $this->getCampaign();
        $config   = parent::getConfig();

        $config['name']    = (string) $campaign->getName();
        $config['subject'] = (string) $campaign->getSubject();
        $config['from']    = ['name' => $campaign->getFromNameOrDefault(), 'email' => $campaign->getFromEmail()];

        return $config;
    }
}
