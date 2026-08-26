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
}
