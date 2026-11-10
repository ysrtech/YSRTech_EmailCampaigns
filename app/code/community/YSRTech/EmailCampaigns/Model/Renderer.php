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
        $html = (string) $template->getHtml();
        if ($html === '' && $template->getDesignJson()) {
            $html = $this->renderDesign(json_decode((string) $template->getDesignJson(), true) ?: []);
        }
        if ($html === '') {
            Mage::throwException('Template has no content to render.');
        }

        $html = $this->_unwrapDocument($html);

        $helper = Mage::helper('ysrtech_emailcampaigns');
        $html = $helper->renderMergeVars($html, $vars);

        /** @var YSRTech_EmailCampaigns_Model_Brand $brand */
        $brand = Mage::getSingleton('ysrtech_emailcampaigns/brand');

        if ((int) $template->getUseBrand() === 1 && $brand->isEnabled($this->_storeId)) {
            return $this->_wrapBranded($template, $html, $vars, $brand);
        }

        $html = $this->_injectUnsubscribe($html, $vars);

        return $this->_wrapDocument($this->_preheader($template, $vars) . $html, $vars);
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
            . '<style>a{color:' . $b['link_color'] . ';}@media only screen and (max-width:620px){.ec-col{width:100% !important;}}</style></head>'
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
