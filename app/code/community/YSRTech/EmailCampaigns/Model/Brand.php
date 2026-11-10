<?php
/**
 * A store view's look for email: its logo, its colours, its header and its
 * footer.
 *
 * Set once under System > Configuration > Email Campaigns > Brand, per
 * store view, and applied when an email is rendered. Changing the logo or a
 * footer link is then one change that every later send picks up - a
 * campaign going out tomorrow, an automation that has been running for a
 * year - instead of an edit to every email that carries a copy of it.
 */
class YSRTech_EmailCampaigns_Model_Brand
{
    public const WIDTH = 600;

    private const DEFAULTS = [
        'heading_color'     => '#222222',
        'button_color'      => '#1f4e79',
        'button_text_color' => '#ffffff',
        'page_bg'           => '#f4f4f4',
        'content_bg'        => '#ffffff',
        'text_color'        => '#444444',
        'link_color'        => '#1f4e79',
        'muted_color'       => '#888888',
        'font'              => 'Arial,Helvetica,sans-serif',
        // Empty means "the same as the text"
        'heading_font'      => '',
        'logo_width'        => 180,
    ];

    /** @var array<string, array> By store id, for the life of the request */
    private $_cache = [];

    public function isEnabled($storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag('ysrtech_emailcampaigns/brand/enabled', $this->_store($storeId));
    }

    /**
     * Every value of the brand for a store view, with a default behind each.
     *
     * @return array<string, mixed>
     */
    public function get($storeId = null): array
    {
        $store = $this->_store($storeId);
        $key   = (string) $store;

        if (isset($this->_cache[$key])) {
            return $this->_cache[$key];
        }

        $brand = self::DEFAULTS;

        foreach (array_keys(self::DEFAULTS) as $field) {
            $value = trim((string) Mage::getStoreConfig('ysrtech_emailcampaigns/brand/' . $field, $store));

            if ($field === 'logo_width') {
                $brand[$field] = max(40, min(self::WIDTH - 64, (int) $value ?: self::DEFAULTS[$field]));
            } elseif ($field === 'font' || $field === 'heading_font') {
                // Only ever one of the listed stacks: this goes into a style
                // attribute in every email
                $fonts = Mage::getModel('ysrtech_emailcampaigns/system_config_source_brand_font')->toOptionArray();
                $brand[$field] = in_array($value, array_column($fonts, 'value'), true) ? $value : self::DEFAULTS[$field];
            } elseif ($value !== '' && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value)) {
                $brand[$field] = strtolower($value);
            }
        }

        if ($brand['heading_font'] === '') {
            $brand['heading_font'] = $brand['font'];
        }

        // The @import for whichever of the two is a web font, if either is
        $families = [];

        foreach (YSRTech_EmailCampaigns_Model_System_Config_Source_Brand_Font::webFonts() as $family => $query) {
            if (strpos($brand['font'] . $brand['heading_font'], "'" . $family . "'") !== false) {
                $families[] = 'family=' . $query;
            }
        }

        $brand['font_import'] = $families
            ? "@import url('https://fonts.googleapis.com/css2?" . implode('&', $families) . "&display=swap');" : '';

        $logo = trim((string) Mage::getStoreConfig('ysrtech_emailcampaigns/brand/logo', $store));

        // Addresses are the storefront's. "All store views" has none of its
        // own - asked for one it gives the admin's - so the default store
        // view's stand in.
        $front = $store ? Mage::app()->getStore($store) : Mage::app()->getDefaultStoreView();

        $brand['logo_url']     = $logo !== ''
            ? $front->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . 'ysrtech/emailcampaigns/brand/' . $logo
            : '';
        $brand['store_name']   = Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($store);
        $brand['store_url']    = trim((string) Mage::getStoreConfig('ysrtech_emailcampaigns/brand/logo_link', $store))
            ?: $front->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
        $brand['address']      = trim((string) Mage::getStoreConfig('general/store_information/address', $store));
        $brand['header_links'] = $this->_links((string) Mage::getStoreConfig('ysrtech_emailcampaigns/brand/header_links', $store), 5);
        $brand['footer_links'] = $this->_links((string) Mage::getStoreConfig('ysrtech_emailcampaigns/brand/footer_links', $store), 8);
        $brand['footer_text']  = trim((string) Mage::getStoreConfig('ysrtech_emailcampaigns/brand/footer_text', $store));

        return $this->_cache[$key] = $brand;
    }

    /**
     * The header: the logo, linked to the store, and a row of links under
     * it when there are any. The store's name in words when there is no logo.
     */
    public function header($storeId = null): string
    {
        $b = $this->get($storeId);
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $logo = $b['logo_url'] !== ''
            ? '<img src="' . $e($b['logo_url']) . '" width="' . (int) $b['logo_width'] . '" alt="' . $e($b['store_name'])
                . '" style="display:block;border:0;width:' . (int) $b['logo_width'] . 'px;max-width:100%;height:auto;" />'
            : '<span style="font-size:24px;line-height:1.2;color:' . $b['heading_color'] . ';">' . $e($b['store_name']) . '</span>';

        $links = '';

        foreach ($b['header_links'] as [$label, $url]) {
            $links .= '<a href="' . $e($url) . '" style="display:inline-block;padding:0 10px;font-size:13px;color:' . $b['link_color']
                . ';text-decoration:none;">' . $e($label) . '</a>';
        }

        return '<tr><td align="center" style="padding:24px 32px ' . ($links ? '12px' : '20px') . ';font-family:' . $b['font'] . ';">'
            . '<a href="' . $e($b['store_url']) . '" style="text-decoration:none;">' . $logo . '</a></td></tr>'
            . ($links ? '<tr><td align="center" style="padding:0 24px 16px;font-family:' . $b['font'] . ';">' . $links . '</td></tr>' : '')
            . '<tr><td style="padding:0;"><div style="height:1px;line-height:1px;font-size:0;background-color:#eeeeee;">&nbsp;</div></td></tr>';
    }

    /**
     * The footer: links, the store's name and postal address, any small
     * print, and the way out. Always the unsubscribe link: an email that
     * wears this footer needs no other.
     */
    public function footer($storeId, string $unsubscribeUrl): string
    {
        $b = $this->get($storeId);
        $h = Mage::helper('ysrtech_emailcampaigns');
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $links = [];

        foreach ($b['footer_links'] as [$label, $url]) {
            $links[] = '<a href="' . $e($url) . '" style="color:' . $b['muted_color'] . ';text-decoration:underline;">' . $e($label) . '</a>';
        }

        $unsubscribe = $unsubscribeUrl !== ''
            ? '<p style="margin:10px 0 0;"><a href="' . $e($unsubscribeUrl) . '" style="color:' . $b['muted_color'] . ';text-decoration:underline;">'
                . $e($h->__('Unsubscribe')) . '</a></p>'
            : '';

        return '<tr><td style="padding:0;"><div style="height:1px;line-height:1px;font-size:0;background-color:#eeeeee;">&nbsp;</div></td></tr>'
            . '<tr><td align="center" style="padding:20px 32px 28px;font-family:' . $b['font'] . ';font-size:12px;line-height:1.6;color:' . $b['muted_color'] . ';">'
            . ($links ? '<p style="margin:0 0 10px;">' . implode(' &nbsp;&middot;&nbsp; ', $links) . '</p>' : '')
            . '<p style="margin:0 0 4px;">' . $e($b['store_name']) . '</p>'
            . ($b['address'] !== '' ? '<p style="margin:0;">' . nl2br($e($b['address'])) . '</p>' : '')
            . ($b['footer_text'] !== '' ? '<p style="margin:10px 0 0;">' . nl2br($e($b['footer_text'])) . '</p>' : '')
            . $unsubscribe . '</td></tr>';
    }

    /**
     * Put existing templates and draft campaigns into the store's header
     * and footer.
     *
     * Two things per email: the switch goes on, and the footer it was
     * designed with comes out - the table row holding {{unsubscribe}} - so
     * that it does not end up with that one and the store's as well. An
     * email with no such row is only switched on. Campaigns that are
     * scheduled, sending or sent are left exactly as they are.
     *
     * @return array{templates:int, campaigns:int, footers:int}
     */
    public function applyToExisting(): array
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $counts   = ['templates' => 0, 'campaigns' => 0, 'footers' => 0];

        foreach ([
            'templates' => [$resource->getTableName('ysrtech_emailcampaigns/template'), 'template_id', null],
            'campaigns' => [$resource->getTableName('ysrtech_emailcampaigns/campaign'), 'campaign_id', 'draft'],
        ] as $kind => [$table, $idField, $status]) {
            $select = $adapter->select()->from($table, [$idField, 'html', 'use_brand']);

            if ($status !== null) {
                $select->where('status = ?', $status);
            }

            foreach ($adapter->fetchAll($select) as $row) {
                $html     = (string) $row['html'];
                $stripped = $this->stripOwnFooter($html);
                $bind     = [];

                if ((int) $row['use_brand'] !== 1) {
                    $bind['use_brand'] = 1;
                }

                if ($stripped !== $html) {
                    $bind['html'] = $stripped;
                    $counts['footers']++;
                }

                if ($bind) {
                    $adapter->update($table, $bind, [$idField . ' = ?' => (int) $row[$idField]]);
                    $counts[$kind]++;
                }
            }
        }

        return $counts;
    }

    /**
     * An email's HTML without the row that holds its own unsubscribe footer.
     *
     * Only the innermost table row around {{unsubscribe}} goes, and only
     * when it is there: the starters and most hand-made designs keep the
     * footer in a row of its own. Anything else is returned untouched.
     */
    public function stripOwnFooter(string $html): string
    {
        $at = strpos($html, '{{unsubscribe}}');

        if ($at === false) {
            return $html;
        }

        $start = strripos(substr($html, 0, $at), '<tr');
        $end   = stripos($html, '</tr>', $at);

        // A row opened and closed around the token, with no other row
        // starting in between: the innermost one
        if ($start === false || $end === false || stripos(substr($html, $start + 3, $end - $start - 3), '<tr') !== false) {
            return $html;
        }

        return substr($html, 0, $start) . substr($html, $end + 5);
    }

    /**
     * "Label | https://..." a line, as typed into the settings.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private function _links(string $text, int $max): array
    {
        $links = [];

        foreach (preg_split('/\r?\n/', $text) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));

            // Web addresses only: this becomes an href in every email
            if (count($parts) === 2 && $parts[0] !== '' && preg_match('#^https?://#i', $parts[1])) {
                $links[] = [$parts[0], $parts[1]];
            }

            if (count($links) >= $max) {
                break;
            }
        }

        return $links;
    }

    /**
     * Store id 0 means "all store views", which is the default scope.
     */
    private function _store($storeId)
    {
        return $storeId ? (int) $storeId : Mage_Core_Model_App::ADMIN_STORE_ID;
    }
}
