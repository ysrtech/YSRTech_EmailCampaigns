<?php
/**
 * Mailgun transport. Uses the v3 messages API with batch + scheduled delivery.
 */
class YSRTech_EmailCampaigns_Model_Transport_Mailgun
    implements YSRTech_EmailCampaigns_Model_Transport_Interface
{
    private const API_BASE = 'https://api.mailgun.net/v3/';

    private function getApiKey(): string
    {
        $key = (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/api_key');
        if ($key === '') {
            Mage::throwException('Mailgun API key is not configured.');
        }
        return $key;
    }

    private function getDomain(): string
    {
        return (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/mailgun_domain');
    }

    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null)
    {
        $params = $this->baseParams($subject, $html, $timestamp);
        $params['to'] = $to;
        if ($vars) {
            $params['recipient-variables'] = json_encode([$to => $vars]);
        }
        return $this->post($params);
    }

    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null)
    {
        if (!$recipients) {
            return null;
        }
        $params = $this->baseParams($subject, $html, $timestamp);
        $emails = [];
        $variables = [];
        foreach ($recipients as $r) {
            $emails[] = isset($r['name']) && $r['name'] !== ''
                ? sprintf('%s <%s>', $r['name'], $r['email'])
                : $r['email'];
            if (!empty($r['vars'])) {
                $variables[$r['email']] = $r['vars'];
            }
        }
        // Mailgun allows up to 1000 recipients per call; keep to 100 for safety.
        $params['to'] = implode(',', array_slice($emails, 0, 100));
        if ($variables) {
            $params['recipient-variables'] = json_encode($variables);
        }
        return $this->post($params);
    }

    private function baseParams(string $subject, string $html, ?int $timestamp): array
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');
        $params = [
            'from'       => sprintf('%s <%s>',
                $helper->getConfig('sending/from_name') ?: 'Store',
                $helper->getConfig('sending/from_email')),
            'subject'    => $subject,
            'html'       => $html,
            'o:tracking' => Mage::helper('ysrtech_emailcampaigns')->getConfig('tracking/enabled') ? 'yes' : 'no',
            'o:tracking-opens'   => 'yes',
            'o:tracking-clicks'  => 'htmlonly',
            // Substituted per-recipient from recipient-variables (Sender.php sets
            // 'tracking_token' there) and echoed back on every subsequent webhook
            // event for this send, so WebhookController can match an "opened" or
            // "clicked" event back to the exact queue row.
            'v:tracking_token'   => '%recipient.tracking_token%',
        ];
        if ($timestamp !== null) {
            $params['o:deliverytime'] = gmdate('D, d M Y H:i:s', $timestamp) . ' +0000';
        }
        return $params;
    }

    /**
     * @return mixed decoded response (contains message id on success)
     */
    private function post(array $params)
    {
        $url = self::API_BASE . $this->getDomain() . '/messages';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => 'api:' . $this->getApiKey(),
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Mage::throwException('Mailgun request failed: ' . $err);
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            Mage::throwException("Mailgun error (HTTP {$status}): " . substr((string) $body, 0, 500));
        }
        return $decoded;
    }
}
