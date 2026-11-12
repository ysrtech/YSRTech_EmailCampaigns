<?php
/**
 * The few read and delete calls made to Mailgun outside of sending.
 *
 * Sending stays in Transport_Mailgun, which has its own reasons for how it
 * posts. This is for everything that asks Mailgun a question: the suppression
 * sync, the connection test, and taking an address back off Mailgun's lists
 * when it is removed from ours.
 */
class YSRTech_EmailCampaigns_Model_Mailgun_Api
{
    /**
     * @param  string      $path   Relative to the regional base, e.g. "domains/x"
     * @param  string      $key    API key, plaintext
     * @param  string|null $region "us" or "eu"; null reads the configured one
     * @param  array|null  $fields Form fields to send with a POST or PUT
     * @return array{status:int, body:string, json:array}
     * @throws Mage_Core_Exception When the request could not be made at all
     */
    public function request(string $method, string $path, string $key, ?string $region = null, ?array $fields = null): array
    {
        $url = preg_match('#^https://#', $path)
            ? $path
            : Mage::helper('ysrtech_emailcampaigns')->getMailgunApiBase($region) . ltrim($path, '/');

        /*
         * Paging links come back from Mailgun as whole URLs and are followed
         * as given. Only ever to Mailgun: the key goes out with the request,
         * and a "next" pointing anywhere else is not one to send it to.
         */
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($host, ['api.mailgun.net', 'api.eu.mailgun.net'], true)) {
            Mage::throwException("Refusing to send a Mailgun key to {$host}.");
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_USERPWD        => 'api:' . $key,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        if ($fields !== null) {
            // As a form, which is what Mailgun's settings endpoints read
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Mage::throwException('Mailgun request failed: ' . $error);
        }

        $json = json_decode((string) $body, true);

        return ['status' => $status, 'body' => (string) $body, 'json' => is_array($json) ? $json : []];
    }
}
