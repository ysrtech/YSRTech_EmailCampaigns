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
            $userVariables = (array) ($eventData['user-variables'] ?? []);
            $token         = (string) ($userVariables['tracking_token'] ?? '');

            if ($email !== '' && $isSuppression) {
                Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')
                    ->suppress($email, $event, $this->_mailgunDetail($eventData), 'webhook');

                if ($event !== 'unsubscribed') {
                    $this->_recordOutcome($event === 'complained' ? 'complained_at' : 'bounced_at', $token, $email);
                }
            } elseif (in_array($event, ['opened', 'clicked'], true)) {
                $this->_recordEngagement($event, $token, $email, $this->_isMachineOpen($eventData));
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
                $detail = (string) ($data['bounce']['message'] ?? '');

                foreach ($recipients as $email) {
                    Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')
                        ->suppress($email, $event, $detail, 'webhook');
                    $this->_recordOutcome(
                        $event === 'email.complained' ? 'complained_at' : 'bounced_at',
                        '',
                        trim($email)
                    );
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
    private function _recordEngagement(string $event, string $trackingToken, string $email, bool $machine = false): void
    {
        $item = $this->_findQueueItem($trackingToken, $email);

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

            if ($machine) {
                $item->setMachineOpenCount((int) $item->getMachineOpenCount() + 1);
            }
        } else {
            $item->addData([
                'click_count' => (int) $item->getClickCount() + 1,
                'clicked_at'  => $item->getClickedAt() ?: Varien_Date::now(),
            ]);
        }

        $item->save();
    }

    /**
     * The queue row an event is about: by token where the provider handed it
     * back, by the most recent send to the address where it did not.
     *
     * @return YSRTech_EmailCampaigns_Model_Queue|null
     */
    private function _findQueueItem(string $trackingToken, string $email)
    {
        if ($trackingToken !== '') {
            /** @var YSRTech_EmailCampaigns_Model_Queue $candidate */
            $candidate = Mage::getModel('ysrtech_emailcampaigns/queue')->load($trackingToken, 'tracking_token');

            if ($candidate->getId()) {
                return $candidate;
            }
        }

        return $email !== '' ? $this->_latestSentTo($email) : null;
    }

    /**
     * Stamp a bounce or a complaint onto the message it was about.
     *
     * The suppression list says the address is finished. This says which
     * send finished it, which is what turns into a bounce rate and a
     * complaint rate for a campaign or a week - the two figures mailbox
     * providers hold a sender to. First report only: a provider retrying the
     * webhook must not move the date.
     */
    private function _recordOutcome(string $column, string $trackingToken, string $email): void
    {
        $item = $this->_findQueueItem($trackingToken, $email);

        if ($item !== null && !$item->getData($column)) {
            $item->setData($column, Varien_Date::now())->save();
        }
    }

    /**
     * What Mailgun said about a failure, short enough to read in a grid.
     */
    private function _mailgunDetail(array $eventData): string
    {
        $status = (array) ($eventData['delivery-status'] ?? []);
        $text   = trim((string) ($status['message'] ?? '')) ?: trim((string) ($status['description'] ?? ''));
        $code   = (string) ($status['code'] ?? '');

        if ($text === '') {
            $text = trim((string) ($eventData['reason'] ?? ''));
        }

        return trim(($code !== '' && strpos($text, $code) !== 0 ? $code . ' ' : '') . $text);
    }

    /**
     * Whether an open was a mail client fetching images by itself.
     *
     * Apple's Mail Privacy Protection loads every image of every message
     * through a proxy whether or not anybody reads it, and Gmail's image
     * proxy prefetches some. Counted as opens they make every campaign look
     * read by people who never saw it. Mailgun marks the ones it recognises
     * in client-info.bot; the bare "Mozilla/5.0" agent is what Apple's proxy
     * sends, for when that field is absent.
     */
    private function _isMachineOpen(array $eventData): bool
    {
        $client = (array) ($eventData['client-info'] ?? []);

        if (trim((string) ($client['bot'] ?? '')) !== '') {
            return true;
        }

        return trim((string) ($client['user-agent'] ?? '')) === 'Mozilla/5.0';
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
