<?php
/**
 * Provider event webhooks. Lets a transport's own bounce/complaint/unsubscribe
 * handling suppress future sends here too, instead of only in that provider's
 * own (transport-specific) suppression list.
 */
class YSRTech_EmailCampaigns_WebhookController extends Mage_Core_Controller_Front_Action
{
    /**
     * Mailgun event webhook (https://documentation.mailgun.com/en/latest/user_manual.html#webhooks).
     * Verifies the request is genuinely from Mailgun before acting on it, since
     * this endpoint is unauthenticated and reachable from the public internet.
     */
    public function mailgunAction()
    {
        $payload = json_decode((string) $this->getRequest()->getRawBody(), true);
        if (!is_array($payload)) {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        if (!$this->_isValidMailgunSignature((array) ($payload['signature'] ?? []))) {
            $this->getResponse()->setHttpResponseCode(401);
            return;
        }

        $eventData = (array) ($payload['event-data'] ?? []);
        $event = (string) ($eventData['event'] ?? '');
        $email = (string) ($eventData['recipient'] ?? '');

        $isSuppressionEvent = in_array($event, ['unsubscribed', 'complained'], true)
            || ($event === 'failed' && ($eventData['severity'] ?? '') === 'permanent');

        if ($email !== '' && $isSuppressionEvent) {
            try {
                Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')->suppress($email, $event);
            } catch (Exception $e) {
                Mage::logException($e);
                $this->getResponse()->setHttpResponseCode(500);
                return;
            }
        }

        $this->getResponse()->setHttpResponseCode(200);
    }

    /**
     * HMAC-SHA256(timestamp . token, signing_key) per Mailgun's webhook security docs.
     */
    private function _isValidMailgunSignature(array $signature): bool
    {
        $key = (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/mailgun_webhook_signing_key');
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');
        $provided = (string) ($signature['signature'] ?? '');

        if ($key === '' || $timestamp === '' || $token === '' || $provided === '') {
            return false;
        }
        // Basic replay protection: Mailgun expects consumers to reject stale timestamps.
        if (abs(time() - (int) $timestamp) > 900) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . $token, $key);
        return hash_equals($expected, $provided);
    }

    /**
     * Resend event webhook (https://resend.com/docs/dashboard/webhooks/event-types),
     * delivered via Svix. Resend's transactional /emails and /emails/batch endpoints
     * (what Transport/Resend.php actually sends through) have no unsubscribe click of
     * their own, but they do still report bounces and spam complaints — treat both as
     * suppression events. Resend only emits "email.bounced" for permanent bounces;
     * transient delivery issues surface as "email.delivery_delayed" instead, which
     * isn't a suppression signal.
     */
    public function resendAction()
    {
        $rawBody = (string) $this->getRequest()->getRawBody();
        if (!$this->_isValidResendSignature($rawBody)) {
            $this->getResponse()->setHttpResponseCode(401);
            return;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->getResponse()->setHttpResponseCode(400);
            return;
        }

        $event = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $recipients = array_filter((array) ($data['to'] ?? []), static fn ($v) => is_string($v) && $v !== '');

        if (in_array($event, ['email.bounced', 'email.complained'], true)) {
            try {
                foreach ($recipients as $email) {
                    Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')->suppress($email, $event);
                }
            } catch (Exception $e) {
                Mage::logException($e);
                $this->getResponse()->setHttpResponseCode(500);
                return;
            }
        }

        $this->getResponse()->setHttpResponseCode(200);
    }

    /**
     * Svix webhook signature verification (https://docs.svix.com/receiving/verifying-payloads/how-manual).
     * Resend's signing secret has the same "whsec_<base64>" shape Svix issues directly.
     */
    private function _isValidResendSignature(string $rawBody): bool
    {
        $secret = (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/resend_webhook_signing_secret');
        if ($secret === '' || !str_starts_with($secret, 'whsec_')) {
            return false;
        }
        $secretBytes = base64_decode(substr($secret, strlen('whsec_')));

        $svixId = (string) $this->getRequest()->getHeader('svix-id');
        $svixTimestamp = (string) $this->getRequest()->getHeader('svix-timestamp');
        $svixSignature = (string) $this->getRequest()->getHeader('svix-signature');
        if ($svixId === '' || $svixTimestamp === '' || $svixSignature === '') {
            return false;
        }
        // Svix's own recommended tolerance.
        if (abs(time() - (int) $svixTimestamp) > 300) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$svixId}.{$svixTimestamp}.{$rawBody}", $secretBytes, true));
        foreach (explode(' ', $svixSignature) as $part) {
            [$version, $sig] = array_pad(explode(',', $part, 2), 2, '');
            if ($version === 'v1' && $sig !== '' && hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }
}
