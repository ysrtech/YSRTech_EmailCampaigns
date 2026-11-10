<?php
/**
 * Shared HTTP handling for the providers: one place that turns an API's
 * errors into plain words and its rate limits into "try again shortly", and
 * one place that makes sure a key is only ever sent to the host it is for.
 */
abstract class YSRTech_EmailCampaigns_Model_Import_Provider_Abstract
    implements YSRTech_EmailCampaigns_Model_Import_Provider_Interface
{
    /** @var array */
    protected $_credentials = [];

    public function setCredentials(array $credentials)
    {
        $this->_credentials = $credentials;

        return $this;
    }

    public function getCredentialFields(): array
    {
        return [];
    }

    /**
     * The API host the key may be sent to. A next-page link anywhere else is
     * refused.
     */
    abstract protected function _host(): string;

    /**
     * Headers that authenticate a request, e.g. ['Authorization: Bearer x'].
     *
     * @return string[]
     */
    abstract protected function _authHeaders(): array;

    protected function _key(): string
    {
        $key = trim((string) ($this->_credentials['api_key'] ?? ''));

        if ($key === '') {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                Mage::helper('ysrtech_emailcampaigns')->__('%s needs an API key.', $this->getName())
            );
        }

        return $key;
    }

    /**
     * GET a path on the provider's host, or one of its own next-page links,
     * and return the decoded JSON.
     *
     * @param  string   $url     Path ("/v3/lists") or absolute URL on _host()
     * @param  array    $query
     * @param  string[] $headers Extra headers
     * @return array
     */
    protected function _get(string $url, array $query = [], array $headers = []): array
    {
        return $this->_json($this->_call('GET', $url, $query, $headers, null));
    }

    /**
     * POST JSON and return the decoded JSON.
     */
    protected function _post(string $url, array $body = [], array $headers = []): array
    {
        return $this->_json($this->_call('POST', $url, [], $headers, json_encode($body)));
    }

    /**
     * @return array{status:int, headers:array<string, string>, body:string}
     */
    protected function _call(string $method, string $url, array $query, array $headers, ?string $body): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $this->_host() . '/' . ltrim($url, '/');
        }

        if (stripos($url, 'https://') !== 0
            || strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower($this->_host())
        ) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(Mage::helper('ysrtech_emailcampaigns')->__(
                '%s returned a link to another host, so the import stopped rather than send your key there.',
                $this->getName()
            ));
        }

        if ($query) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $headers = array_merge(['Accept: application/json'], $this->_authHeaders(), $headers);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        return $this->_check($this->_send($method, $url, $headers, $body));
    }

    /**
     * The one place a request leaves the building, so a test can stand in
     * for it.
     *
     * @param  string[] $headers
     * @return array{status:int, headers:array<string, string>, body:string} Header names lower-cased
     */
    protected function _send(string $method, string $url, array $headers, ?string $body): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            // Never follow a redirect: it could carry the key to another host
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders) {
                if (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                Mage::helper('ysrtech_emailcampaigns')->__('%s did not answer. The import will try again shortly.', $this->getName()),
                true,
                60
            );
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response];
    }

    /**
     * @param  array{status:int, headers:array<string, string>, body:string} $response
     * @return array The same response, when it was a success
     */
    protected function _check(array $response): array
    {
        $status = $response['status'];

        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $h    = Mage::helper('ysrtech_emailcampaigns');
        $name = $this->getName();

        if ($status === 401 || $status === 403) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                $h->__('%s rejected the API key, or the key has no read access to this data.', $name)
            );
        }

        if ($status === 429) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                $h->__('%s is rate limiting the import. It will continue shortly.', $name),
                true,
                max(10, (int) ($response['headers']['retry-after'] ?? 60))
            );
        }

        if ($status >= 500) {
            throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
                $h->__('%s had a temporary problem (%d). The import will try again shortly.', $name, $status),
                true,
                60
            );
        }

        $json   = json_decode($response['body'], true);
        $detail = null;

        if (is_array($json)) {
            foreach ([['detail'], ['message'], ['error'], ['errors', 0, 'message'], ['errors', 0, 'detail']] as $path) {
                $value = $json;

                foreach ($path as $step) {
                    $value = is_array($value) && array_key_exists($step, $value) ? $value[$step] : null;
                }

                if (is_string($value) && $value !== '') {
                    $detail = $value;
                    break;
                }
            }
        }

        throw new YSRTech_EmailCampaigns_Model_Import_Provider_Exception(
            $h->__('%s said: %s', $name, mb_substr($detail ?? ('HTTP ' . $status), 0, 300))
        );
    }

    protected function _json(array $response): array
    {
        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }
}
