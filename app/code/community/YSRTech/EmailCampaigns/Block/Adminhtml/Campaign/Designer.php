<?php
/**
 * The template designer, pointed at a campaign's own copy of its email.
 *
 * Same editor, same blocks and sections; only where it loads from, saves to
 * and goes back to differ.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Campaign_Designer
    extends YSRTech_EmailCampaigns_Block_Adminhtml_Template_Designer
{
    protected function _campaignId(): int
    {
        return (int) Mage::registry('ysrtech_emailcampaigns_campaign')->getId();
    }

    #[Override]
    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/saveDesign', ['id' => $this->_campaignId()]);
    }

    #[Override]
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/edit', ['id' => $this->_campaignId()]);
    }

    #[Override]
    public function getPreviewUrl(): string
    {
        return $this->getUrl('*/*/preview', ['id' => $this->_campaignId()]);
    }

    #[Override]
    public function getChecksUrl(): string
    {
        return $this->getUrl('*/*/checks', ['id' => $this->_campaignId()]);
    }

    // The template controller owns image uploads and saved sections, and the
    // campaign designer uses the same ones.
    #[Override]
    public function getAssetsUrl(): string
    {
        return $this->getUrl('*/ysrtech_template/assets');
    }

    #[Override]
    public function getSectionsUrl(): string
    {
        return $this->getUrl('*/ysrtech_template/sections');
    }
}
