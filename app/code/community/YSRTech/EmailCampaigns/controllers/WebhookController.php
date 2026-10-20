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
 * verifies the provider's signature before it is allowed to change anything.
 */
class YSRTech_EmailCampaigns_WebhookController extends Mage_Core_Controller_Front_Action
{
    /** Mailgun's own guidance on rejecting stale timestamps */
    private const MAILGUN_TOLERANCE = 900;

    /** Svix's recommended tolerance */
    private const SVIX_TOLERANCE = 300;

    /**
     * POST /emailcampaigns/webhook/mailgun
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
        $event     = (string) ($eventData['event'] ?? '');
        $email     = trim((string) ($eventData['recipient'] ?? ''));

        /*
         * A soft bounce is not a suppression: a full mailbox or a greylisting
         * relay will take the next one. Only Mailgun calling the failure
         * permanent means stop.
         */
        $isSuppression = in_array($event, ['unsubscribed', 'complained'], true)
            || ($event === 'failed' && ($eventData['severity'] ?? '') === 'permanent');

        try {
            if ($email !== '' && $isSuppression) {
                Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')->suppress($email, $event);
            } elseif (in_array($event, ['opened', 'clicked'], true)) {
                $userVariables = (array) ($eventData['user-variables'] ?? []);
                $this->_recordEngagement(
                    $event,
                    (string) ($userVariables['tracking_token'] ?? ''),
                    $email
                );
            }
        } catch (Exception $e) {
            Mage::logException($e);
            // 500 rather than swallowing it: every provider retries, and a
            // row not written is better recovered by their retry than lost.
            $this->getResponse()->setHttpResponseCode(500);

            return;
        }

        $this->getResponse()->setHttpResponseCode(200);
    }

    /**
     * POST /emailcampaigns/webhook/resend
     *
     * Resend's transactional endpoints, which is what Transport_Resend sends
     * through, have no unsubscribe of their own, but they do report bounces
     * and complaints. Only email.bounced is permanent - a transient problem
     * arrives as email.delivery_delayed, which is not a reason to stop.
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

        $event      = (string) ($payload['type'] ?? '');
        $data       = (array) ($payload['data'] ?? []);
        $recipients = array_filter(
            (array) ($data['to'] ?? []),
            static fn ($v) => is_string($v) && trim($v) !== ''
        );

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
     * Record an open or a click against the queue row it belongs to.
     *
     * The token is tried first and the recipient address second, because the
     * token cannot be relied on alone. Mailgun's documentation covers
     * %recipient.x% substitution in the subject, the body and the To header,
     * but says nothing about substituting it inside a v: custom variable -
     * and a campaign goes out five hundred recipients to a message, so a
     * message-level v:tracking_token would be one value for all of them. It
     * is sent anyway: if the substitution does happen the exact row is found,
     * and if it does not the literal placeholder matches no row and the
     * address below answers instead.
     *
     * Falling back to the address can attribute an open to the wrong campaign
     * for the right person, when somebody has had two sends. It cannot
     * attribute it to the wrong person, which is the error that would matter.
     */
    private function _recordEngagement(string $event, string $trackingToken, string $email): void
    {
        $item = null;

        if ($trackingToken !== '') {
            /** @var YSRTech_EmailCampaigns_Model_Queue $candidate */
            $candidate = Mage::getModel('ysrtech_emailcampaigns/queue')->load($trackingToken, 'tracking_token');
            $item = $candidate->getId() ? $candidate : null;
        }

        if ($item === null && $email !== '') {
            $item = $this->_latestSentTo($email);
        }

        if ($item === null) {
            return;
        }

        // addData merges; setData with an array would replace the whole row
        // and turn the save into an insert.
        if ($event === 'opened') {
            $item->addData([
                'open_count' => (int) $item->getOpenCount() + 1,
                // First open only: "when did they open it" is the useful
                // question, and the count carries the rest.
                'opened_at'  => $item->getOpenedAt() ?: Varien_Date::now(),
            ]);
        } else {
            $item->addData([
                'click_count' => (int) $item->getClickCount() + 1,
                'clicked_at'  => $item->getClickedAt() ?: Varien_Date::now(),
            ]);
        }

        $item->save();
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Queue|null
     */
    private function _latestSentTo(string $email)
    {
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/queue_collection')
            ->addFieldToFilter('email', $email)
            ->addFieldToFilter('status', 'sent')
            ->setOrder('sent_at', 'DESC')
            ->setPageSize(1);

        $item = $collection->getFirstItem();

        return $item->getId() ? $item : null;
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
