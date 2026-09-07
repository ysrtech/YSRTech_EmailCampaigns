<?php
/**
 * The visual editor for a template's content.
 *
 * GrapesJS with its newsletter preset: table-based blocks that survive the
 * email clients, and an inliner that moves css into style attributes on the
 * way out. Both are served from this store rather than a CDN, so the admin
 * works without outbound internet and no third party is told which templates
 * are being edited.
 *
 * The designer is an alternative to the HTML field on the edit form, not a
 * replacement for it. Whatever it saves is the same html column, so a
 * template can be roughed out here and finished by hand, or the other way
 * round.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Designer extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/designer.phtml');
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Template
     */
    public function getEmailTemplate()
    {
        return Mage::registry('ysrtech_emailcampaigns_template');
    }

    /**
     * @return string
     */
    public function getAssetUrl(string $file): string
    {
        return $this->getSkinUrl('ysrtech/emailcampaigns/grapesjs/' . $file);
    }

    /**
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/save', ['id' => $this->getEmailTemplate()->getId(), 'back' => 'designer']);
    }

    /**
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/edit', ['id' => $this->getEmailTemplate()->getId()]);
    }

    /**
     * @return string
     */
    public function getPreviewUrl(): string
    {
        return $this->getUrl('*/*/preview', ['id' => $this->getEmailTemplate()->getId()]);
    }

    /**
     * The content the editor opens with, as a json string safe to embed.
     *
     * @return string
     */
    public function getContentJson(): string
    {
        $html = (string) $this->getEmailTemplate()->getHtml();

        if (trim($html) === '') {
            // An empty canvas invites a bare paragraph, which arrives
            // full-width and unstyled; start from a whole email instead
            $html = Mage::getSingleton('ysrtech_emailcampaigns/template_starter')->getHtml();
        }

        return Mage::helper('core')->jsonEncode($html);
    }

    /**
     * Where the editor lists and uploads images.
     *
     * @return string
     */
    public function getAssetsUrl(): string
    {
        return $this->getUrl('*/*/assets');
    }

    /**
     * @return string
     */
    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
