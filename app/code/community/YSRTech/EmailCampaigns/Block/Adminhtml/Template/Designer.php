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
    /**
     * Styles for the designer's canvas only, never saved or sent.
     *
     * An email that wears the store's header and footer is designed as the
     * part in between, with no frame of its own. Left like that the canvas
     * shows it stretched across the whole screen, which is nothing like the
     * 600px column it is sent in - so the canvas is given that column, on
     * the brand's page colour.
     */
    public function getCanvasCss(): string
    {
        /** @var YSRTech_EmailCampaigns_Model_Brand $brand */
        $brand   = Mage::getSingleton('ysrtech_emailcampaigns/brand');
        $storeId = $this->getStoreIdForBrand();

        if ((int) $this->getEmailTemplate()->getUseBrand() !== 1 || !$brand->isEnabled($storeId)) {
            return '';
        }

        $b = $brand->get($storeId);

        return 'html{background-color:' . $b['page_bg'] . ' !important;}'
            . 'body{max-width:' . YSRTech_EmailCampaigns_Model_Brand::WIDTH . 'px;margin:24px auto !important;background-color:'
            . $b['content_bg'] . ' !important;min-height:300px;}';
    }

    /**
     * Whether the email being designed is wrapped in the store's header and
     * footer, for the note under the canvas.
     */
    public function isBranded(): bool
    {
        return $this->getCanvasCss() !== '';
    }

    /** The store view whose brand applies; a template has none of its own */
    public function getStoreIdForBrand()
    {
        return null;
    }

    /**
     * What the designer needs to offer product blocks: the three layouts
     * with their starting options, the choices for each option, and where
     * to ask for a preview.
     */
    public function getWidgetConfig(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');
        $W = YSRTech_EmailCampaigns_Model_Widget_Products::class;

        $categories = [['id' => '', 'name' => $h->__('Choose a category...')]];
        $store      = $this->getStoreIdForBrand() ? Mage::app()->getStore($this->getStoreIdForBrand()) : Mage::app()->getDefaultStoreView();
        $root       = Mage::getModel('catalog/category')->load($store->getRootCategoryId());

        if ($root->getId()) {
            $collection = Mage::getResourceModel('catalog/category_collection')
                ->addAttributeToSelect('name')
                ->addAttributeToFilter('is_active', 1)
                ->addFieldToFilter('path', ['like' => $root->getPath() . '/%']);

            $byPath = [];

            foreach ($collection as $category) {
                $byPath[$category->getPath()] = $category;
            }

            // In tree order, each indented under its parent
            uksort($byPath, static function ($a, $b) use ($byPath) {
                return strcmp(self::_treeKey($a, $byPath), self::_treeKey($b, $byPath));
            });

            foreach ($byPath as $category) {
                $categories[] = [
                    'id'   => (string) $category->getId(),
                    'name' => str_repeat('-- ', max(0, $category->getLevel() - $root->getLevel() - 1)) . $category->getName(),
                ];
            }
        }

        return [
            'previewUrl' => $this->getUrl('*/ysrtech_template/widget'),
            'storeId'    => (int) $this->getStoreIdForBrand(),
            'categories' => $categories,
            'layouts'    => [
                ['id' => $W::LAYOUT_GRID, 'label' => $h->__('Product grid'), 'hint' => $h->__('Covers in rows, each with its name. For new releases.'), 'defaults' => $W::defaults($W::LAYOUT_GRID)],
                ['id' => $W::LAYOUT_DEALS, 'label' => $h->__('Deals row'), 'hint' => $h->__('Three across with the old price crossed out. For what is on sale.'), 'defaults' => $W::defaults($W::LAYOUT_DEALS)],
                ['id' => $W::LAYOUT_FEATURE, 'label' => $h->__('Featured product'), 'hint' => $h->__('One product on a dark card with a few words about it. For a staff pick.'), 'defaults' => $W::defaults($W::LAYOUT_FEATURE)],
            ],
            'sources' => [
                ['id' => $W::SOURCE_CATEGORY, 'name' => $h->__('Category')],
                ['id' => $W::SOURCE_SKUS, 'name' => $h->__('Chosen products (by SKU)')],
                ['id' => $W::SOURCE_SALE, 'name' => $h->__('On sale')],
                ['id' => $W::SOURCE_NEW, 'name' => $h->__('New arrivals')],
            ],
            'sorts' => [
                ['id' => '', 'name' => $h->__("The source's own order")],
                ['id' => 'position', 'name' => $h->__('Category position')],
                ['id' => 'as_listed', 'name' => $h->__('The order the SKUs are listed in')],
                ['id' => 'newest', 'name' => $h->__('Newest first')],
                ['id' => 'name', 'name' => $h->__('Name, A to Z')],
                ['id' => 'price_asc', 'name' => $h->__('Price, low to high')],
                ['id' => 'price_desc', 'name' => $h->__('Price, high to low')],
                ['id' => 'random', 'name' => $h->__('Random, reshuffled each send')],
            ],
            't' => [
                'category'       => $h->__('Products'),
                'empty'          => $h->__('No products to show for these settings. If that is still so when the email is sent, this section is left out.'),
                'name'           => $h->__('Product block'),
                'yes'            => $h->__('Yes'),
                'no'             => $h->__('No'),
                'source'         => $h->__('Products come from'),
                'categoryField'  => $h->__('Category'),
                'skus'           => $h->__('SKUs (comma separated)'),
                'heading'        => $h->__('Heading'),
                'intro'          => $h->__('Line under the heading'),
                'limit'          => $h->__('How many products'),
                'columns'        => $h->__('Per row'),
                'sort'           => $h->__('Order'),
                'min_products'   => $h->__('Hide the section below this many'),
                'show_view_all'  => $h->__('Show the "view all" link'),
                'view_all_url'   => $h->__('"View all" goes to'),
                'view_all_label' => $h->__('"View all" wording'),
                'show_price'     => $h->__('Show prices'),
                'show_brand'     => $h->__('Show the publisher'),
                'image_width'    => $h->__('Image width (pixels)'),
                'label'          => $h->__('Label above the name'),
                'blurb'          => $h->__('A few words about it'),
                'button'         => $h->__('Button wording'),
            ],
        ];
    }

    /**
     * A sort key that puts a category after its parent and among its
     * siblings by position: each id in the path, as that category's
     * position and then its id.
     */
    private static function _treeKey(string $path, array $byPath): string
    {
        $key   = '';
        $built = '';

        foreach (explode('/', $path) as $id) {
            $built = $built === '' ? $id : $built . '/' . $id;
            $key  .= sprintf('%06d.%06d/', isset($byPath[$built]) ? (int) $byPath[$built]->getPosition() : 0, (int) $id);
        }

        return $key;
    }

    public function getSectionsUrl(): string
    {
        return $this->getUrl('*/*/sections');
    }

    public function getChecksUrl(): string
    {
        return $this->getUrl('*/*/checks', ['id' => $this->getEmailTemplate()->getId()]);
    }

    /**
     * The placeholders the designer offers, with whether a fallback makes
     * sense for each.
     *
     * @return array<int, array{token:string, label:string, fallback:bool}>
     */
    public function getMergeTags(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            ['token' => 'customer.firstname', 'label' => $h->__('First name'), 'fallback' => true],
            ['token' => 'customer.lastname', 'label' => $h->__('Last name'), 'fallback' => true],
            ['token' => 'customer.email', 'label' => $h->__('Email address'), 'fallback' => false],
            ['token' => 'store.name', 'label' => $h->__('Store name'), 'fallback' => false],
            ['token' => 'unsubscribe_url', 'label' => $h->__('Unsubscribe link address'), 'fallback' => false],
            ['token' => 'order.increment_id', 'label' => $h->__('Order number (automations)'), 'fallback' => false],
            ['token' => 'order.grand_total', 'label' => $h->__('Order total (automations)'), 'fallback' => false],
            ['token' => 'order.url', 'label' => $h->__('Order link address (automations)'), 'fallback' => false],
            ['token' => 'cart.item_names', 'label' => $h->__('Cart items (cart reminder)'), 'fallback' => false],
            ['token' => 'cart.grand_total', 'label' => $h->__('Cart total (cart reminder)'), 'fallback' => false],
            ['token' => 'cart.url', 'label' => $h->__('Cart link address (cart reminder)'), 'fallback' => false],
        ];
    }

    /**
     * Ready-made sections for the block panel, in this store's name.
     *
     * @return array<int, array{id:string, label:string, html:string}>
     */
    public function getSectionBlocks(): array
    {
        return Mage::getSingleton('ysrtech_emailcampaigns/template_starter')->getSections();
    }

    public function getFormKey(): string
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }
}
