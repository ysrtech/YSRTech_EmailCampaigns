<?php
/**
 * Provider event webhooks: unsubscribes, complaints, hard bounces, opens and
 * clicks pushed back into the store instead of staying in the provider's
 * dashboard.
 *
 * Worth having even though Suppressions already polls Mailgun hourly. A
 * webhook arrives in seconds rather than up to an hour, it covers opens and
 * clicks which no suppression list reports, and it needs no account-level API
 * key - the provider is pushing to us, so there is no suppression endpoint to
 * be authorised against. The poll stays as the safety net for anything a
 * delivery missed, which is the one thing webhooks are bad at.
 *
 * Both endpoints are unauthenticated and reachable from the internet, so each
 * verifies the provider's signature before it keeps anything. A notice is
 * kept first and applied by cron a moment later: see Webhook_Inbox.
 */
class YSRTech_EmailCampaigns_WebhookController extends Mage_Core_Controller_Front_Action
{
    /** Mailgun's own guidance on rejecting stale timestamps */
    private const MAILGUN_TOLERANCE = 900;

    /** Svix's recommended tolerance */
    private const SVIX_TOLERANCE = 300;

    /**
     * POST /emailcampaigns/webhook/mailgun
     *
     * Checked, written down, answered. What the notice changes is applied by
     * cron from Webhook_Inbox: nothing here waits on the queue table, so a
     * burst of opens after a campaign does not turn into timeouts.
     */
    public function mailgunAction()
    {
        $rawBody = (string) $this->getRequest()->getRawBody();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            $this->getResponse()->setHttpResponseCode(400);

            return;
        }

        if (!$this->_isValidMailgunSignature((array) ($payload['signature'] ?? []))) {
            $this->getResponse()->setHttpResponseCode(401);

            return;
        }

        // Mailgun's id for the event; the signature token, unique to each
        // delivery of it, where an older payload has none
        $key = (string) ($payload['event-data']['id'] ?? '') ?: (string) ($payload['signature']['token'] ?? '');

        $this->_keep('mailgun', $key, $rawBody);
    }

    /**
     * POST /emailcampaigns/webhook/resend
     */
    public function resendAction()
    {
        $rawBody = (string) $this->getRequest()->getRawBody();

        if (!$this->_isValidResendSignature($rawBody)) {
            $this->getResponse()->setHttpResponseCode(401);

            return;
        }

        if (!is_array(json_decode($rawBody, true))) {
            $this->getResponse()->setHttpResponseCode(400);

            return;
        }

        $this->_keep('resend', (string) $this->getRequest()->getHeader('svix-id'), $rawBody);
    }

    private function _keep(string $provider, string $key, string $rawBody): void
    {
        try {
            Mage::getSingleton('ysrtech_emailcampaigns/webhook_inbox')->store($provider, $key, $rawBody);
        } catch (Throwable $e) {
            Mage::logException($e);
            // 500 rather than swallowing it: every provider retries, and a
            // notice not kept is better recovered by their retry than lost.
            $this->getResponse()->setHttpResponseCode(500);

            return;
        }

        $this->getResponse()->setHttpResponseCode(200);
    }

    /**
     * HMAC-SHA256(timestamp . token, signing key), per Mailgun's webhook
     * security documentation.
     */
    private function _isValidMailgunSignature(array $signature): bool
    {
        $key = (string) Mage::helper('ysrtech_emailcampaigns')->getSecret('sending/mailgun_webhook_signing_key');
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token     = (string) ($signature['token'] ?? '');
        $provided  = (string) ($signature['signature'] ?? '');

        if ($key === '' || $timestamp === '' || $token === '' || $provided === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::MAILGUN_TOLERANCE) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . $token, $key), $provided);
    }

    /**
     * Svix signature verification, which is what Resend delivers through. Its
     * signing secret has the same whsec_<base64> shape Svix issues directly.
     */
    private function _isValidResendSignature(string $rawBody): bool
    {
        $secret = (string) Mage::helper('ysrtech_emailcampaigns')->getSecret('sending/resend_webhook_signing_secret');

        if ($secret === '' || !str_starts_with($secret, 'whsec_')) {
            return false;
        }

        $secretBytes = base64_decode(substr($secret, strlen('whsec_')));

        $svixId        = (string) $this->getRequest()->getHeader('svix-id');
        $svixTimestamp = (string) $this->getRequest()->getHeader('svix-timestamp');
        $svixSignature = (string) $this->getRequest()->getHeader('svix-signature');

        if ($svixId === '' || $svixTimestamp === '' || $svixSignature === '') {
            return false;
        }

        if (abs(time() - (int) $svixTimestamp) > self::SVIX_TOLERANCE) {
            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha256', "{$svixId}.{$svixTimestamp}.{$rawBody}", $secretBytes, true)
        );

        // The header carries a space-separated list, so an old and a new
        // signing secret can both be live during a rotation.
        foreach (explode(' ', $svixSignature) as $part) {
            [$version, $sig] = array_pad(explode(',', $part, 2), 2, '');

            if ($version === 'v1' && $sig !== '' && hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
