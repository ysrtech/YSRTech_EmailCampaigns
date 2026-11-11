<?php
/**
 * A group of products in an email, filled from the catalog when the email
 * is rendered.
 *
 * In the email's HTML a product block is one empty element carrying its
 * options:
 *
 *   <div data-ec-widget="products" data-layout="grid" data-source="category"
 *        data-category="42" data-limit="6" data-heading="New Releases"></div>
 *
 * and this turns it into the table markup an email client can draw, with
 * that moment's products, prices and images. So a newsletter designed once
 * and sent every week shows that week's new releases and that week's deals,
 * and a price is never one somebody typed.
 *
 * The options are the ones the storefront's Product Showcase Row has - where
 * the products come from, how many, in what order, the heading, the "view
 * all" link, prices, publisher, image width - so a row is set up here the
 * way it is there. There are three layouts, for the three ways a newsletter
 * shows products: a grid of covers, a row of deals with was/now prices, and
 * one featured product with a few words about it.
 */
class YSRTech_EmailCampaigns_Model_Widget_Products
{
    public const LAYOUT_GRID    = 'grid';
    public const LAYOUT_DEALS   = 'deals';
    public const LAYOUT_FEATURE = 'feature';

    public const SOURCE_CATEGORY = 'category';
    public const SOURCE_SKUS     = 'skus';
    public const SOURCE_SALE     = 'sale';
    public const SOURCE_NEW      = 'new';

    /** Never more than this in one block, whatever is asked for */
    private const MAX_PRODUCTS = 24;

    /** @var string[] Names of the products of the first block rendered, for the preview text */
    protected $_firstNames = [];

    /**
     * What each layout starts from. Every key is an option; anything not
     * given in the element falls back to these.
     */
    public static function defaults(string $layout): array
    {
        $common = [
            'layout' => $layout, 'source' => self::SOURCE_CATEGORY, 'category' => '', 'skus' => '',
            'heading' => '', 'intro' => '', 'limit' => 6, 'columns' => 2, 'sort' => '', 'min_products' => 1,
            'show_view_all' => 1, 'view_all_url' => '', 'view_all_label' => '',
            'show_price' => 0, 'show_brand' => 0, 'image_width' => 210,
            'label' => '', 'blurb' => '', 'button' => '',
        ];

        if ($layout === self::LAYOUT_DEALS) {
            return ['source' => self::SOURCE_SALE, 'limit' => 3, 'columns' => 3, 'show_price' => 1, 'image_width' => 150] + $common;
        }

        if ($layout === self::LAYOUT_FEATURE) {
            return ['source' => self::SOURCE_SKUS, 'limit' => 1, 'columns' => 1, 'show_view_all' => 0, 'show_price' => 1, 'image_width' => 160] + $common;
        }

        return $common;
    }

    /**
     * Replace every product block in an email's HTML with its products.
     *
     * @param  int|null $storeId The store view whose catalog, prices and links to use
     */
    public function process(string $html, $storeId = null): string
    {
        $this->_firstNames = [];

        if (strpos($html, 'data-ec-widget') === false) {
            return $html;
        }

        return (string) preg_replace_callback(
            // The element is always written empty or with placeholder text,
            // never with markup inside, so the first closing tag is its own
            '#<div\b([^>]*\bdata-ec-widget\s*=\s*"products"[^>]*)>[^<]*</div>#i',
            function (array $m) use ($storeId) {
                return $this->render($this->_attributes($m[1]), $storeId);
            },
            $html
        );
    }

    /**
     * The first few product names from the first block of the last email
     * processed: "What Leah Found, Cobra, Still Only Mimi".
     */
    public function getFirstNames(int $count = 3): string
    {
        return implode(', ', array_slice($this->_firstNames, 0, $count));
    }

    /**
     * One block, as email HTML. Empty when it has fewer products than its
     * "hide below" setting: a heading over nothing is worse than no section.
     */
    public function render(array $options, $storeId = null): string
    {
        $layout  = in_array($options['layout'] ?? '', [self::LAYOUT_DEALS, self::LAYOUT_FEATURE], true) ? $options['layout'] : self::LAYOUT_GRID;
        $options = array_filter($options, static fn ($v) => $v !== null && $v !== '') + self::defaults($layout);

        try {
            $products = $this->load($options, $storeId);
        } catch (Throwable $e) {
            // A catalog problem must not take the whole email down with it
            Mage::logException($e);
            $products = [];
        }

        if (count($products) < max(0, (int) $options['min_products']) || !$products) {
            return '';
        }

        if (!$this->_firstNames) {
            $this->_firstNames = array_column($products, 'name');
        }

        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand')->get($storeId);

        return $layout === self::LAYOUT_FEATURE
            ? $this->_feature($products[0], $options, $brand)
            : $this->_heading($options, $brand, $storeId) . $this->_rows($products, $options, $brand, $layout);
    }

    // ------------------------------------------------------------ products

    /**
     * The products for a block, as plain rows: name, url, image, prices.
     *
     * Read as the storefront would read them. The catalog's visibility,
     * stock, price index and URL rewrites are all per store view, and asked
     * from the admin they answer for no store at all - so the store view is
     * emulated for the length of the query.
     *
     * @return array<int, array{name:string, url:string, image:string, price:float, final_price:float, on_sale:bool, brand:string}>
     */
    public function load(array $options, $storeId = null): array
    {
        $storeId   = $storeId ? (int) $storeId : (int) Mage::app()->getDefaultStoreView()->getId();
        $emulation = Mage::getSingleton('core/app_emulation');
        $initial   = $emulation->startEnvironmentEmulation($storeId, Mage_Core_Model_App_Area::AREA_FRONTEND);

        try {
            $collection = $this->_collection($options, $storeId);
            $rows       = [];

            if ($collection) {
                $width = max(40, min(600, (int) $options['image_width'])) * 2;

                foreach ($collection as $product) {
                    $price = (float) $product->getPrice();
                    $final = (float) $product->getFinalPrice();

                    $rows[] = [
                        'name'        => (string) $product->getName(),
                        'url'         => (string) $product->getProductUrl(),
                        // Twice the shown width, so it is sharp on a phone
                        'image'       => (string) Mage::helper('catalog/image')->init($product, 'small_image')
                            ->keepFrame(false)->constrainOnly(true)->resize($width),
                        'price'       => $price,
                        'final_price' => $final,
                        'on_sale'     => $final > 0 && $final < $price,
                        'price_html'  => Mage::app()->getStore($storeId)->formatPrice($price, false),
                        'final_html'  => Mage::app()->getStore($storeId)->formatPrice($final, false),
                        'brand'       => $product->getData('publisher') ? (string) $product->getAttributeText('publisher') : '',
                    ];
                }
            }
        } finally {
            $emulation->stopEnvironmentEmulation($initial);
        }

        return $rows;
    }

    protected function _collection(array $options, int $storeId)
    {
        $limit  = max(1, min(self::MAX_PRODUCTS, (int) $options['limit']));
        $source = (string) $options['source'];
        $skus   = [];

        if ($source === self::SOURCE_SALE || $source === self::SOURCE_NEW) {
            $collection = $this->_listing($source, $storeId);
        } elseif ($source === self::SOURCE_SKUS) {
            $skus = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) $options['skus'])), 'strlen')));

            if (!$skus) {
                return null;
            }

            $collection = Mage::getResourceModel('catalog/product_collection')
                ->setStoreId($storeId)->addStoreFilter($storeId)
                ->addAttributeToFilter('sku', ['in' => $skus]);
        } else {
            $category = $this->_category((string) $options['category'], $storeId);

            if (!$category || !$category->getId()) {
                return null;
            }

            $collection = $category->getProductCollection()->setStoreId($storeId);
        }

        if (!$collection) {
            return null;
        }

        $collection->addAttributeToSelect(['name', 'small_image', 'price', 'special_price', 'special_from_date', 'special_to_date', 'url_key'])
            ->addPriceData()->addUrlRewrite();

        try {
            $collection->addAttributeToSelect('publisher');
        } catch (Exception $e) {
            // A store without that attribute simply shows no publisher
        }

        Mage::getSingleton('catalog/product_status')->addVisibleFilterToCollection($collection);
        Mage::getSingleton('catalog/product_visibility')->addVisibleInCatalogFilterToCollection($collection);

        $select = $collection->getSelect();

        switch ((string) $options['sort']) {
            case 'position':
                if ($source === self::SOURCE_CATEGORY) {
                    $collection->addAttributeToSort('position', 'ASC');
                }
                break;
            case 'as_listed':
                if ($skus) {
                    $select->order(new Zend_Db_Expr('FIELD(e.sku, ' . implode(',', array_map([$collection->getConnection(), 'quote'], $skus)) . ')'));
                }
                break;
            case 'newest':
                $collection->addAttributeToSort('created_at', 'DESC');
                break;
            case 'name':
                $collection->addAttributeToSort('name', 'ASC');
                break;
            case 'price_asc':
                $collection->addAttributeToSort('price', 'ASC');
                break;
            case 'price_desc':
                $collection->addAttributeToSort('price', 'DESC');
                break;
            case 'random':
                $select->order(new Zend_Db_Expr('RAND()'));
                break;
            default:
                // A hand-picked list reads in the order it was typed unless
                // told otherwise; anything else has no order of its own to
                // keep, so the newest come first
                if ($skus) {
                    $select->order(new Zend_Db_Expr('FIELD(e.sku, ' . implode(',', array_map([$collection->getConnection(), 'quote'], $skus)) . ')'));
                } elseif ($source !== self::SOURCE_SALE && $source !== self::SOURCE_NEW) {
                    $collection->addAttributeToSort('created_at', 'DESC');
                }
        }

        return $collection->setPageSize($limit)->setCurPage(1);
    }

    /**
     * The category an option names: "category/42" from a chooser, a bare id,
     * or its address on the storefront, "new-release/new-releases".
     *
     * By address so that a design can be shared between installs, or
     * written before anybody has looked the id up: ids differ from one
     * database to the next, and the address is what people know it by.
     */
    protected function _category(string $value, int $storeId)
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~^(?:category/)?(\d+)$~', $value, $m)) {
            $category = Mage::getModel('catalog/category')->setStoreId($storeId)->load((int) $m[1]);

            return $category->getId() ? $category : null;
        }

        $path = preg_replace('~\.html$~', '', trim($value, '/'));

        $category = Mage::getResourceModel('catalog/category_collection')
            ->setStoreId($storeId)
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('url_path', ['in' => [$path, $path . '.html']])
            ->setPageSize(1)
            ->getFirstItem();

        return $category->getId() ? $category : null;
    }

    /**
     * "On sale" and "New arrivals", as the storefront's own /catalogsale and
     * /catalognew pages decide them where those modules are installed - so
     * the email and the page it links to list the same things - and by
     * special price or "set as new" dates where they are not.
     */
    protected function _listing(string $source, int $storeId)
    {
        [$alias, $module] = $source === self::SOURCE_SALE ? ['catalogsale', 'Thirty4_CatalogSale'] : ['catalognew', 'Thirty4_CatalogNew'];

        if (Mage::helper('core')->isModuleEnabled($module)) {
            $layer = Mage::getModel($alias . '/layer');

            if ($layer && method_exists($layer, 'getProductCollection') && ($collection = $layer->getProductCollection())) {
                return $collection;
            }
        }

        $collection = Mage::getResourceModel('catalog/product_collection')->setStoreId($storeId)->addStoreFilter($storeId);
        $today      = Mage::app()->getLocale()->date()->toString('yyyy-MM-dd');

        if ($source === self::SOURCE_SALE) {
            // The price index knows what a customer would pay, special
            // prices and catalog rules both
            $collection->addPriceData();
            $collection->getSelect()->where('price_index.final_price < price_index.price');
            $collection->getSelect()->order(new Zend_Db_Expr('(price_index.price - price_index.final_price) / price_index.price DESC'));
        } else {
            $collection->addAttributeToFilter('news_from_date', ['or' => [['date' => true, 'to' => $today . ' 23:59:59'], ['is' => new Zend_Db_Expr('null')]]], 'left')
                ->addAttributeToFilter('news_to_date', ['or' => [['date' => true, 'from' => $today . ' 00:00:00'], ['is' => new Zend_Db_Expr('null')]]], 'left')
                ->addAttributeToFilter([['attribute' => 'news_from_date', 'is' => new Zend_Db_Expr('not null')], ['attribute' => 'news_to_date', 'is' => new Zend_Db_Expr('not null')]])
                ->addAttributeToSort('news_from_date', 'DESC');
        }

        return $collection;
    }

    // -------------------------------------------------------------- markup

    /**
     * The heading row: title, "view all" link, a short rule under it, and
     * an optional line of introduction.
     */
    protected function _heading(array $o, array $b, $storeId): string
    {
        $e       = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $h       = Mage::helper('ysrtech_emailcampaigns');
        $heading = trim((string) $o['heading']);

        if ($heading === '') {
            $heading = $o['source'] === self::SOURCE_SALE ? $h->__('Sale') : ($o['source'] === self::SOURCE_NEW ? $h->__('New Arrivals') : '');
        }

        $viewAll = '';

        if ((int) $o['show_view_all'] === 1 && ($url = $this->_viewAllUrl($o, $storeId)) !== '') {
            $label   = trim((string) $o['view_all_label']) ?: $h->__('View all') . ' →';
            $viewAll = '<td align="right" valign="bottom" style="font-size:14px;white-space:nowrap;"><a href="' . $e($url)
                . '" target="_blank" style="color:' . $b['link_color'] . ';font-weight:600;text-decoration:none;">' . $e($label) . '</a></td>';
        }

        if ($heading === '' && $viewAll === '' && trim((string) $o['intro']) === '') {
            return '';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="ecw-px" style="padding:36px 40px 8px 40px;font-family:' . $b['font'] . ';">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="ecw-h" style="font-family:' . $b['heading_font'] . ';font-size:26px;line-height:32px;font-weight:700;color:' . $b['heading_color'] . ';">' . $e($heading) . '</td>'
            . $viewAll . '</tr>'
            . ($heading !== '' ? '<tr><td colspan="2" style="padding-top:12px;"><div style="height:3px;width:56px;background-color:' . $b['button_color']
                . ';line-height:3px;font-size:0;">&nbsp;</div></td></tr>' : '')
            . (trim((string) $o['intro']) !== '' ? '<tr><td colspan="2" style="padding-top:14px;font-size:16px;line-height:24px;color:' . $b['text_color'] . ';">'
                . $e($o['intro']) . '</td></tr>' : '')
            . '</table></td></tr></table>';
    }

    /**
     * Products in rows of N. The grid's cells stack on a phone; a deals row
     * stays side by side, three covers across being the point of it.
     *
     * A deals row lines its items up along the top: the covers start level,
     * and a title that runs to two lines pushes only its own price down. The
     * grid lines up along the bottom, so that names sit on one line under
     * covers of different heights.
     */
    protected function _rows(array $products, array $o, array $b, string $layout): string
    {
        $e       = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $columns = max(1, min(4, (int) $o['columns']));
        $width   = max(40, min(600, (int) $o['image_width']));
        $deals   = $layout === self::LAYOUT_DEALS;
        $percent = round(100 / $columns, 2);
        $html    = '';

        foreach (array_chunk($products, $columns) as $row) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td class="ecw-px" style="padding:24px ' . ($deals ? '34px 28px 34px' : '40px 32px 40px') . ';">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';

            foreach ($row as $p) {
                $price = '';

                if ((int) $o['show_price'] === 1) {
                    $price = '<p style="margin:' . ($deals ? '0' : '6px 0 0') . ';font-size:15px;line-height:20px;">'
                        . ($p['on_sale']
                            ? '<span style="color:#9a9286;text-decoration:line-through;">' . $e($p['price_html']) . '</span>&nbsp; <strong style="color:#c2272d;">' . $e($p['final_html']) . '</strong>'
                            : '<span style="color:' . $b['text_color'] . ';">' . $e($p['final_html']) . '</span>')
                        . '</p>';
                }

                $brand = (int) $o['show_brand'] === 1 && $p['brand'] !== ''
                    ? '<p style="margin:10px 0 0;font-size:12px;line-height:16px;letter-spacing:1px;text-transform:uppercase;color:' . $b['muted_color'] . ';">' . $e($p['brand']) . '</p>'
                    : '';

                $html .= '<td class="' . ($deals ? 'ecw-deal' : 'ecw-col') . '" width="' . $percent . '%" valign="' . ($deals ? 'top' : 'bottom') . '" align="center" style="width:' . $percent
                    . '%;vertical-align:' . ($deals ? 'top' : 'bottom') . ';padding:0 ' . ($deals ? '6px' : '8px') . ';font-family:' . $b['font'] . ';">'
                    . '<a href="' . $e($p['url']) . '" target="_blank" style="display:block;"><img class="' . ($deals ? 'ecw-deal-cover' : 'ecw-cover') . '" src="' . $e($p['image'])
                    . '" width="' . $width . '" alt="' . $e($p['name']) . '" style="display:block;border:0;width:' . $width . 'px;max-width:100%;height:auto;margin:0 auto;" /></a>'
                    . $brand
                    . '<p style="margin:' . ($deals ? '12px 0 4px' : '14px 0 0') . ';font-size:' . ($deals ? '15px;line-height:19px' : '16px;line-height:21px') . ';font-weight:700;">'
                    . '<a href="' . $e($p['url']) . '" target="_blank" style="color:' . $b['heading_color'] . ';text-decoration:none;">' . $e($p['name']) . '</a></p>'
                    . $price . '</td>';
            }

            // A short last row keeps its cells the same width as the rest
            for ($i = count($row); $i < $columns; $i++) {
                $html .= '<td class="' . ($deals ? 'ecw-deal' : 'ecw-col') . '" width="' . $percent . '%" style="width:' . $percent . '%;font-size:0;line-height:0;">&nbsp;</td>';
            }

            $html .= '</tr></table></td></tr></table>';
        }

        return $html;
    }

    /**
     * One product on a dark card: cover on the left, a label, its name, a
     * few words, the price when it is really on sale, and a button.
     */
    protected function _feature(array $p, array $o, array $b): string
    {
        $e     = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $h     = Mage::helper('ysrtech_emailcampaigns');
        $width = max(40, min(300, (int) $o['image_width']));
        $label = trim((string) $o['label']) ?: trim((string) $o['heading']);

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="ecw-px" style="padding:12px 40px 40px 40px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#111111;border-radius:10px;"><tr>'
            . '<td class="ecw-feat-img" width="' . ($width + 30) . '" valign="middle" style="width:' . ($width + 30) . 'px;padding:28px 0 28px 28px;">'
            . '<a href="' . $e($p['url']) . '" target="_blank"><img src="' . $e($p['image']) . '" width="' . $width . '" alt="' . $e($p['name'])
            . '" style="display:block;border:0;width:' . $width . 'px;height:auto;border-radius:4px;" /></a></td>'
            . '<td class="ecw-feat-txt" valign="middle" style="padding:28px 28px 28px 24px;font-family:' . $b['font'] . ';">'
            . ($label !== '' ? '<p style="margin:0 0 8px 0;font-size:12px;letter-spacing:3px;text-transform:uppercase;color:' . $b['button_color'] . ';font-weight:700;">' . $e($label) . '</p>' : '')
            . '<p style="margin:0 0 8px 0;font-family:' . $b['heading_font'] . ';font-size:24px;line-height:28px;font-weight:700;color:#ffffff;">' . $e($p['name']) . '</p>'
            . (trim((string) $o['blurb']) !== '' ? '<p style="margin:0 0 18px 0;font-size:15px;line-height:22px;color:#cfc6b8;">' . nl2br($e($o['blurb'])) . '</p>' : '')
            // Was/now only when it is really marked down: a price beside a
            // recommendation otherwise reads as an advert
            . ((int) $o['show_price'] === 1 && $p['on_sale'] ? '<p style="margin:0;padding-bottom:12px;font-size:15px;line-height:20px;"><span style="color:#9a9286;text-decoration:line-through;">'
                . $e($p['price_html']) . '</span>&nbsp; <strong style="color:#e0565b;">' . $e($p['final_html']) . '</strong></p>' : '')
            . '<a href="' . $e($p['url']) . '" target="_blank" style="display:inline-block;padding:11px 24px;border:1px solid ' . $b['button_color']
            . ';border-radius:999px;font-size:14px;font-weight:700;letter-spacing:1px;text-transform:uppercase;text-decoration:none;color:' . $b['button_color'] . ';">'
            . $e(trim((string) $o['button']) ?: $h->__('Read more')) . '</a></td></tr></table></td></tr></table>';
    }

    /**
     * Where "view all" goes: what was typed, or the source's own page.
     */
    protected function _viewAllUrl(array $o, $storeId): string
    {
        $store = $storeId ? Mage::app()->getStore($storeId) : Mage::app()->getDefaultStoreView();
        $base  = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
        $typed = trim((string) $o['view_all_url']);

        if ($typed !== '') {
            return preg_match('#^https?://#i', $typed) ? $typed : $base . ltrim($typed, '/');
        }

        if ($o['source'] === self::SOURCE_SALE) {
            return $base . 'catalogsale';
        }

        if ($o['source'] === self::SOURCE_NEW) {
            return $base . 'catalognew';
        }

        if ($o['source'] === self::SOURCE_CATEGORY) {
            $category = $this->_category((string) $o['category'], (int) $store->getId());

            return $category ? (string) $category->getUrl() : '';
        }

        return '';
    }

    /**
     * The options out of an element's attributes: data-limit="6" => limit.
     */
    protected function _attributes(string $tag): array
    {
        $options = [];

        if (preg_match_all('/\bdata-([a-z_\-]+)\s*=\s*"([^"]*)"/i', $tag, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as [, $name, $value]) {
                $name = str_replace('-', '_', strtolower($name));

                if ($name !== 'ec_widget') {
                    $options[$name] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
                }
            }
        }

        return $options;
    }

    /**
     * The few rules a product block needs on a phone. They go in the email's
     * head; everything else about a block is inline.
     */
    public static function css(): string
    {
        return '@media screen and (max-width:620px){'
            . '.ecw-px{padding-left:20px !important;padding-right:20px !important;}'
            . '.ecw-col{display:block !important;width:100% !important;max-width:100% !important;padding:0 0 28px 0 !important;}'
            . '.ecw-cover{width:240px !important;}'
            . '.ecw-deal{padding:0 4px !important;}'
            . '.ecw-deal p{font-size:13px !important;line-height:17px !important;}'
            . '.ecw-feat-img,.ecw-feat-txt{display:block !important;width:100% !important;padding:0 20px !important;text-align:center !important;}'
            . '.ecw-feat-img{padding-top:24px !important;}.ecw-feat-txt{padding-bottom:28px !important;}'
            . '.ecw-feat-img img{margin:0 auto 20px auto !important;}'
            . '}';
    }
}
