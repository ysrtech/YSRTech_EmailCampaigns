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

        /*
         * Before renderMergeVars(), not after: its {{a.b.c}} regex matches
         * {{product.<id>.field}} too, and with no "product" key in $vars it
         * would silently blank every catalog marker before this ever saw them.
         */
        $html = $this->_injectCatalogData($html);

        $helper = Mage::helper('ysrtech_emailcampaigns');
        $html = $helper->renderMergeVars($html, $vars);
        $html = $this->_injectUnsubscribe($html, $vars);

        return $this->_wrapDocument($html, $vars);
    }

    /**
     * Resolves {{product.<id>.field}} / {{category.<id>.field}} markers the
     * designer's Product/Category blocks leave behind, against the live
     * catalog at send time — the same load-fresh-not-frozen principle as the
     * order/cart variables in Automation/Variables.php, just for catalog data
     * instead of order data. Loads are cached per process since the same
     * block renders once per recipient in a batch send.
     */
    private function _injectCatalogData(string $html): string
    {
        static $cache = [];

        return preg_replace_callback(
            '/\{\{(product|category)\.(\d+)\.(image|name|price|url)\}\}/',
            static function (array $m) use (&$cache) {
                [, $type, $id, $field] = $m;
                $cacheKey = "{$type}:{$id}";

                if (!isset($cache[$cacheKey])) {
                    $cache[$cacheKey] = $type === 'product'
                        ? Mage::getModel('catalog/product')->load((int) $id)
                        : Mage::getModel('catalog/category')->load((int) $id);
                }

                $item = $cache[$cacheKey];

                if (!$item->getId()) {
                    return '';
                }

                return match ($field) {
                    'name'  => htmlspecialchars((string) $item->getName(), ENT_QUOTES),
                    'price' => $type === 'product'
                        ? Mage::helper('core')->currency($item->getPrice(), true, false)
                        : '',
                    // Guarded the same way as TemplateController::catalogSearchAction():
                    // Mage_Catalog_Helper_Image throws rather than returning
                    // empty/placeholder when a product has no image set.
                    'image' => $type === 'product'
                        ? ($item->getSmallImage() && $item->getSmallImage() !== 'no_selection'
                            ? (string) Mage::helper('catalog/image')->init($item, 'small_image')->resize(300)
                            : '')
                        : (string) $item->getImageUrl(),
                    'url'   => $type === 'product' ? (string) $item->getProductUrl() : (string) $item->getUrl(),
                    default => '',
                };
            },
            $html
        );
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
