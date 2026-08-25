<?php
class YSRTech_EmailCampaigns_Helper_Data extends Mage_Core_Helper_Abstract
{
    /**
     * Read module config value for current store scope.
     */
    public function getConfig(string $path, $storeId = null)
    {
        return Mage::getStoreConfig('ysrtech_emailcampaigns/' . $path, $storeId);
    }

    /**
     * Replace {{ customer.firstname }} style merge variables.
     */
    public function renderMergeVars(string $text, array $vars): string
    {
        return preg_replace_callback(
            '/{{\s*([a-zA-Z0-9_.]+)\s*}}/',
            static function (array $m) use ($vars) {
                $parts = explode('.', $m[1]);
                $val = $vars;
                foreach ($parts as $p) {
                    if (!is_array($val) || !array_key_exists($p, $val)) {
                        return '';
                    }
                    $val = $val[$p];
                }
                return is_scalar($val) ? (string) $val : '';
            },
            $text
        );
    }
}
