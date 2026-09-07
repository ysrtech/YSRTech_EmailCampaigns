<?php
/**
 * Resend transport (https://resend.com) — batch send via /emails/batch.
 */
class YSRTech_EmailCampaigns_Model_Transport_Resend
    implements YSRTech_EmailCampaigns_Model_Transport_Interface
{
    private const API_BASE = 'https://api.resend.com/';

    private function getApiKey(): string
    {
        $key = (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/api_key');
        if ($key === '') {
            Mage::throwException('Resend API key is not configured.');
        }
        return $key;
    }

    /**
     * @inheritdoc
     *
     * Resend's batch endpoint takes discrete messages rather than one body
     * plus variables, so each carries its own html and there is nothing for
     * the provider to expand.
     */
    public function supportsRecipientVariables(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null)
    {
        $payload = [
            'from'    => $this->getFrom(),
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];
        if ($timestamp !== null) {
            $payload['scheduled_at'] = gmdate('Y-m-d\TH:i:s', $timestamp) . 'Z';
        }
        return $this->request('POST', 'emails', $payload);
    }

    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null)
    {
        if (!$recipients) {
            return null;
        }
        $messages = [];
        foreach (array_slice($recipients, 0, 100) as $r) {
            $messages[] = [
                'from'    => $this->getFrom(),
                'to'      => [$r['email']],
                'subject' => $subject,
                // Their own copy. Using the shared body posted the first
                // recipient's email - greeting and all - to everybody.
                'html'    => isset($r['html']) ? (string) $r['html'] : $html,
            ];
        }
        return $this->request('POST', 'emails/batch', $messages);
    }

    private function getFrom(): string
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');
        return sprintf('%s <%s>',
            $helper->getConfig('sending/from_name') ?: 'Store',
            $helper->getConfig('sending/from_email'));
    }

    private function request(string $method, string $path, array $payload)
    {
        $ch = curl_init(self::API_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->getApiKey(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Mage::throwException('Resend request failed: ' . $err);
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            Mage::throwException("Resend error (HTTP {$status}): " . substr((string) $body, 0, 500));
        }
        return $decoded;
    }
}
