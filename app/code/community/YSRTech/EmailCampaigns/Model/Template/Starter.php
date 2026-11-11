<?php
/**
 * Starting points for a new template.
 *
 * Written as email has to be: tables for layout, every style inline, a fixed
 * 600px column. They are a shop's emails - a sale, new arrivals, a cart left
 * behind - with this store's name, address and links already in them, so the
 * first thing to do with one is change the words, not the plumbing.
 *
 * Images are the store's own product placeholder until real ones are chosen
 * in the designer. Nothing points at anybody else's server: an email's
 * images are fetched by the recipient, weeks later, and a stock-photo host
 * that has moved on leaves a hole in every copy already sent.
 */
class YSRTech_EmailCampaigns_Model_Template_Starter
{
    /** Width of the content column: the de facto standard for email */
    private const CONTENT_WIDTH = 600;

    /**
     * The colours and font the pieces below are drawn in: the store view's
     * brand, which has a default behind every value.
     *
     * @var array<string, mixed>
     */
    private $_c = [];

    /** @var bool Whether the store's own header and footer will be put around what is built */
    private $_branded = false;

    private function _palette($storeId): void
    {
        /** @var YSRTech_EmailCampaigns_Model_Brand $brand */
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');

        $this->_c       = $brand->get($storeId);
        $this->_branded = $brand->isEnabled($storeId);
    }

    /**
     * The plain starter, which is what an empty template opens as.
     */
    public function getHtml($storeId = null): string
    {
        return $this->build('plain', $storeId);
    }

    /**
     * @return array<string, array{name:string, category:string, description:string, subject:string, preheader:string}>
     */
    public function getStarters(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        $campaigns = $h->__('Campaigns');
        $lifecycle = $h->__('Automations');

        return $this->_fromConfig() + [
            'plain' => [
                'name' => $h->__('Plain newsletter'), 'category' => $campaigns,
                'description' => $h->__('A heading, room to write, and the footer. For a letter rather than a poster.'),
                'subject' => $h->__('News from {{store.name}}'),
                'preheader' => '',
            ],
            'new_arrivals' => [
                'name' => $h->__('New arrivals'), 'category' => $campaigns,
                'description' => $h->__('A lead image and four products in a grid, each with its own link.'),
                'subject' => $h->__('Just in at {{store.name}}'),
                'preheader' => $h->__('Four new things we think you will like.'),
            ],
            'sale' => [
                'name' => $h->__('Sale announcement'), 'category' => $campaigns,
                'description' => $h->__('One offer, one coupon code, one button. Nothing to distract from it.'),
                'subject' => $h->__('20% off everything this week'),
                'preheader' => $h->__('Use the code inside before Sunday night.'),
            ],
            'holiday' => [
                'name' => $h->__('Holiday gift guide'), 'category' => $campaigns,
                'description' => $h->__('A greeting, three gift ideas by price, and the last day to order in time.'),
                'subject' => $h->__('Gifts that arrive in time'),
                'preheader' => $h->__('Ideas at three prices, and the last day to order.'),
            ],
            'welcome' => [
                'name' => $h->__('Welcome'), 'category' => $lifecycle,
                'description' => $h->__('For a new subscriber: what they will get from you, and somewhere to start.'),
                'subject' => $h->__('Welcome to {{store.name}}'),
                'preheader' => $h->__('Thank you for joining us. Here is where to start.'),
            ],
            'abandoned_cart' => [
                'name' => $h->__('Cart reminder'), 'category' => $lifecycle,
                'description' => $h->__('For the abandoned cart trigger: what they left, and a button straight back to it.'),
                'subject' => $h->__('You left something in your cart'),
                'preheader' => $h->__('It is still there. Pick up where you left off.'),
            ],
            'order_followup' => [
                'name' => $h->__('Order follow-up'), 'category' => $lifecycle,
                'description' => $h->__('For the order triggers, a few days on: did it arrive, and would they say so in a review.'),
                'subject' => $h->__('How is your order?'),
                'preheader' => $h->__('We would love to hear what you think.'),
            ],
            'winback' => [
                'name' => $h->__('We miss you'), 'category' => $lifecycle,
                'description' => $h->__('For customers who have not ordered in a while: what is new, and a reason to come back.'),
                'subject' => $h->__('It has been a while'),
                'preheader' => $h->__('A little something to welcome you back.'),
            ],
        ];
    }

    /**
     * Starters another module brings: a store's own newsletter, built once
     * and offered beside the ready-made ones.
     *
     * Declared in that module's config.xml:
     *
     *   <global><ysrtech_emailcampaigns><starters>
     *     <my_weekly>
     *       <name>Weekly newsletter</name>
     *       <category>Our newsletters</category>
     *       <description>...</description>
     *       <subject>...</subject>
     *       <preheader>...</preheader>
     *       <module>Vendor_Module</module>
     *       <file>data/newsletter/weekly.html</file>
     *       <frame>2</frame>   <!-- Renderer::FRAME_*: 2 is "its own complete design" -->
     *     </my_weekly>
     *   </starters></ysrtech_emailcampaigns></global>
     *
     * The file is the email's body. In it {{store_url}}, {{skin_url}} and
     * {{media_url}} are replaced with this store's addresses when a template
     * is made from it; everything else, product blocks and placeholders
     * included, is left for send time.
     *
     * @return array<string, array>
     */
    protected function _fromConfig(): array
    {
        $node     = Mage::getConfig()->getNode('global/ysrtech_emailcampaigns/starters');
        $starters = [];

        if (!$node) {
            return $starters;
        }

        foreach ($node->children() as $code => $definition) {
            $module = (string) $definition->module;
            $file   = (string) $definition->file;

            // Inside the declaring module's own directory and nowhere else
            if ($module === '' || $file === '' || strpos($file, '..') !== false) {
                continue;
            }

            $path = Mage::getModuleDir('', $module) . DS . str_replace('/', DS, ltrim($file, '/'));

            if (!is_file($path)) {
                continue;
            }

            $starters[(string) $code] = [
                'name'        => (string) $definition->name,
                'category'    => (string) $definition->category ?: Mage::helper('ysrtech_emailcampaigns')->__('This store'),
                'description' => (string) $definition->description,
                'subject'     => (string) $definition->subject,
                'preheader'   => (string) $definition->preheader,
                'file'        => $path,
                'frame'       => isset($definition->frame) ? (int) $definition->frame : YSRTech_EmailCampaigns_Model_Renderer::FRAME_NONE,
            ];
        }

        return $starters;
    }

    /**
     * What should go around a template made from this starter: one of
     * Renderer::FRAME_*.
     */
    public function getFrame(string $code): int
    {
        $starters = $this->_fromConfig();

        return isset($starters[$code]) ? (int) $starters[$code]['frame'] : YSRTech_EmailCampaigns_Model_Renderer::FRAME_BRAND;
    }

    /**
     * The HTML of one starter, for this store.
     */
    public function build(string $code, $storeId = null): string
    {
        $this->_palette($storeId);

        $files = $this->_fromConfig();

        if (isset($files[$code])) {
            $store = $storeId ? Mage::app()->getStore($storeId) : Mage::app()->getDefaultStoreView();

            return strtr((string) file_get_contents($files[$code]['file']), [
                '{{store_url}}' => $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK),
                '{{skin_url}}'  => $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN),
                '{{media_url}}' => $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA),
            ]);
        }

        $h     = Mage::helper('ysrtech_emailcampaigns');
        $e     = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $shop  = $this->_storeUrl($storeId);
        $hi    = $e($h->__('Hi %s,', '{{ customer.firstname|there }}'));

        switch ($code) {
            case 'new_arrivals':
                $rows = $this->_hero($e($h->__('Just in')), $e($h->__('New this week, and not for long at these numbers.')))
                    . $this->_image($shop)
                    . $this->_products([
                        [$h->__('Product name'), '$00.00'], [$h->__('Product name'), '$00.00'],
                        [$h->__('Product name'), '$00.00'], [$h->__('Product name'), '$00.00'],
                    ], $shop, 2)
                    . $this->_button($e($h->__('See everything new')), $shop);
                break;

            case 'sale':
                $rows = $this->_hero($e($h->__('20% off everything')), $e($h->__('This week only. Ends Sunday at midnight.')))
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0;">'
                        . $e($h->__('Everything in the store is 20% off until Sunday night. Use this code at checkout:')) . '</p>')
                    . $this->_coupon('SAVE20')
                    . $this->_button($e($h->__('Shop the sale')), $shop)
                    . $this->_text('<p style="margin:0;font-size:12px;color:' . $this->_c['muted_color'] . ';">'
                        . $e($h->__('One use per customer. Not valid with other offers.')) . '</p>', 'center');
                break;

            case 'holiday':
                $rows = $this->_hero($e($h->__('Gifts that arrive in time')), $e($h->__('Order by the 10th for delivery before the holiday.')))
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0;">'
                        . $e($h->__('Wishing you and your family a happy holiday. If you are still looking for a gift, here are three we would be glad to receive ourselves.')) . '</p>')
                    . $this->_products([
                        [$h->__('Under $25'), $h->__('Gift idea')], [$h->__('Under $50'), $h->__('Gift idea')],
                        [$h->__('Under $100'), $h->__('Gift idea')],
                    ], $shop, 3)
                    . $this->_button($e($h->__('See the gift guide')), $shop);
                break;

            case 'welcome':
                $rows = $this->_hero($e($h->__('Welcome to %s', '{{ store.name }}')), $e($h->__('Thank you for joining us.')))
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0 0 16px;">'
                        . $e($h->__('You will hear from us when something new comes in, when there is a sale worth knowing about, and not much otherwise.'))
                        . '</p><p style="margin:0;">' . $e($h->__('In the meantime, these are what people come to us for:')) . '</p>')
                    . $this->_products([[$h->__('Best seller'), '$00.00'], [$h->__('Best seller'), '$00.00']], $shop, 2)
                    . $this->_button($e($h->__('Start browsing')), $shop);
                break;

            case 'abandoned_cart':
                $rows = $this->_hero($e($h->__('Still thinking it over?')), '')
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0 0 16px;">'
                        . $e($h->__('You left these in your cart:')) . '</p>'
                        . '<p style="margin:0 0 16px;padding:14px 16px;background-color:#f7f7f7;border-left:3px solid ' . $this->_c['button_color'] . ';">'
                        . '{{ cart.item_names }}<br /><strong>{{ cart.grand_total }}</strong></p><p style="margin:0;">'
                        . $e($h->__('They are still there, and the cart is saved for you.')) . '</p>')
                    . $this->_button($e($h->__('Return to your cart')), '{{ cart.url }}')
                    . $this->_text('<p style="margin:0;font-size:12px;color:' . $this->_c['muted_color'] . ';">'
                        . $e($h->__('Already ordered? Then ignore this, and thank you.')) . '</p>', 'center');
                break;

            case 'order_followup':
                $rows = $this->_hero($e($h->__('How is your order?')), '')
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0 0 16px;">'
                        . $e($h->__('Your order %s should be with you by now. We hope it is exactly what you wanted.', '#{{ order.increment_id }}'))
                        . '</p><p style="margin:0;">'
                        . $e($h->__('If anything is not right, reply to this email and a person will sort it out. And if it is, a short review helps the next customer more than anything we could write.')) . '</p>')
                    . $this->_button($e($h->__('View your order')), '{{ order.url }}');
                break;

            case 'winback':
                $rows = $this->_hero($e($h->__('It has been a while')), $e($h->__('Here is 15% off to welcome you back.')))
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0;">'
                        . $e($h->__('A lot has come in since your last order. Have a look around, and take 15% off whatever you find with this code:')) . '</p>')
                    . $this->_coupon('WELCOMEBACK')
                    . $this->_button($e($h->__('See what is new')), $shop);
                break;

            default:
                $rows = $this->_hero($e($h->__('Your header')), '')
                    . $this->_text('<p style="margin:0 0 16px;">' . $hi . '</p><p style="margin:0 0 16px;">'
                        . $e($h->__('Write your newsletter here.')) . '</p>');
        }

        // With the store's header and footer on, they are put around this
        // when it is sent; a footer here as well would be a second one.
        return $this->_frame($rows . ($this->_branded ? '' : $this->_footer($storeId)));
    }

    /**
     * An email from a list of sections, each a type and its words: what
     * "Design with AI" produces. The words arrive as plain text and are
     * escaped here; the only markup is this class's own.
     *
     * @param array<int, array<string, mixed>> $sections See Ai_Designer::parse()
     * @param string                           $link     Where every button goes
     */
    public function buildFromSpec(array $sections, string $link, $storeId = null): string
    {
        $this->_palette($storeId);

        $e    = static fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        // {{ customer.firstname|there }} has to survive escaping as it is
        $t    = static fn (string $v) => preg_replace_callback('/\{\{.*?\}\}/s', static fn ($m) => html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'), $e($v));
        $link = $e($link);
        $rows = '';

        foreach ($sections as $s) {
            switch ($s['type'] ?? '') {
                case 'hero':
                    $rows .= $this->_hero($t((string) $s['heading']), $t((string) ($s['text'] ?? '')));
                    break;

                case 'text':
                    $paragraphs = preg_split('/\n\s*\n/', trim((string) $s['text'])) ?: [];
                    $html       = ($s['heading'] ?? '') !== ''
                        ? '<h2 style="margin:0 0 12px;font-size:19px;line-height:1.3;font-weight:normal;color:' . $this->_c['heading_color'] . ';">' . $t((string) $s['heading']) . '</h2>'
                        : '';

                    foreach ($paragraphs as $i => $paragraph) {
                        $html .= '<p style="margin:0' . ($i < count($paragraphs) - 1 ? ' 0 16px' : '') . ';">' . nl2br($t(trim($paragraph))) . '</p>';
                    }

                    $rows .= $this->_text($html);
                    break;

                case 'button':
                    $rows .= $this->_button($t((string) $s['label']), $link);
                    break;

                case 'coupon':
                    $rows .= $this->_coupon((string) $s['code']);

                    if (($s['note'] ?? '') !== '') {
                        $rows .= $this->_text('<p style="margin:0;font-size:12px;color:' . $this->_c['muted_color'] . ';">' . $t((string) $s['note']) . '</p>', 'center');
                    }
                    break;

                case 'products':
                    // A product block: the store's real products, chosen when
                    // the email is sent, and editable in the designer
                    $count = (int) ($s['count'] ?? 4);
                    $rows .= '<tr><td style="padding:8px 0;"><div data-ec-widget="products" data-layout="grid" data-source="'
                        . (($s['show'] ?? '') === 'sale' ? 'sale' : 'new') . '" data-heading="' . $e((string) ($s['heading'] ?? ''))
                        . '" data-limit="' . $count . '" data-columns="' . ($count === 3 || $count === 6 ? 3 : 2)
                        . '" data-show-price="1" data-show-view-all="0"></div></td></tr>';
                    break;

                case 'divider':
                    $rows .= '<tr><td style="padding:12px 32px;"><div style="border-top:1px solid #e5e5e5;font-size:0;line-height:0;">&nbsp;</div></td></tr>';
                    break;
            }
        }

        return $this->_frame($rows . ($this->_branded ? '' : $this->_footer($storeId)));
    }

    /**
     * The pieces the starters are made of, on their own, for the designer's
     * Sections panel. Each is a table that can be dropped between others.
     *
     * @return array<int, array{id:string, label:string, html:string}>
     */
    public function getSections($storeId = null): array
    {
        $this->_palette($storeId);

        $h    = Mage::helper('ysrtech_emailcampaigns');
        $e    = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $shop = $this->_storeUrl($storeId);
        $wrap = fn (string $rows) => '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:' . $this->_c['font'] . ';color:'
            . $this->_c['text_color'] . ';">' . $rows . '</table>';

        return [
            ['id' => 'header', 'label' => $h->__('Heading'),
                'html' => $wrap($this->_hero($e($h->__('Your heading')), $e($h->__('A line underneath it.'))))],
            ['id' => 'greeting', 'label' => $h->__('Greeting and text'),
                'html' => $wrap($this->_text('<p style="margin:0 0 16px;">' . $e($h->__('Hi %s,', '{{ customer.firstname|there }}'))
                    . '</p><p style="margin:0;">' . $e($h->__('Write here.')) . '</p>'))],
            ['id' => 'button', 'label' => $h->__('Button'),
                'html' => $wrap($this->_button($e($h->__('Shop now')), $shop))],
            ['id' => 'coupon', 'label' => $h->__('Coupon code'),
                'html' => $wrap($this->_coupon('CODE10'))],
            ['id' => 'products2', 'label' => $h->__('Two products'),
                'html' => $wrap($this->_products([[$h->__('Product name'), '$00.00'], [$h->__('Product name'), '$00.00']], $shop, 2))],
            ['id' => 'products3', 'label' => $h->__('Three products'),
                'html' => $wrap($this->_products([[$h->__('Product name'), '$00.00'], [$h->__('Product name'), '$00.00'], [$h->__('Product name'), '$00.00']], $shop, 3))],
            ['id' => 'footer', 'label' => $h->__('Footer: address and unsubscribe'),
                'html' => $wrap($this->_footer($storeId))],
        ];
    }

    private function _frame(string $rows): string
    {
        $width = self::CONTENT_WIDTH;
        $font  = $this->_c['font'];

        // Inside the store's own frame: just the content, edge to edge
        if ($this->_branded) {
            return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:' . $font . ';color:'
                . $this->_c['text_color'] . ';">' . $rows . '</table>';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $this->_c['page_bg']
            . ';margin:0;padding:24px 0;"><tr><td align="center">'
            . '<table width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:' . $width
            . 'px;max-width:100%;background-color:' . $this->_c['content_bg'] . ';border-radius:4px;font-family:' . $font . ';color:'
            . $this->_c['text_color'] . ';">' . $rows . '</table></td></tr></table>';
    }

    private function _hero(string $title, string $sub): string
    {
        return '<tr><td align="center" style="padding:28px 32px 20px;border-bottom:1px solid #eeeeee;">'
            . '<h1 style="margin:0;font-size:26px;line-height:1.25;font-weight:normal;color:' . $this->_c['heading_color'] . ';">' . $title . '</h1>'
            . ($sub !== '' ? '<p style="margin:8px 0 0;font-size:15px;line-height:1.5;color:' . $this->_c['muted_color'] . ';">' . $sub . '</p>' : '')
            . '</td></tr>';
    }

    private function _text(string $html, string $align = 'left'): string
    {
        return '<tr><td align="' . $align . '" style="padding:24px 32px 8px;font-size:15px;line-height:1.6;">' . $html . '</td></tr>';
    }

    private function _image(string $link): string
    {
        return '<tr><td style="padding:0;"><a href="' . $link . '"><img src="' . $this->_placeholder()
            . '" width="' . self::CONTENT_WIDTH . '" alt="' . htmlspecialchars(Mage::helper('ysrtech_emailcampaigns')->__('Describe this picture'), ENT_QUOTES)
            . '" style="display:block;width:100%;max-width:' . self::CONTENT_WIDTH . 'px;height:auto;border:0;" /></a></td></tr>';
    }

    private function _button(string $label, string $url): string
    {
        // A table cell with a background, not a styled link alone: Outlook
        // ignores padding on an anchor and renders the button as bare text.
        return '<tr><td align="center" style="padding:20px 32px 28px;">'
            . '<table cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="background-color:' . $this->_c['button_color']
            . ';border-radius:4px;"><a href="' . $url . '" style="display:inline-block;padding:13px 30px;font-size:15px;font-weight:bold;color:' . $this->_c['button_text_color'] . ';text-decoration:none;">'
            . $label . '</a></td></tr></table></td></tr>';
    }

    private function _coupon(string $code): string
    {
        return '<tr><td align="center" style="padding:16px 32px 4px;">'
            . '<table cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:12px 28px;border:2px dashed '
            . $this->_c['button_color'] . ';font-family:Courier New,Courier,monospace;font-size:22px;letter-spacing:3px;color:' . $this->_c['heading_color'] . ';">'
            . htmlspecialchars($code, ENT_QUOTES) . '</td></tr></table></td></tr>';
    }

    /**
     * @param array<int, array{0:string, 1:string}> $products [name, price or caption]
     */
    private function _products(array $products, string $link, int $perRow): string
    {
        $cell  = (int) floor((self::CONTENT_WIDTH - 64 - ($perRow - 1) * 16) / $perRow);
        $alt   = htmlspecialchars(Mage::helper('ysrtech_emailcampaigns')->__('Describe this product'), ENT_QUOTES);
        $html  = '';

        foreach (array_chunk($products, $perRow) as $row) {
            $html .= '<tr><td style="padding:16px 32px 0;"><table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';

            foreach ($row as $i => [$name, $caption]) {
                $html .= '<td width="' . $cell . '" valign="top" align="center" style="padding:0 ' . ($i < count($row) - 1 ? '16px' : '0') . ' 0 0;font-size:14px;line-height:1.5;">'
                    . '<a href="' . $link . '"><img src="' . $this->_placeholder() . '" width="' . $cell . '" alt="' . $alt
                    . '" style="display:block;width:100%;max-width:' . $cell . 'px;height:auto;border:0;" /></a>'
                    . '<p style="margin:10px 0 2px;color:' . $this->_c['heading_color'] . ';">' . htmlspecialchars((string) $name, ENT_QUOTES) . '</p>'
                    . '<p style="margin:0;color:' . $this->_c['muted_color'] . ';">' . htmlspecialchars((string) $caption, ENT_QUOTES) . '</p></td>';
            }

            $html .= '</tr></table></td></tr>';
        }

        return $html;
    }

    private function _footer($storeId): string
    {
        $e     = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $store = $e(Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($storeId));
        $addr  = nl2br($e(trim((string) Mage::getStoreConfig('general/store_information/address', $storeId))));

        return '<tr><td align="center" style="padding:20px 32px 28px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.6;color:'
            . $this->_c['muted_color'] . ';"><p style="margin:0 0 6px;">' . $store . '</p><p style="margin:0 0 10px;">' . $addr
            . '</p>{{unsubscribe}}</td></tr>';
    }

    private function _storeUrl($storeId): string
    {
        $store = $storeId ? Mage::app()->getStore($storeId) : Mage::app()->getDefaultStoreView();

        return htmlspecialchars((string) $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK), ENT_QUOTES);
    }

    /**
     * The store's own product placeholder, by absolute URL.
     */
    private function _placeholder(): string
    {
        $store = Mage::app()->getDefaultStoreView();

        return htmlspecialchars(
            $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN) . 'frontend/base/default/images/catalog/product/placeholder/image.jpg',
            ENT_QUOTES
        );
    }
}
