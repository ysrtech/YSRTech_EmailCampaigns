<?php
/**
 * Renders a template's design JSON / HTML into final email HTML
 * with merge variables, unsubscribe footer and responsive table layout.
 */
class YSRTech_EmailCampaigns_Model_Renderer
{
    /**
     * @param YSRTech_EmailCampaigns_Model_Template $template
     * @param array $vars merge variables (customer.*, store.*)
     */
    public function render(YSRTech_EmailCampaigns_Model_Template $template, array $vars): string
    {
        if ($template->getIsLetter()) {
            // A letter has no design to render: this is how it is shown
            // where an email's HTML is expected, its words and nothing else
            $letter = Mage::getSingleton('ysrtech_emailcampaigns/letter');

            // In a preview there is nobody to unsubscribe: show where the
            // link will be, with an address that reads as what it is
            if (empty($vars['unsubscribe_url']) || strpos((string) $vars['unsubscribe_url'], 'http') !== 0) {
                $vars['unsubscribe_url'] = Mage::app()->getStore($this->_storeId)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK) . 'unsubscribe';
            }

            return $letter->html($letter->text((string) $template->getHtml(), $vars));
        }

        if ((int) $template->getUseBrand() === self::FRAME_DOC) {
            return $this->_renderDocument($template, $vars);
        }

        $html = (string) $template->getHtml();
        if ($html === '' && $template->getDesignJson()) {
            $html = $this->renderDesign(json_decode((string) $template->getDesignJson(), true) ?: []);
        }
        if ($html === '') {
            Mage::throwException('Template has no content to render.');
        }

        $html = $this->_unwrapDocument($html);

        /*
         * Product blocks first. They are filled from the catalog as it is
         * now, and what they found feeds two things below: the names for the
         * preview text, and whether the email needs their phone rules.
         */
        /** @var YSRTech_EmailCampaigns_Model_Widget_Products $widgets */
        $widgets          = Mage::getSingleton('ysrtech_emailcampaigns/widget_products');
        $this->_hasBlocks = strpos($html, 'data-ec-widget') !== false;
        $html             = $widgets->process($html, $this->_storeId);

        $vars += [
            'date'     => $this->_dates(),
            'products' => ['names' => $widgets->getFirstNames()],
        ];

        $helper = Mage::helper('ysrtech_emailcampaigns');
        $html = $helper->renderMergeVars($html, $vars);

        /** @var YSRTech_EmailCampaigns_Model_Brand $brand */
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');

        if ((int) $template->getUseBrand() === 1 && $brand->isEnabled($this->_storeId)) {
            return $this->_wrapBranded($template, $html, $vars, $brand);
        }

        if ((int) $template->getUseBrand() === self::FRAME_NONE) {
            return $this->_wrapBare($template, $html, $vars);
        }

        $html = $this->_injectUnsubscribe($html, $vars);

        return $this->_wrapDocument(
            $this->_preheader($template, $vars)
            . ($this->_hasBlocks ? '<style>' . YSRTech_EmailCampaigns_Model_Widget_Products::css() . '</style>' : '')
            . $html,
            $vars
        );
    }

    /**
     * An email from the builder. It is already a whole document with its
     * own frame, so nothing is put around it; what is still to do is fill
     * it in: the store's header and footer where their blocks stand, the
     * product blocks from the catalog, each reader's values, and the
     * preview text at the top.
     */
    protected function _renderDocument(YSRTech_EmailCampaigns_Model_Template $template, array $vars): string
    {
        $html = (string) $template->getHtml();

        if ($html === '') {
            Mage::throwException('Template has no content to render.');
        }

        $H = YSRTech_EmailCampaigns_Model_Design_Html::class;

        /** @var YSRTech_EmailCampaigns_Model_Brand $brand */
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');
        $b     = $brand->get($this->_storeId);
        $table = static fn (string $rows) => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'
            . $b['font'] . ';color:' . $b['text_color'] . ';">' . $rows . '</table>';

        if (strpos($html, $H::STORE_HEADER) !== false) {
            $html = str_replace($H::STORE_HEADER, $table($brand->header($this->_storeId)), $html);
        }

        if (strpos($html, $H::STORE_FOOTER) !== false) {
            $html = str_replace($H::STORE_FOOTER, $table($brand->footer($this->_storeId, '{{ unsubscribe_url }}')), $html);
        }

        /** @var YSRTech_EmailCampaigns_Model_Widget_Products $widgets */
        $widgets          = Mage::getSingleton('ysrtech_emailcampaigns/widget_products');
        $this->_hasBlocks = strpos($html, 'data-ec-widget') !== false;
        $html             = $widgets->process($html, $this->_storeId);

        $vars += [
            'date'     => $this->_dates(),
            'products' => ['names' => $widgets->getFirstNames()],
        ];

        $html = Mage::helper('ysrtech_emailcampaigns')->renderMergeVars($html, $vars);

        // The older footer token, where an email brought in from before
        // the builder still carries it
        if (strpos($html, '{{unsubscribe}}') !== false) {
            $html = $this->_injectUnsubscribe($html, $vars);
        }

        $css = $b['font_import'] . ($this->_hasBlocks ? YSRTech_EmailCampaigns_Model_Widget_Products::css() : '');

        if ($css !== '' && stripos($html, '</head>') !== false) {
            $html = (string) preg_replace('#</head>#i', '<style>' . $css . '</style></head>', $html, 1);
        }

        $preheader = $this->_preheader($template, $vars);

        if ($preheader !== '') {
            $html = (string) preg_replace('#(<body\b[^>]*>)#i', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $preheader), $html, 1);
        }

        return $html;
    }

    /**
     * What is inside a document's body, for HTML that is to be put inside
     * another email.
     */
    public function bodyOf(string $html): string
    {
        return $this->_unwrapDocument($html);
    }

    /**
     * An email from before the builder as the whole document it is sent
     * as, with every placeholder and product block left in: what the
     * builder holds it as when it is opened there.
     */
    public function legacyDocument(YSRTech_EmailCampaigns_Model_Template $template): string
    {
        $html = $this->_unwrapDocument((string) $template->getHtml());
        $keep = ['unsubscribe_url' => '{{ unsubscribe_url }}', 'store' => ['name' => '{{ store.name }}']];

        $this->_hasBlocks = false;

        if ((int) $template->getUseBrand() === self::FRAME_NONE) {
            $document = $this->_wrapBare($template->setPreheader(''), $html, $keep);
        } else {
            $document = $this->_wrapDocument($this->_injectUnsubscribe($html, $keep), ['store' => ['name' => '']]);
        }

        return $document;
    }

    /** @var bool Whether the email being rendered has product blocks in it */
    protected $_hasBlocks = false;

    /**
     * Today, for a dateline: {{ date.long }} is "Nov 7, 2026" and
     * {{ date.hebrew }} is "Cheshvan 27 5787", both in the store's timezone.
     *
     * The Hebrew months are spelled the way this store's readers write
     * them, not the way the calendar library does.
     */
    protected function _dates(): array
    {
        $timezone = (string) Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE) ?: 'UTC';
        $now      = new DateTime('now', new DateTimeZone($timezone));
        $hebrew   = '';

        if (class_exists('IntlDateFormatter')) {
            $format = new IntlDateFormatter('en_US@calendar=hebrew', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
                $timezone, IntlDateFormatter::TRADITIONAL, 'MMMM d y');

            $hebrew = strtr((string) $format->format($now), [
                'Heshvan' => 'Cheshvan', 'Tevet' => 'Teves', 'Nisan' => 'Nissan', 'Iyyar' => 'Iyar',
                'Tamuz' => 'Tammuz', 'Tishri' => 'Tishrei',
            ]);
        }

        return ['long' => $now->format('M j, Y'), 'hebrew' => $hebrew, 'year' => $now->format('Y')];
    }

    /**
     * What goes around an email, as kept in a template's or a campaign's
     * use_brand: the plain frame every email had before there was a choice
     * (0), the store view's header and footer (1), or nothing at all (2).
     */
    public const FRAME_PLAIN = 0;
    public const FRAME_BRAND = 1;
    public const FRAME_NONE  = 2;
    /** A whole document from the builder, sent as it stands */
    public const FRAME_DOC   = 3;

    /**
     * An email that is a complete design in itself: its own header, its own
     * footer, its own background. Nothing is put around it.
     *
     * What it does get is a document to live in, because the designer keeps
     * only what is inside the body. So the head is written here each time:
     * the web fonts the design names, and the handful of rules a 600px table
     * layout needs to fold down on a phone. Those rules are tied to class
     * names a design opts into - wrap, px, col, cover, hero-h, nav, cat,
     * catrow, feat-img, feat-txt, center-m - so a design that uses none of
     * them is untouched.
     */
    protected function _wrapBare($template, string $html, array $vars): string
    {
        $html = $this->_injectUnsubscribe($html, $vars);

        // The colour behind the email, if its outermost table names one
        $background = preg_match('/data-ec-bg\s*=\s*"(#[0-9a-fA-F]{3,6})"/', $html, $m) ? $m[1] : '#ffffff';

        $families = [];

        foreach (YSRTech_EmailCampaigns_Model_System_Config_Source_Brand_Font::webFonts() as $family => $query) {
            if (strpos($html, $family) !== false) {
                $families[] = 'family=' . $query;
            }
        }

        $css = ($families ? "@import url('https://fonts.googleapis.com/css2?" . implode('&', $families) . "&display=swap');" : '')
            . 'body{margin:0 !important;padding:0 !important;width:100% !important;}'
            . 'table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0;}'
            . 'img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}'
            . 'a{text-decoration:none;}'
            . '@media screen and (max-width:620px){'
            . '.wrap{width:100% !important;}'
            . '.px{padding-left:20px !important;padding-right:20px !important;}'
            . '.col{display:block !important;width:100% !important;max-width:100% !important;padding:0 0 28px 0 !important;}'
            . '.cover{width:240px !important;}'
            . '.center-m{text-align:center !important;}'
            . '.hero-h{font-size:32px !important;line-height:38px !important;}'
            . '.feat-img,.feat-txt{display:block !important;width:100% !important;padding:0 !important;}'
            . '.feat-img img{margin:0 auto 20px auto !important;}'
            . '.nav a{display:inline-block !important;padding:6px 8px !important;}'
            . '.cat{padding:0 4px 8px 4px !important;}'
            . '.catrow{padding-left:12px !important;padding-right:12px !important;}'
            . '}'
            . ($this->_hasBlocks ? YSRTech_EmailCampaigns_Model_Widget_Products::css() : '');

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width,initial-scale=1" />'
            . '<meta name="x-apple-disable-message-reformatting" />'
            . '<style>' . $css . '</style></head>'
            . '<body style="margin:0;padding:0;background-color:' . $background . ';">'
            . $this->_preheader($template, $vars) . $html . '</body></html>';
    }

    /** @var int|null The store view the next render is for */
    protected $_storeId;

    /**
     * Which store view's brand - logo, colours, header, footer - the renders
     * that follow should wear. Null or 0 is the default.
     *
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->_storeId = $storeId ? (int) $storeId : null;

        return $this;
    }

    /**
     * The email inside the store view's header and footer.
     *
     * One 600px column on the brand's page colour: header, the email, footer.
     * The footer carries the unsubscribe link, so none is added besides - but
     * a {{unsubscribe}} left in the body by an older design is taken out
     * rather than drawn as a second one.
     */
    protected function _wrapBranded($template, string $html, array $vars, $brand): string
    {
        $b     = $brand->get($this->_storeId);
        $width = YSRTech_EmailCampaigns_Model_Brand::WIDTH;
        $html  = str_replace('{{unsubscribe}}', '', $html);

        return '<!DOCTYPE html><html><head><meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width,initial-scale=1" />'
            . '<style>' . $b['font_import'] . 'a{color:' . $b['link_color'] . ';}@media only screen and (max-width:620px){.ec-col{width:100% !important;}}'
            . ($this->_hasBlocks ? YSRTech_EmailCampaigns_Model_Widget_Products::css() : '') . '</style></head>'
            . '<body style="margin:0;padding:0;background-color:' . $b['page_bg'] . ';">'
            . $this->_preheader($template, $vars)
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $b['page_bg'] . ';">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" class="ec-col" width="' . $width . '" cellpadding="0" cellspacing="0" border="0" style="width:' . $width
            . 'px;max-width:100%;background-color:' . $b['content_bg'] . ';border-radius:4px;">'
            . $brand->header($this->_storeId)
            . '<tr><td style="padding:0;font-family:' . $b['font'] . ';color:' . $b['text_color'] . ';">' . $html . '</td></tr>'
            . $brand->footer($this->_storeId, (string) ($vars['unsubscribe_url'] ?? ''))
            . '</table></td></tr></table></body></html>';
    }

    /**
     * The preview text, as the first thing in the body and nowhere on screen.
     *
     * An inbox shows the opening words of a message after its subject. A
     * hidden element at the very top makes those words ours instead of
     * "View in browser" or a logo's alt text. The run of zero-width spaces
     * after it is padding: without it a short preview is topped up with
     * whatever comes next in the body, which is the thing being avoided.
     */
    protected function _preheader(YSRTech_EmailCampaigns_Model_Template $template, array $vars): string
    {
        $text = trim((string) $template->getPreheader());

        if ($text === '') {
            return '';
        }

        $text = Mage::helper('ysrtech_emailcampaigns')->renderMergeVars($text, $vars);

        return '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . str_repeat('&#8203;&nbsp;', 60) . '</div>';
    }

    /**
     * Reduce a whole document to the markup inside its body.
     *
     * The template is wrapped in <html><body> further down, so content that
     * already carries its own produces a document with two bodies - which is
     * what the designer's inlined export gives, and what somebody pasting a
     * full email from elsewhere gives too. Nested body tags are invalid, and
     * clients disagree about how to cope.
     *
     * Any <style> from the head is carried into the body rather than dropped:
     * a pasted template would otherwise lose all of its styling. Style blocks
     * inside the body are well supported by the clients that support style
     * blocks at all.
     *
     * @param  string $html
     * @return string
     */
    protected function _unwrapDocument(string $html): string
    {
        if (!preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $body)) {
            return $html;
        }

        $styles = '';

        if (preg_match('#<head\b[^>]*>(.*)</head>#is', $html, $head)
            && preg_match_all('#<style\b[^>]*>.*?</style>#is', $head[1], $found)
        ) {
            $styles = implode("\n", $found[0]);
        }

        return $styles . $body[1];
    }

    /**
     * Convert editor design JSON (list of blocks) into table-based email HTML.
     * Block types: text, image, button, divider, spacer.
     */
    public function renderDesign(array $design): string
    {
        $out = [];
        foreach ($design['blocks'] ?? [] as $block) {
            $style = $this->_inlineStyle($block['style'] ?? []);
            switch ($block['type'] ?? '') {
                case 'text':
                    $out[] = "<tr><td style=\"{$style}\">"
                        . nl2br($block['content'] ?? '') . '</td></tr>';
                    break;
                case 'image':
                    $src = htmlspecialchars((string) ($block['src'] ?? ''), ENT_QUOTES);
                    $alt = htmlspecialchars((string) ($block['alt'] ?? ''), ENT_QUOTES);
                    $out[] = "<tr><td style=\"{$style}\"><img src=\"{$src}\" alt=\"{$alt}\" "
                        . 'style="max-width:100%;display:block;border:0;" /></td></tr>';
                    break;
                case 'button':
                    $label = htmlspecialchars((string) ($block['label'] ?? 'Click'), ENT_QUOTES);
                    $url   = htmlspecialchars((string) ($block['url'] ?? '#'), ENT_QUOTES);
                    $bg    = htmlspecialchars((string) ($block['color'] ?? '#2563eb'), ENT_QUOTES);
                    $out[] = "<tr><td style=\"padding:12px 0;\" align=\"center\">"
                        . "<a href=\"{$url}\" style=\"background:{$bg};color:#ffffff;padding:12px 28px;"
                        . "border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;\">{$label}</a>"
                        . '</td></tr>';
                    break;
                case 'divider':
                    $out[] = '<tr><td style="padding:8px 0;"><hr style="border:0;'
                        . 'border-top:1px solid #dddddd;margin:0;" /></td></tr>';
                    break;
                case 'spacer':
                    $h = (int) ($block['height'] ?? 20);
                    $out[] = "<tr><td style=\"height:{$h}px;line-height:{$h}px;font-size:0;\">&nbsp;</td></tr>";
                    break;
            }
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . implode('', $out) . '</table>';
    }

    /**
     * Make sure the email carries exactly one way out.
     *
     * The merge pass has already run, so a template that wrote
     * {{ unsubscribe_url }} into its own markup now holds the real link. It
     * used to get the automatic footer on top of that, which put two
     * unsubscribe links in front of every recipient - the same duplication the
     * Mailgun transport warns about when the sending domain has a footer of
     * its own configured.
     */
    private function _injectUnsubscribe(string $html, array $vars): string
    {
        if (empty($vars['unsubscribe_url'])) {
            return $html;
        }

        $url    = htmlspecialchars((string) $vars['unsubscribe_url'], ENT_QUOTES);
        $footer = '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #eeeeee;'
            . 'font-size:11px;color:#999999;text-align:center;">'
            . "<a href=\"{$url}\" style=\"color:#999999;\">Unsubscribe</a></div>";

        // An explicit marker says where the standard footer goes
        if (str_contains($html, '{{unsubscribe}}')) {
            return str_replace('{{unsubscribe}}', $footer, $html);
        }

        // The template placed the link itself, so leave it alone
        if (str_contains($html, (string) $vars['unsubscribe_url']) || str_contains($html, $url)) {
            return $html;
        }

        return $html . $footer;
    }

    private function _wrapDocument(string $body, array $vars): string
    {
        $storeName = htmlspecialchars((string) ($vars['store']['name'] ?? ''), ENT_QUOTES);
        return '<!DOCTYPE html><html><head><meta charset="utf-8" />'
            . '<meta name="viewport" content="width=device-width,initial-scale=1" /></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f5;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"'
            . ' style="width:600px;max-width:100%;background:#ffffff;border-radius:8px;">'
            . $body
            . '</table>'
            . ($storeName !== '' ? "<div style=\"font-size:11px;color:#aaaaaa;margin-top:12px;\">{$storeName}</div>" : '')
            . '</td></tr></table></body></html>';
    }

    private function _inlineStyle(array $style): string
    {
        $map = [
            'color'       => static fn ($v) => 'color:' . $v,
            'fontSize'    => static fn ($v) => 'font-size:' . ((int) $v) . 'px',
            'align'       => static fn ($v) => 'text-align:' . $v,
            'padding'     => static fn ($v) => 'padding:' . ((int) $v) . 'px',
            'lineHeight'  => static fn ($v) => 'line-height:' . ((float) $v),
        ];
        $css = [];
        foreach ($style as $k => $v) {
            if (isset($map[$k]) && $v !== null && $v !== '') {
                $css[] = $map[$k]($v);
            }
        }
        $base = 'font-family:Arial,Helvetica,sans-serif;color:#333333;font-size:14px;line-height:1.6;';
        return $base . implode(';', $css) . ';';
    }
}
