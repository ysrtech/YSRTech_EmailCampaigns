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
        /*
         * {{ customer.firstname }}, and {{ customer.firstname|there }} for a
         * fallback. Most of this store's subscribers have no name recorded at
         * all, and without a fallback a greeting renders as "Hi ,".
         */
        return preg_replace_callback(
            '/{{\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([^}]*?)\s*)?}}/',
            static function (array $m) use ($vars) {
                $fallback = isset($m[2]) ? $m[2] : '';
                $parts = explode('.', $m[1]);
                $val = $vars;
                foreach ($parts as $p) {
                    if (!is_array($val) || !array_key_exists($p, $val)) {
                        return $fallback;
                    }
                    $val = $val[$p];
                }
                if (!is_scalar($val)) {
                    return $fallback;
                }
                $val = (string) $val;
                return $val === '' ? $fallback : $val;
            },
            $text
        );
    }
}
