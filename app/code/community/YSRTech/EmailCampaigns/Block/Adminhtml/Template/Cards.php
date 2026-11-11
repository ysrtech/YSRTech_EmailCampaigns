<?php
/**
 * The templates, as cards: a picture of each, its name, and what can be
 * done with it. Opening one opens the builder.
 */
class YSRTech_EmailCampaigns_Block_Adminhtml_Template_Cards extends Mage_Adminhtml_Block_Template
{
    public const PER_PAGE = 24;

    /** @var YSRTech_EmailCampaigns_Model_Resource_Template_Collection|null */
    private $_collection;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ysrtech_emailcampaigns/template_cards.phtml');
    }

    public function getSearch(): string
    {
        return trim((string) $this->getRequest()->getParam('q'));
    }

    public function getCollection()
    {
        if ($this->_collection === null) {
            $this->_collection = Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')
                ->setOrder('updated_at', 'DESC')
                ->setPageSize(self::PER_PAGE)
                ->setCurPage(max(1, (int) $this->getRequest()->getParam('p')));

            if ($this->getSearch() !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->getSearch()) . '%';
                $this->_collection->addFieldToFilter(['name', 'subject'], [['like' => $like], ['like' => $like]]);
            }
        }

        return $this->_collection;
    }

    /**
     * The email as it would be sent to a sample reader, for the card's
     * picture. Empty when it cannot be drawn.
     */
    public function getPreviewHtml($template): string
    {
        try {
            return trim((string) $template->getHtml()) === '' ? '' : Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template);
        } catch (Throwable $e) {
            return '';
        }
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('*/*/index', array_filter(['p' => $page, 'q' => $this->getSearch()]));
    }
}
