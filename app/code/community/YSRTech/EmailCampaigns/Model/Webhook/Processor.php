<?php
/**
 * What a provider's notice changes: an address suppressed, a message marked
 * delivered, opened, clicked, bounced or complained about.
 *
 * Called by Webhook_Inbox for each notice it holds. Anything thrown from
 * here leaves the notice in the inbox to be tried again.
 */
class YSRTech_EmailCampaigns_Model_Webhook_Processor
{
    /**
     * @param string $receivedAt When the notice arrived, for one that does not carry its own time
     */
    public function apply(string $provider, string $payload, string $receivedAt): void
    {
        $data = json_decode($payload, true);

        if (!is_array($data)) {
            return; // nothing in it; not something a retry would improve
        }

        if ($provider === 'mailgun') {
            $this->_mailgun($data, $receivedAt);
        } elseif ($provider === 'resend') {
            $this->_resend($data, $receivedAt);
        }
    }

    private function _mailgun(array $payload, string $receivedAt): void
    {
        $eventData = (array) ($payload['event-data'] ?? []);
        $event     = (string) ($eventData['event'] ?? '');
        $email     = trim((string) ($eventData['recipient'] ?? ''));
        $token     = (string) (((array) ($eventData['user-variables'] ?? []))['tracking_token'] ?? '');

        // When it happened, not when it was applied: this now runs a minute
        // or more after the fact
        $at = is_numeric($eventData['timestamp'] ?? null) && (float) $eventData['timestamp'] > 0
            ? gmdate('Y-m-d H:i:s', (int) $eventData['timestamp'])
            : $receivedAt;

        /*
         * A soft bounce is not a suppression: a full mailbox or a greylisting
         * relay will take the next one. Only Mailgun calling the failure
         * permanent means stop.
         */
        $isSuppression = in_array($event, ['unsubscribed', 'complained'], true)
            || ($event === 'failed' && ($eventData['severity'] ?? '') === 'permanent');

        if ($email !== '' && $isSuppression) {
            Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')
                ->suppress($email, $event, $this->_mailgunDetail($eventData), 'webhook');

            if ($event !== 'unsubscribed') {
                $this->_recordOutcome($event === 'complained' ? 'complained_at' : 'bounced_at', $token, $email, $at);
            }
        } elseif ($event === 'delivered') {
            $this->_recordOutcome('delivered_at', $token, $email, $at);
        } elseif (in_array($event, ['opened', 'clicked'], true)) {
            $this->_recordEngagement($event, $token, $email, $this->_isMachineOpen($eventData), $at, (string) ($eventData['url'] ?? ''));
        }
    }

    /**
     * Resend's transactional endpoints, which is what Transport_Resend sends
     * through, have no unsubscribe of their own, but they do report
     * deliveries, bounces and complaints. Only email.bounced is permanent -
     * a transient problem arrives as email.delivery_delayed, which is not a
     * reason to stop.
     */
    private function _resend(array $payload, string $receivedAt): void
    {
        $event      = (string) ($payload['type'] ?? '');
        $data       = (array) ($payload['data'] ?? []);
        $recipients = array_filter(
            (array) ($data['to'] ?? []),
            static fn ($v) => is_string($v) && trim($v) !== ''
        );

        $time = strtotime((string) ($payload['created_at'] ?? ''));
        $at   = $time ? gmdate('Y-m-d H:i:s', $time) : $receivedAt;

        foreach ($recipients as $email) {
            $email = trim($email);

            if (in_array($event, ['email.bounced', 'email.complained'], true)) {
                Mage::getModel('ysrtech_emailcampaigns/subscriber_pref')
                    ->suppress($email, $event, (string) ($data['bounce']['message'] ?? ''), 'webhook');
                $this->_recordOutcome($event === 'email.complained' ? 'complained_at' : 'bounced_at', '', $email, $at);
            } elseif ($event === 'email.delivered') {
                $this->_recordOutcome('delivered_at', '', $email, $at);
            }
        }
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
     * address below answers instead. A letter carries no token at all, so
     * that its headers stay those of ordinary mail.
     *
     * Falling back to the address can attribute an open to the wrong campaign
     * for the right person, when somebody has had two sends. It cannot
     * attribute it to the wrong person, which is the error that would matter.
     */
    private function _recordEngagement(string $event, string $trackingToken, string $email, bool $machine, string $at, string $url = ''): void
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
                'opened_at'  => $item->getOpenedAt() ?: $at,
            ]);

            if ($machine) {
                $item->setMachineOpenCount((int) $item->getMachineOpenCount() + 1);
            }
        } else {
            $item->addData([
                'click_count' => (int) $item->getClickCount() + 1,
                'clicked_at'  => $item->getClickedAt() ?: $at,
            ]);
        }

        $item->save();

        // Which link it was, for the report's list of links
        if ($event === 'clicked' && $url !== '') {
            Mage::getSingleton('ysrtech_emailcampaigns/conversions')->recordLinkClick([
                'queue_id'      => (int) $item->getId(),
                'campaign_id'   => $item->getCampaignId(),
                'automation_id' => $item->getAutomationId(),
            ], $url, $at);
        }
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
     * Stamp a delivery, a bounce or a complaint onto the message it was
     * about.
     *
     * The suppression list says the address is finished. This says which
     * send finished it, which is what turns into a bounce rate and a
     * complaint rate for a campaign or a week - the two figures mailbox
     * providers hold a sender to. First report only: a provider retrying the
     * webhook must not move the date.
     */
    private function _recordOutcome(string $column, string $trackingToken, string $email, string $at): void
    {
        $item = $this->_findQueueItem($trackingToken, $email);

        if ($item !== null && !$item->getData($column)) {
            $item->setData($column, $at)->save();
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
}
