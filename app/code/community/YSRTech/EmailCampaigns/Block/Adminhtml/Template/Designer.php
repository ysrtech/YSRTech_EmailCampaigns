<?php
/**
 * The email builder, on a template.
 *
 * A page of its own rather than one inside the admin's frame: the builder
 * fills the screen, and brings its own styles, which the admin theme's
 * would otherwise fight. Everything it needs to start is handed to it as
 * one object; after that it talks to the controller.
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

    /** The store view whose brand and catalog apply; a template has none of its own */
    public function getStoreIdForBrand()
    {
        return null;
    }

    public function getSkinFile(string $file): string
    {
        $path = Mage::getBaseDir('skin') . '/adminhtml/default/default/ysrtech/emailcampaigns/designer/' . $file;

        // The file's own time as a version, so a deploy is seen at once
        return $this->getSkinUrl('ysrtech/emailcampaigns/designer/' . $file) . '?v=' . (is_file($path) ? filemtime($path) : 0);
    }

    public function getKind(): string
    {
        return 'template';
    }

    /**
     * @return array<string, string>
     */
    public function getUrls(): array
    {
        $id = ['id' => $this->getEmailTemplate()->getId()];

        return [
            'save'     => $this->getUrl('*/ysrtech_template/saveDesign', $id),
            'back'     => $this->getUrl('*/ysrtech_template/index'),
            'render'   => $this->getUrl('*/ysrtech_template/renderDoc'),
            'widget'   => $this->getUrl('*/ysrtech_template/widget'),
            'assets'   => $this->getUrl('*/ysrtech_template/assets'),
            'sections' => $this->getUrl('*/ysrtech_template/sections'),
            'brand'    => $this->getUrl('*/system_config/edit', ['section' => 'ysrtech_emailcampaigns']),
            'test'     => '',
        ];
    }

    /**
     * Everything the builder starts with.
     */
    public function getConfig(): array
    {
        $template = $this->getEmailTemplate();
        $storeId  = $this->getStoreIdForBrand();
        $h        = Mage::helper('ysrtech_emailcampaigns');

        /** @var YSRTech_EmailCampaigns_Model_Brand $brand */
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');
        $b     = $brand->get($storeId);

        /** @var YSRTech_EmailCampaigns_Model_Design_Doc $docs */
        $docs  = Mage::getModel('ysrtech_emailcampaigns/design_doc');
        $D     = YSRTech_EmailCampaigns_Model_Design_Doc::class;
        $table = static fn (string $rows) => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'
            . $b['font'] . ';color:' . $b['text_color'] . ';">' . $rows . '</table>';

        $defaults = [];

        foreach (['heading', 'text', 'image', 'button', 'divider', 'spacer', 'social', 'coupon', 'quote', 'menu', 'html', 'footer', 'products'] as $type) {
            $defaults[$type] = $D::defaults($type);
        }

        $defaults['footer']['company'] = $b['store_name'];
        $defaults['footer']['address'] = trim((string) preg_replace('/\s*\n\s*/', ', ', $b['address']));
        $defaults['button']['href']    = $b['store_url'];
        $defaults['products']['options'] = array_map('strval', $defaults['products']['options']);
        $defaults['storeheader'] = $defaults['storefooter'] = new stdClass();

        $user = Mage::getSingleton('admin/session')->getUser();

        try {
            $checks = trim((string) $template->getHtml()) !== ''
                ? Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($template, (string) $template->getSubject(), $storeId)
                : [];
        } catch (Throwable $e) {
            $checks = [];
        }

        return [
            'kind'       => $this->getKind(),
            'name'       => (string) $template->getName(),
            'subject'    => (string) $template->getSubject(),
            'preheader'  => (string) $template->getPreheader(),
            'doc'        => $template->getDocForBuilder($storeId),
            'unsaved'    => false,
            'checks'     => $checks,
            'storeId'    => (int) $storeId,
            'formKey'    => Mage::getSingleton('core/session')->getFormKey(),
            'adminEmail' => $user ? (string) $user->getEmail() : '',
            'urls'       => $this->getUrls(),
            'from'       => [
                'name'  => (string) ($h->getConfig('sending/from_name') ?: $b['store_name']),
                'email' => (string) $h->getConfig('sending/from_email'),
            ],
            'brand'      => [
                'enabled'   => $brand->isEnabled($storeId),
                'settings'  => $docs->settings($storeId),
                'header'    => $table($brand->header($storeId)),
                'footer'    => $table($brand->footer($storeId, '#')),
                'fontImport' => (string) $b['font_import'],
                'logo'      => (string) $b['logo_url'],
                'storeName' => (string) $b['store_name'],
                'storeUrl'  => (string) $b['store_url'],
                'address'   => $defaults['footer']['address'],
                'menu'      => implode(', ', array_map(static fn ($link) => $link[0] . ' | ' . $link[1], $b['header_links'])),
            ],
            'widget'     => $this->getWidgetConfig(),
            'mergeTags'  => $this->getMergeTags(),
            'social'     => YSRTech_EmailCampaigns_Model_Design_Html::SOCIAL,
            'defaults'   => $defaults,
            'fonts'      => $D::FONTS,
        ];
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

}
