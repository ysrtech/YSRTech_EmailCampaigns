<?php
/**
 * A design document, as the builder keeps it, turned into the email that is
 * sent: table-based HTML with every style inline, columns that stack on a
 * phone, and a button Outlook draws properly.
 *
 * The builder's stage draws the same blocks in the browser
 * (skin/.../designer/render.js) so that editing looks like the result; this
 * is the one that counts. The two are kept block for block alike: change
 * them together.
 *
 * What comes out still has its placeholders in it. {{ customer.firstname }},
 * the product blocks and the store's header and footer are filled in by
 * Renderer when the email is rendered for somebody.
 */
class YSRTech_EmailCampaigns_Model_Design_Html
{
    public const STORE_HEADER = '<!--ec:store-header-->';
    public const STORE_FOOTER = '<!--ec:store-footer-->';

    public const SOCIAL = [
        'facebook'  => ['Facebook', '#1877F2', 'f'],
        'instagram' => ['Instagram', '#E1306C', 'IG'],
        'x'         => ['X', '#18181B', 'X'],
        'youtube'   => ['YouTube', '#FF0000', '&#9654;'],
        'linkedin'  => ['LinkedIn', '#0A66C2', 'in'],
        'tiktok'    => ['TikTok', '#000000', '&#9834;'],
        'whatsapp'  => ['WhatsApp', '#25D366', 'W'],
    ];

    /**
     * @param array $doc A document that has been through Design_Doc::clean()
     */
    public function render(array $doc, string $subject = ''): string
    {
        $raw = self::fullDocument($doc);

        if ($raw !== null) {
            return $raw;
        }

        $s = $doc['settings'];
        $W = (int) $s['width'];

        $rows = [];

        foreach ($doc['rows'] as $r) {
            $total = array_sum($r['layout']) ?: 1;
            $cols  = '';

            foreach ($r['layout'] as $i => $w) {
                $blocks = '';

                foreach ($r['cells'][$i] ?? [] as $b) {
                    $blocks .= $this->block($b, $s);
                }

                $cols .= '<td class="' . ($r['stack'] ? 'col' : '') . '" width="' . (int) round($W * $w / $total) . '" valign="top" style="width:'
                    . number_format($w / $total * 100, 2, '.', '') . '%;vertical-align:top">' . $blocks . '</td>';
            }

            $rows[] = '<tr class="' . ($r['hide'] ? 'hide-' . $r['hide'] : '') . '"><td style="padding:' . (int) $r['pad'][0] . 'px 0 ' . (int) $r['pad'][1]
                . 'px;background:' . ($r['bg'] ?: $s['contentBg']) . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . $cols . '</tr></table></td></tr>';
        }

        return '<!doctype html><html lang="en" xmlns:v="urn:schemas-microsoft-com:vml"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting">'
            . '<title>' . self::e($subject) . '</title>' . "\n"
            . '<style>body{margin:0;padding:0}img{-ms-interpolation-mode:bicubic}a{color:' . $s['link'] . '}'
            . '@media (max-width:' . ($W + 20) . 'px){.wrap{width:100%!important}.col{display:block!important;width:100%!important}.hide-mobile{display:none!important}}'
            . '@media (min-width:' . ($W + 21) . 'px){.hide-desktop{display:none!important}}</style></head>' . "\n"
            . '<body style="margin:0;background:' . $s['bodyBg'] . '">' . "\n"
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $s['bodyBg'] . '"><tr><td align="center" style="padding:24px 8px">' . "\n"
            . '<!--[if mso]><table role="presentation" width="' . $W . '"><tr><td><![endif]-->' . "\n"
            . '<table role="presentation" class="wrap" width="' . $W . '" cellpadding="0" cellspacing="0" border="0" style="width:' . $W
            . 'px;max-width:100%;background:' . $s['contentBg'] . '">' . "\n"
            . implode("\n", $rows) . "\n"
            . '</table>' . "\n"
            . '<!--[if mso]></td></tr></table><![endif]-->' . "\n"
            . '</td></tr></table></body></html>';
    }

    /**
     * A design that is one block holding a whole HTML document - an email
     * brought in from elsewhere, or one made before the builder - is sent
     * exactly as written.
     */
    public static function fullDocument(array $doc): ?string
    {
        $rows  = $doc['rows'] ?? [];
        $block = count($rows) === 1 && count($rows[0]['cells'] ?? []) === 1 && count($rows[0]['cells'][0]) === 1 ? $rows[0]['cells'][0][0] : null;
        $code  = $block && ($block['type'] ?? '') === 'html' ? (string) ($block['props']['code'] ?? '') : '';

        return preg_match('/^\s*(<!doctype|<html)/i', $code) ? $code : null;
    }

    public function block(array $b, array $s): string
    {
        $p    = $b['props'];
        $pad  = $p['pad'] ?? [0, 0, 0, 0];
        $wrap = static fn (string $inner, string $extra = '') => '<div style="padding:' . (int) $pad[0] . 'px ' . (int) $pad[1] . 'px ' . (int) $pad[2] . 'px '
            . (int) $pad[3] . 'px;' . $extra . '">' . $inner . '</div>';
        $ff   = 'font-family:' . $s['font'];
        $e    = [self::class, 'e'];

        switch ($b['type']) {
            case 'heading':
                return $wrap('<h2 style="margin:0;' . $ff . ';font-size:' . (int) $p['size'] . 'px;line-height:1.25;font-weight:' . (int) $p['weight']
                    . ';color:' . ($p['color'] ?: $s['heading']) . ';text-align:' . $p['align'] . '">' . $p['html'] . '</h2>');

            case 'text':
                return $wrap('<div style="margin:0;' . $ff . ';font-size:' . (int) $p['size'] . 'px;line-height:' . $p['lh'] . ';color:'
                    . ($p['color'] ?: $s['text']) . ';text-align:' . $p['align'] . '">' . $p['html'] . '</div>');

            case 'image':
                $img = '<img src="' . $e($p['src']) . '" alt="' . $e($p['alt']) . '" width="' . (int) round(($s['width'] - $pad[1] - $pad[3]) * $p['width'] / 100)
                    . '" style="display:inline-block;width:' . (int) $p['width'] . '%;max-width:100%;height:auto;border:0;border-radius:' . (int) $p['radius'] . 'px">';

                return $wrap($p['href'] !== '' ? '<a href="' . $e($p['href']) . '">' . $img . '</a>' : $img, 'text-align:' . $p['align'] . ';line-height:0');

            case 'button':
                $bg = $p['bg'] ?: $s['btn'];
                $r  = $p['radius'] === null ? (int) $s['radius'] : (int) $p['radius'];
                $fg = $p['color'] ?: ($s['btnText'] ?? '#FFFFFF');
                $a  = '<a href="' . $e($p['href']) . '" style="display:' . ($p['full'] ? 'block' : 'inline-block') . ';background:' . $bg . ';color:' . $fg . ';' . $ff
                    . ';font-size:' . (int) $p['size'] . 'px;font-weight:700;text-decoration:none;padding:13px 26px;border-radius:' . $r . 'px;text-align:center">' . $e($p['label']) . '</a>';

                return $wrap('<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="' . $e($p['href'])
                    . '" style="height:46px;v-text-anchor:middle;width:220px" arcsize="' . (int) round($r / 46 * 100) . '%" fillcolor="' . $bg
                    . '" stroke="f"><center style="color:' . $fg . ';font-family:Arial;font-size:' . (int) $p['size'] . 'px;font-weight:bold">' . $e($p['label'])
                    . '</center></v:roundrect><![endif]--><!--[if !mso]><!-->' . $a . '<!--<![endif]-->', 'text-align:' . $p['align']);

            case 'divider':
                return $wrap('<div style="border-top:' . (int) $p['thick'] . 'px solid ' . $p['color'] . ';width:' . (int) $p['width']
                    . '%;margin:0 auto;font-size:0;line-height:0">&nbsp;</div>');

            case 'spacer':
                return '<div style="height:' . (int) $p['h'] . 'px;line-height:' . (int) $p['h'] . 'px;font-size:0">&nbsp;</div>';

            case 'social':
                $icons = '';

                foreach ($p['nets'] as $net) {
                    if (isset(self::SOCIAL[$net])) {
                        $icons .= '<a href="' . $e($p['urls'][$net] ?? '#') . '" style="text-decoration:none">' . self::socialIcon($net, (int) $p['size']) . '</a>';
                    }
                }

                return $wrap($icons, 'text-align:' . $p['align']);

            case 'coupon':
                return $wrap('<div style="border:2px dashed ' . $s['btn'] . ';border-radius:14px;padding:18px;text-align:center;' . $ff
                    . '"><div style="font:700 26px/1.2 \'Courier New\',monospace;letter-spacing:.12em;color:' . $s['heading'] . '">' . $e($p['code'])
                    . '</div><div style="font-size:13px;color:' . $s['text'] . ';margin-top:6px">' . $e($p['note']) . '</div></div>');

            case 'quote':
                return $wrap('<div style="' . $ff . ';border-left:3px solid ' . $s['btn'] . ';padding-left:18px"><div style="font-size:18px;line-height:1.5;color:'
                    . $s['heading'] . ';font-style:italic">' . $p['html'] . '</div><div style="font-size:13px;color:' . $s['text'] . ';margin-top:12px">' . $e($p['who']) . '</div></div>');

            case 'menu':
                $links = '';

                foreach (self::menuItems((string) $p['items']) as [$label, $url]) {
                    $links .= '<a href="' . $e($url) . '" style="' . $ff . ';font-size:14px;font-weight:600;color:' . $s['heading']
                        . ';text-decoration:none;margin:0 9px;display:inline-block">' . $e($label) . '</a>';
                }

                return $wrap($links, 'text-align:' . $p['align']);

            case 'html':
                return $wrap((string) $p['code']);

            case 'footer':
                return $wrap('<div style="' . $ff . ';font-size:12px;line-height:1.6;color:#71717A;text-align:center"><div>' . $e($p['note'])
                    . '</div><div style="margin-top:6px"><b style="color:#52525B">' . $e($p['company']) . '</b>' . ($p['address'] !== '' ? ' &middot; ' . $e($p['address']) : '')
                    . '</div><div style="margin-top:8px"><a href="{{ unsubscribe_url }}" style="color:#71717A">' . $e($p['unsubscribe']) . '</a></div></div>');

            case 'products':
                // Filled from the catalog when the email is rendered
                $attributes = '';

                foreach ($p['options'] as $name => $value) {
                    $attributes .= ' data-' . str_replace('_', '-', $name) . '="' . $e((string) $value) . '"';
                }

                return '<div data-ec-widget="products"' . $attributes . '></div>';

            case 'storeheader':
                return self::STORE_HEADER;

            case 'storefooter':
                return self::STORE_FOOTER;
        }

        return '';
    }

    public static function socialIcon(string $net, int $size): string
    {
        [$name, $colour, $glyph] = self::SOCIAL[$net];

        return '<span title="' . $name . '" style="display:inline-block;width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;background:' . $colour
            . ';color:#fff;font:700 ' . (int) round($size * .38) . 'px/' . $size . 'px Helvetica,Arial,sans-serif;text-align:center;margin:0 4px">' . $glyph . '</span>';
    }

    /**
     * "Books | https://shop/books, Sale | /sale" as [label, url] pairs.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public static function menuItems(string $items): array
    {
        $out = [];

        foreach (explode(',', $items) as $item) {
            [$label, $url] = array_map('trim', array_pad(explode('|', $item, 2), 2, ''));

            if ($label !== '') {
                $out[] = [$label, $url !== '' ? $url : '#'];
            }
        }

        return $out;
    }

    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
