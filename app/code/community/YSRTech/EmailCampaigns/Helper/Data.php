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
     * Read a config value that is stored encrypted, such as the provider API
     * key.
     *
     * The field is declared with adminhtml/system_config_backend_encrypted, so
     * the backend model encrypts it on save and core_config_data holds
     * ciphertext. Mage::getStoreConfig hands back whatever is in the column
     * verbatim -- decrypting is the reader's job in Magento 1, and skipping it
     * means the ciphertext goes out as the API key and every call comes back
     * 401 unauthorized.
     */
    public function getSecret(string $path, $storeId = null): string
    {
        $stored = trim((string) $this->getConfig($path, $storeId));

        if ($stored === '') {
            return '';
        }

        try {
            $plain = trim((string) Mage::helper('core')->decrypt($stored));
        } catch (Throwable $e) {
            // A missing crypt module raises an Error, not an Exception, and a
            // 500 on the config read is worse than the fallback below
            $plain = '';
        }

        /*
         * Decrypting a value that was never encrypted -- one written straight
         * into the table by a fixture or a migration rather than through the
         * admin -- does not fail, it returns empty or binary noise. An API key
         * is printable ASCII, so anything else means the stored value was
         * already plaintext and should be used as it stands.
         */
        if ($plain !== '' && preg_match('/^[\x20-\x7E]+$/', $plain)) {
            return $plain;
        }

        return $stored;
    }

    /**
     * Base URL of Mailgun's API for the configured region, with the trailing
     * slash.
     *
     * A domain lives in one region. Asking the US endpoint about an EU domain
     * does not say "wrong region", it says 404 - or 401 for the key - so
     * everything that talks to Mailgun has to agree on this.
     */
    public function getMailgunApiBase(?string $region = null): string
    {
        $region = strtolower(trim((string) ($region ?? $this->getConfig('sending/mailgun_region'))));

        return $region === 'eu' ? 'https://api.eu.mailgun.net/v3/' : 'https://api.mailgun.net/v3/';
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

    /**
     * A segment's rules in words, one top-level condition a line. Empty for a
     * segment with no conditions, which matches every subscriber.
     *
     * @return string[]
     */
    public function segmentRuleLines(YSRTech_EmailCampaigns_Model_Segment $segment): array
    {
        $root = $segment->getConditions();

        if (!$root instanceof Mage_Rule_Model_Condition_Combine) {
            return [];
        }

        $lines = [];

        foreach ((array) $root->getConditions() as $condition) {
            $text = $condition instanceof Mage_Rule_Model_Condition_Combine
                ? $condition->asStringRecursive()
                : $condition->asString();

            // Nested groups come back indented over several lines
            $lines[] = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        }

        return array_values(array_filter($lines, 'strlen'));
    }
}
