<?php
/**
 * The design document the builder edits: settings for the whole email and a
 * list of rows, each a column layout whose cells hold blocks.
 *
 *   {settings: {width, bodyBg, contentBg, font, text, heading, link, btn, radius},
 *    rows: [{id, layout: [1, 1], cells: [[block, ...], [...]], bg, pad: [top, bottom], stack, hide}]}
 *   block: {id, type, props}
 *
 * This class makes documents (an empty one, the starters, one from an AI
 * plan, one around an email from before the builder) and cleans one that
 * has come back from the browser, so that what is stored and rendered has
 * exactly the shape Design_Html expects.
 */
class YSRTech_EmailCampaigns_Model_Design_Doc
{
    public const LAYOUTS = [[1], [1, 1], [1, 2], [2, 1], [1, 1, 1], [1, 1, 1, 1]];
    public const FONTS   = [
        'Helvetica, Arial, sans-serif', 'Arial, Helvetica, sans-serif', 'Georgia, Times, serif', 'Verdana, Geneva, sans-serif',
        'Trebuchet MS, sans-serif', "'Courier New', monospace",
    ];

    private $_n = 0;

    // ------------------------------------------------------------- making

    /**
     * The whole-email settings, from the store view's brand.
     */
    public function settings($storeId = null): array
    {
        $b = Mage::getSingleton('ysrtech_emailcampaigns/brand')->get($storeId);

        return [
            'width'     => 600,
            'bodyBg'    => $b['page_bg'],
            'contentBg' => $b['content_bg'],
            'font'      => $b['font'],
            'text'      => $b['text_color'],
            'heading'   => $b['heading_color'],
            'link'      => $b['link_color'],
            'btn'       => $b['button_color'],
            'btnText'   => $b['button_text_color'],
            'radius'    => 6,
        ];
    }

    public function block(string $type, array $props = []): array
    {
        return ['id' => 'b' . dechex(++$this->_n) . substr(md5(uniqid('', true)), 0, 5), 'type' => $type, 'props' => $props + self::defaults($type)];
    }

    public function row(array $layout, array $cells = [], array $props = []): array
    {
        return $props + [
            'id'     => 'r' . dechex(++$this->_n) . substr(md5(uniqid('', true)), 0, 5),
            'layout' => $layout,
            'cells'  => $cells ?: array_fill(0, count($layout), []),
            'bg'     => '',
            'pad'    => [8, 8],
            'stack'  => true,
            'hide'   => '',
        ];
    }

    /**
     * What a new block of each type starts as.
     */
    public static function defaults(string $type): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        switch ($type) {
            case 'heading':  return ['html' => $h->__('A clear, short headline'), 'size' => 28, 'align' => 'left', 'weight' => 700, 'color' => '', 'pad' => [8, 28, 8, 28]];
            case 'text':     return ['html' => $h->__('Write something worth reading. Keep paragraphs short; most people read email on a phone.'), 'size' => 16, 'lh' => 1.6, 'align' => 'left', 'color' => '', 'pad' => [8, 28, 8, 28]];
            case 'image':    return ['src' => '', 'alt' => '', 'href' => '', 'width' => 100, 'align' => 'center', 'radius' => 0, 'pad' => [8, 28, 8, 28]];
            case 'button':   return ['label' => $h->__('Shop now'), 'href' => '', 'bg' => '', 'color' => '', 'radius' => null, 'full' => false, 'align' => 'center', 'size' => 16, 'pad' => [12, 28, 12, 28]];
            case 'divider':  return ['color' => '#E4E4E7', 'thick' => 1, 'width' => 100, 'pad' => [12, 28, 12, 28]];
            case 'spacer':   return ['h' => 32];
            case 'social':   return ['nets' => ['facebook', 'instagram'], 'urls' => [], 'align' => 'center', 'size' => 32, 'pad' => [12, 28, 12, 28]];
            case 'coupon':   return ['code' => 'WELCOME10', 'note' => $h->__('One use per customer.'), 'pad' => [12, 28, 12, 28]];
            case 'quote':    return ['html' => $h->__('A few words from a happy customer.'), 'who' => $h->__('Name, city'), 'pad' => [16, 28, 16, 28]];
            case 'menu':     return ['items' => '', 'align' => 'center', 'pad' => [8, 28, 8, 28]];
            case 'html':     return ['code' => '<p style="margin:0">Custom HTML goes here.</p>', 'pad' => [8, 28, 8, 28]];
            case 'footer':   return ['company' => '', 'address' => '', 'note' => '', 'unsubscribe' => $h->__('Unsubscribe'), 'pad' => [16, 28, 24, 28]];
            case 'products': return ['options' => array_diff_key(YSRTech_EmailCampaigns_Model_Widget_Products::defaults('grid'), ['layout' => 1]) + ['layout' => 'grid']];
        }

        return [];
    }

    /**
     * The top of a new email: the store's own header when it has one set
     * up, a logo-or-name row otherwise.
     */
    public function headerRow($storeId = null): array
    {
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');
        $b     = $brand->get($storeId);

        if ($brand->isEnabled($storeId)) {
            return $this->row([1], [[$this->block('storeheader')]], ['pad' => [0, 0]]);
        }

        $first = $b['logo_url'] !== ''
            ? $this->block('image', ['src' => $b['logo_url'], 'alt' => $b['store_name'], 'href' => $b['store_url'], 'width' => 40, 'pad' => [16, 28, 8, 28]])
            : $this->block('heading', ['html' => htmlspecialchars($b['store_name'], ENT_QUOTES, 'UTF-8'), 'size' => 24, 'align' => 'center', 'weight' => 400, 'pad' => [20, 28, 12, 28]]);

        return $this->row([1], [[$first]]);
    }

    public function footerRow($storeId = null): array
    {
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');
        $b     = $brand->get($storeId);

        if ($brand->isEnabled($storeId)) {
            return $this->row([1], [[$this->block('storefooter')]], ['pad' => [0, 0]]);
        }

        return $this->row([1], [[$this->block('footer', [
            'company' => $b['store_name'],
            'address' => trim((string) preg_replace('/\s*\n\s*/', ', ', $b['address'])),
        ])]]);
    }

    public function blank($storeId = null): array
    {
        return [
            'settings' => $this->settings($storeId),
            'rows'     => [$this->headerRow($storeId), $this->row([1], [[$this->block('heading'), $this->block('text')]], ['pad' => [16, 16]]), $this->footerRow($storeId)],
        ];
    }

    /**
     * An email from a list of plain sections: what the starters are written
     * as, and what "Design with AI" returns. The words arrive as text and
     * are escaped here.
     *
     * @param array<int, array<string, mixed>> $sections hero / text / button / coupon / products / divider
     * @param string                           $link     Where every button goes
     */
    public function fromSections(array $sections, string $link, $storeId = null): array
    {
        $e    = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = [$this->headerRow($storeId)];

        foreach ($sections as $s) {
            switch ($s['type'] ?? '') {
                case 'hero':
                    $blocks = [$this->block('heading', ['html' => $e($s['heading']), 'size' => 30, 'align' => 'center', 'weight' => 400, 'pad' => [8, 28, 4, 28]])];

                    if (($s['text'] ?? '') !== '') {
                        $blocks[] = $this->block('text', ['html' => $e($s['text']), 'size' => 15, 'align' => 'center', 'pad' => [0, 28, 8, 28]]);
                    }

                    $rows[] = $this->row([1], [$blocks], ['pad' => [20, 12]]);
                    break;

                case 'text':
                    $blocks = [];

                    if (($s['heading'] ?? '') !== '') {
                        $blocks[] = $this->block('heading', ['html' => $e($s['heading']), 'size' => 20, 'weight' => 400]);
                    }

                    $paragraphs = preg_split('/\n\s*\n/', trim((string) $s['text'])) ?: [];
                    $blocks[]   = $this->block('text', ['html' => implode('<br><br>', array_map(static fn ($p) => nl2br($e(trim($p)), false), $paragraphs))]);
                    $rows[]     = $this->row([1], [$blocks]);
                    break;

                case 'button':
                    $rows[] = $this->row([1], [[$this->block('button', ['label' => (string) $s['label'], 'href' => (string) ($s['url'] ?? $link)])]]);
                    break;

                case 'coupon':
                    $rows[] = $this->row([1], [[$this->block('coupon', ['code' => (string) $s['code'], 'note' => (string) ($s['note'] ?? '')])]]);
                    break;

                case 'products':
                    $count  = (int) ($s['count'] ?? 4);
                    $rows[] = $this->row([1], [[$this->block('products', ['options' => [
                        'source'        => ($s['show'] ?? '') === 'sale' ? 'sale' : 'new',
                        'heading'       => (string) ($s['heading'] ?? ''),
                        'limit'         => $count,
                        'columns'       => $count === 3 || $count === 6 ? 3 : 2,
                        'show_price'    => 1,
                        'show_view_all' => 0,
                    ] + self::defaults('products')['options']])]], ['pad' => [0, 0]]);
                    break;

                case 'divider':
                    $rows[] = $this->row([1], [[$this->block('divider')]], ['pad' => [0, 0]]);
                    break;
            }
        }

        $rows[] = $this->footerRow($storeId);

        return ['settings' => $this->settings($storeId), 'rows' => $rows];
    }

    /**
     * The built-in starters, as sections.
     *
     * @return array<int, array<string, mixed>>|null Null for a code that is not one of them
     */
    public function starterSections(string $code): ?array
    {
        $h  = Mage::helper('ysrtech_emailcampaigns');
        $hi = $h->__('Hi %s,', '{{ customer.firstname|there }}');

        switch ($code) {
            case 'plain':
                return [
                    ['type' => 'hero', 'heading' => $h->__('Your headline')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('Write your newsletter here.')],
                ];

            case 'new_arrivals':
                return [
                    ['type' => 'hero', 'heading' => $h->__('Just in'), 'text' => $h->__('New this week, and not for long at these numbers.')],
                    ['type' => 'products', 'show' => 'new', 'count' => 4],
                    ['type' => 'button', 'label' => $h->__('See everything new')],
                ];

            case 'sale':
                return [
                    ['type' => 'hero', 'heading' => $h->__('20% off everything'), 'text' => $h->__('This week only. Ends Sunday at midnight.')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('Everything in the store is 20% off until Sunday night. Use this code at checkout:')],
                    ['type' => 'coupon', 'code' => 'SAVE20', 'note' => $h->__('One use per customer. Not valid with other offers.')],
                    ['type' => 'button', 'label' => $h->__('Shop the sale')],
                ];

            case 'holiday':
                return [
                    ['type' => 'hero', 'heading' => $h->__('Gifts that arrive in time'), 'text' => $h->__('Order by the 10th for delivery before the holiday.')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('Wishing you and your family a happy holiday. If you are still looking for a gift, here are a few we would be glad to receive ourselves.')],
                    ['type' => 'products', 'show' => 'new', 'count' => 3],
                    ['type' => 'button', 'label' => $h->__('See the gift guide')],
                ];

            case 'welcome':
                return [
                    ['type' => 'hero', 'heading' => $h->__('Welcome to %s', '{{ store.name }}'), 'text' => $h->__('Thank you for joining us.')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('You will hear from us when something new comes in, when there is a sale worth knowing about, and not much otherwise.') . "\n\n" . $h->__('In the meantime, these are what people come to us for:')],
                    ['type' => 'products', 'show' => 'new', 'count' => 2],
                    ['type' => 'button', 'label' => $h->__('Start browsing')],
                ];

            case 'abandoned_cart':
                return [
                    ['type' => 'hero', 'heading' => $h->__('Still thinking it over?')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('You left these in your cart:') . "\n\n{{ cart.item_names }}\n{{ cart.grand_total }}\n\n" . $h->__('They are still there, and the cart is saved for you.')],
                    ['type' => 'button', 'label' => $h->__('Return to your cart'), 'url' => '{{ cart.url }}'],
                ];

            case 'order_followup':
                return [
                    ['type' => 'hero', 'heading' => $h->__('How is your order?')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('Your order %s should be with you by now. We hope it is exactly what you wanted.', '#{{ order.increment_id }}') . "\n\n" . $h->__('If anything is not right, reply to this email and a person will sort it out. And if it is, a short review helps the next customer more than anything we could write.')],
                    ['type' => 'button', 'label' => $h->__('View your order'), 'url' => '{{ order.url }}'],
                ];

            case 'winback':
                return [
                    ['type' => 'hero', 'heading' => $h->__('It has been a while'), 'text' => $h->__('Here is 15% off to welcome you back.')],
                    ['type' => 'text', 'text' => $hi . "\n\n" . $h->__('A lot has come in since your last order. Have a look around, and take 15% off whatever you find with this code:')],
                    ['type' => 'coupon', 'code' => 'WELCOMEBACK', 'note' => ''],
                    ['type' => 'button', 'label' => $h->__('See what is new')],
                ];
        }

        return null;
    }

    /**
     * A document around an email that was made before the builder, so it
     * can be opened in it. Its HTML becomes one Custom HTML block.
     *
     * An email that was a complete design of its own, or one with nothing
     * around it, becomes a whole HTML document, which the builder sends
     * exactly as written. One that wore the store's header and footer keeps
     * them, as the blocks that stand for them.
     */
    public function fromLegacy(YSRTech_EmailCampaigns_Model_Template $template, $storeId = null): array
    {
        $R     = YSRTech_EmailCampaigns_Model_Renderer::class;
        $frame = (int) $template->getUseBrand();
        $html  = (string) $template->getHtml();

        if ($frame === $R::FRAME_BRAND) {
            // Only what was inside its body: it now sits inside another email
            $html = str_replace('{{unsubscribe}}', '', Mage::getSingleton('ysrtech_emailcampaigns/renderer')->bodyOf($html));

            return [
                'settings' => $this->settings($storeId),
                'rows'     => [
                    $this->row([1], [[$this->block('storeheader')]], ['pad' => [0, 0]]),
                    $this->row([1], [[$this->block('html', ['code' => $html, 'pad' => [0, 0, 0, 0]])]], ['pad' => [0, 0]]),
                    $this->row([1], [[$this->block('storefooter')]], ['pad' => [0, 0]]),
                ],
            ];
        }

        $document = Mage::getSingleton('ysrtech_emailcampaigns/renderer')->setStoreId($storeId)->legacyDocument($template);

        return [
            'settings' => $this->settings($storeId),
            'rows'     => [$this->row([1], [[$this->block('html', ['code' => $document, 'pad' => [0, 0, 0, 0]])]], ['pad' => [0, 0]])],
        ];
    }

    // ----------------------------------------------------------- cleaning

    /**
     * A document from the browser, made safe to store and render: known
     * block types only, every number a number within its range, every colour
     * a colour, every list the right length. Anything unrecognisable is
     * dropped rather than guessed at.
     *
     * @throws Mage_Core_Exception When it is not a document at all
     */
    public function clean($doc, $storeId = null): array
    {
        if (!is_array($doc) || !is_array($doc['rows'] ?? null)) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('That is not an email design.'));
        }

        $base     = $this->settings($storeId);
        $in       = is_array($doc['settings'] ?? null) ? $doc['settings'] : [];
        $settings = [
            'width'  => self::_int($in['width'] ?? 600, 480, 700),
            'font'   => self::_css($in['font'] ?? '') ?: $base['font'],
            'radius' => self::_int($in['radius'] ?? 6, 0, 30),
        ];

        foreach (['bodyBg', 'contentBg', 'text', 'heading', 'link', 'btn', 'btnText'] as $key) {
            $settings[$key] = self::_colour($in[$key] ?? '') ?: $base[$key];
        }

        $rows = [];

        foreach (array_slice($doc['rows'], 0, 80) as $r) {
            if (!is_array($r)) {
                continue;
            }

            $layout = array_values(array_filter(array_map('intval', (array) ($r['layout'] ?? [1])), static fn ($w) => $w >= 1 && $w <= 4));
            $layout = in_array($layout, self::LAYOUTS, true) ? $layout : [1];
            $cells  = [];

            foreach ($layout as $i => $w) {
                $cells[$i] = [];

                foreach (array_slice((array) ($r['cells'][$i] ?? []), 0, 40) as $b) {
                    $block = is_array($b) ? $this->_cleanBlock($b) : null;

                    if ($block !== null) {
                        $cells[$i][] = $block;
                    }
                }
            }

            $pad    = (array) ($r['pad'] ?? []);
            $rows[] = [
                'id'     => self::_id($r['id'] ?? '', 'r'),
                'layout' => $layout,
                'cells'  => $cells,
                'bg'     => self::_colour($r['bg'] ?? ''),
                'pad'    => [self::_int($pad[0] ?? 0, 0, 120), self::_int($pad[1] ?? 0, 0, 120)],
                'stack'  => !isset($r['stack']) || (bool) $r['stack'],
                'hide'   => in_array($r['hide'] ?? '', ['mobile', 'desktop'], true) ? $r['hide'] : '',
            ];
        }

        return ['settings' => $settings, 'rows' => $rows];
    }

    private function _cleanBlock(array $b): ?array
    {
        $type = (string) ($b['type'] ?? '');
        $d    = self::defaults($type);
        $p    = is_array($b['props'] ?? null) ? $b['props'] : [];

        if (!$d && !in_array($type, ['storeheader', 'storefooter'], true)) {
            return null;
        }

        $align = static fn ($v, $default) => in_array($v, ['left', 'center', 'right'], true) ? $v : $default;
        $text  = static fn ($v, int $max = 2000) => is_scalar($v) ? mb_substr((string) $v, 0, $max) : '';
        // Words typed on the stage: formatting is kept, anything that runs is not
        $rich  = static fn ($v) => is_scalar($v) ? mb_substr((string) preg_replace(['#<(script|style|iframe|object|embed|form)\b.*?</\1>#is', '#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*>#i', '/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*/i'], ['', '', '', '$1=$2#'], (string) $v), 0, 20000) : '';
        $props = [];

        switch ($type) {
            case 'heading':
                $props = ['html' => $rich($p['html'] ?? ''), 'size' => self::_int($p['size'] ?? $d['size'], 12, 60), 'align' => $align($p['align'] ?? '', 'left'),
                    'weight' => in_array((int) ($p['weight'] ?? 0), [400, 500, 600, 700, 800], true) ? (int) $p['weight'] : (int) $d['weight'], 'color' => self::_colour($p['color'] ?? '')];
                break;

            case 'text':
                $props = ['html' => $rich($p['html'] ?? ''), 'size' => self::_int($p['size'] ?? $d['size'], 10, 30), 'lh' => max(1, min(2.4, round((float) ($p['lh'] ?? 1.6), 2))),
                    'align' => $align($p['align'] ?? '', 'left'), 'color' => self::_colour($p['color'] ?? '')];
                break;

            case 'image':
                $props = ['src' => self::_url($p['src'] ?? ''), 'alt' => $text($p['alt'] ?? '', 300), 'href' => self::_url($p['href'] ?? ''),
                    'width' => self::_int($p['width'] ?? 100, 5, 100), 'align' => $align($p['align'] ?? '', 'center'), 'radius' => self::_int($p['radius'] ?? 0, 0, 60)];
                break;

            case 'button':
                $props = ['label' => $text($p['label'] ?? '', 120), 'href' => self::_url($p['href'] ?? ''), 'bg' => self::_colour($p['bg'] ?? ''),
                    'color' => self::_colour($p['color'] ?? ''), 'radius' => isset($p['radius']) && $p['radius'] !== '' ? self::_int($p['radius'], 0, 40) : null,
                    'full' => !empty($p['full']), 'align' => $align($p['align'] ?? '', 'center'), 'size' => self::_int($p['size'] ?? 16, 11, 26)];
                break;

            case 'divider':
                $props = ['color' => self::_colour($p['color'] ?? '') ?: '#E4E4E7', 'thick' => self::_int($p['thick'] ?? 1, 1, 10), 'width' => self::_int($p['width'] ?? 100, 5, 100)];
                break;

            case 'spacer':
                $props = ['h' => self::_int($p['h'] ?? 32, 4, 160)];
                break;

            case 'social':
                $nets = array_values(array_intersect(array_keys(YSRTech_EmailCampaigns_Model_Design_Html::SOCIAL), array_map('strval', (array) ($p['nets'] ?? []))));
                $urls = [];

                foreach ($nets as $net) {
                    $urls[$net] = self::_url($p['urls'][$net] ?? '');
                }

                $props = ['nets' => $nets, 'urls' => $urls, 'align' => $align($p['align'] ?? '', 'center'), 'size' => self::_int($p['size'] ?? 32, 16, 56)];
                break;

            case 'coupon':
                $props = ['code' => $text($p['code'] ?? '', 60), 'note' => $text($p['note'] ?? '', 300)];
                break;

            case 'quote':
                $props = ['html' => $rich($p['html'] ?? ''), 'who' => $text($p['who'] ?? '', 200)];
                break;

            case 'menu':
                $props = ['items' => $text($p['items'] ?? '', 1500), 'align' => $align($p['align'] ?? '', 'center')];
                break;

            case 'html':
                // The writer's own HTML, kept as written: this is the place for it
                $props = ['code' => is_scalar($p['code'] ?? null) ? mb_substr((string) $p['code'], 0, 400000) : ''];
                break;

            case 'footer':
                $props = ['company' => $text($p['company'] ?? '', 200), 'address' => $text($p['address'] ?? '', 400), 'note' => $text($p['note'] ?? '', 400),
                    'unsubscribe' => $text($p['unsubscribe'] ?? '', 60) ?: $d['unsubscribe']];
                break;

            case 'products':
                $options = [];

                foreach ($d['options'] as $name => $default) {
                    $value          = is_array($p['options'] ?? null) && is_scalar($p['options'][$name] ?? null) ? (string) $p['options'][$name] : (string) $default;
                    $options[$name] = mb_substr($value, 0, 500);
                }

                $props = ['options' => $options];
                break;
        }

        if (isset($d['pad'])) {
            $pad          = (array) ($p['pad'] ?? $d['pad']);
            $props['pad'] = [self::_int($pad[0] ?? 0, 0, 120), self::_int($pad[1] ?? 0, 0, 120), self::_int($pad[2] ?? 0, 0, 120), self::_int($pad[3] ?? 0, 0, 120)];
        }

        return ['id' => self::_id($b['id'] ?? '', 'b'), 'type' => $type, 'props' => $props];
    }

    private static function _int($value, int $min, int $max): int
    {
        return max($min, min($max, (int) round((float) $value)));
    }

    /** A hex colour, or '' */
    private static function _colour($value): string
    {
        return is_string($value) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? $value : '';
    }

    /** A font stack: letters, digits, spaces, commas, quotes and hyphens */
    private static function _css($value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9 ,\'"-]{2,160}$/', $value) ? str_replace('"', "'", $value) : '';
    }

    /**
     * An address a link or an image may have: http(s), mailto, tel, a path
     * on this site, a #fragment, or one of this module's own placeholders.
     */
    private static function _url($value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' && preg_match('#^(https?://|mailto:|tel:|/|\#|\{\{|%)#i', $value) ? mb_substr($value, 0, 2000) : '';
    }

    private static function _id($value, string $prefix): string
    {
        return is_string($value) && preg_match('/^[a-z0-9]{2,24}$/i', $value) ? $value : $prefix . substr(md5(uniqid('', true)), 0, 8);
    }
}
