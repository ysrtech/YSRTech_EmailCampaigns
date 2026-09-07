<?php
/**
 * Mailgun transport, using the v3 messages API.
 *
 * Sends a batch as one request: a single body carrying %recipient.x%
 * placeholders plus a map of each person's values, which Mailgun expands per
 * recipient. Twelve thousand people become a couple of dozen requests rather
 * than twelve thousand, and nobody is posted somebody else's copy.
 *
 * Unsubscribes are Mailgun's. The body carries %unsubscribe_url%, Mailgun
 * hosts the page, records the opt-out on the domain's suppression list and
 * refuses to send to that address again - no part of the store is involved in
 * the recipient's side of it.
 *
 * That rests on a domain setting rather than anything this class can send, so
 * the first post of a send checks it first. See
 * _assertUnsubscribeTrackingActive().
 */
class YSRTech_EmailCampaigns_Model_Transport_Mailgun
    implements YSRTech_EmailCampaigns_Model_Transport_Interface
{
    private const API_BASE = 'https://api.mailgun.net/v3/';

    /**
     * Mailgun accepts up to 1,000 addresses per call. Held well below that:
     * the whole batch shares one request, so a smaller number means a failure
     * costs fewer recipients a retry.
     */
    private const MAX_PER_CALL = 500;

    /** @var bool Preflight result, held for the life of the send */
    private $_unsubscribeTrackingChecked = false;

    /**
     * @inheritdoc
     */
    public function supportsRecipientVariables(): bool
    {
        return true;
    }

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
        $domain = trim((string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/mailgun_domain'));

        if ($domain === '') {
            Mage::throwException('Mailgun sending domain is not configured.');
        }

        return $domain;
    }

    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null)
    {
        return $this->sendBatch(
            [['email' => $to, 'name' => '', 'vars' => $vars]],
            $subject,
            $html,
            $timestamp
        );
    }

    /**
     * @inheritdoc
     */
    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null)
    {
        if (!$recipients) {
            return null;
        }

        $result = null;

        foreach (array_chunk($recipients, self::MAX_PER_CALL) as $chunk) {
            $result = $this->_postChunk($chunk, $subject, $html, $timestamp);
        }

        return $result;
    }

    /**
     * @param  array       $chunk
     * @param  string      $subject
     * @param  string      $html
     * @param  int|null    $timestamp
     * @return mixed
     */
    private function _postChunk(array $chunk, string $subject, string $html, ?int $timestamp)
    {
        $this->_assertUnsubscribeTrackingActive();

        $addresses = [];
        $variables = [];

        foreach ($chunk as $recipient) {
            $email = (string) $recipient['email'];
            $addresses[] = $email;
            $variables[$email] = $this->_flatten($recipient);
        }

        /*
         * Load-bearing, not defensive: Mailgun only splits a multi-recipient
         * message into one message each when recipient-variables covers them.
         * Without it every person in this list receives one email addressed to
         * all five hundred of them, with everybody's address on show.
         */
        if (count($variables) !== count($addresses)) {
            Mage::throwException('Refusing to send: every recipient must carry variables, or Mailgun exposes the list.');
        }

        $params = [
            'from'    => $this->_getFrom(),
            'to'      => implode(',', $addresses),
            'subject' => $subject,
            'html'    => $html,
            'recipient-variables' => Mage::helper('core')->jsonEncode($variables),
        ];

        $tracking = Mage::helper('ysrtech_emailcampaigns')->getConfig('tracking/enabled') ? 'yes' : 'no';
        $params['o:tracking']        = $tracking;
        $params['o:tracking-opens']  = $tracking;
        $params['o:tracking-clicks'] = $tracking === 'yes' ? 'htmlonly' : 'no';

        if ($timestamp !== null) {
            $params['o:deliverytime'] = gmdate('D, d M Y H:i:s', $timestamp) . ' +0000';
        }

        return $this->_post($params);
    }

    /**
     * Mailgun's recipient variables are a flat map read as %recipient.key%,
     * so the nested render variables have to come down to one level.
     *
     * @param  array $recipient
     * @return array
     */
    private function _flatten(array $recipient): array
    {
        $vars = isset($recipient['vars']) && is_array($recipient['vars']) ? $recipient['vars'] : [];

        $flat = [
            'email'     => (string) $recipient['email'],
            'firstname' => isset($vars['customer']['firstname']) ? (string) $vars['customer']['firstname'] : '',
            'lastname'  => isset($vars['customer']['lastname']) ? (string) $vars['customer']['lastname'] : '',
        ];

        // Anything else scalar the caller attached, one level down.
        // unsubscribe_url is skipped: it is Mailgun's own token, substituted
        // by Mailgun itself, and has no business being a recipient variable.
        foreach ($vars as $key => $value) {
            if ($key !== 'unsubscribe_url' && is_scalar($value) && !isset($flat[$key])) {
                $flat[$key] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * @return string
     */
    private function _getFrom(): string
    {
        $helper = Mage::helper('ysrtech_emailcampaigns');

        return sprintf(
            '%s <%s>',
            $helper->getConfig('sending/from_name') ?: Mage::app()->getStore()->getFrontendName(),
            $helper->getConfig('sending/from_email')
        );
    }

    /**
     * Refuse to send unless the domain is set up to handle unsubscribes.
     *
     * There is no per-message switch for this. The message options include
     * o:tracking, o:tracking-clicks and o:tracking-opens, and nothing for
     * unsubscribes: it is a domain setting, turned on in the control panel or
     * through PUT /v3/domains/{name}/tracking/unsubscribe, and it needs the
     * tracking DNS records in place first.
     *
     * The failure this guards against is quiet and expensive. With the setting
     * off, Mailgun does not substitute %unsubscribe_url% - it goes out as
     * those literal characters, so a whole campaign lands with no working way
     * to opt out. Better the send stops here with something to act on.
     *
     * @throws Mage_Core_Exception
     */
    private function _assertUnsubscribeTrackingActive(): void
    {
        // Once per instance: a campaign posts a chunk every 500 recipients
        // and the answer cannot change mid-send
        if ($this->_unsubscribeTrackingChecked) {
            return;
        }

        $domain   = $this->getDomain();
        $response = $this->_get(self::API_BASE . 'domains/' . rawurlencode($domain) . '/tracking');
        $settings = $response['tracking']['unsubscribe'] ?? [];

        if (empty($settings['active'])) {
            Mage::throwException(
                "Unsubscribe tracking is off for the Mailgun domain {$domain}, so the unsubscribe link in "
                . 'every message would be delivered as the literal text %unsubscribe_url%. Turn it on under '
                . "Sending > Domain settings > Tracking for {$domain} (the tracking DNS records have to be in "
                . 'place first) and send again.'
            );
        }

        /*
         * Mailgun appends its own footer to every message when this is set,
         * which alongside the link already in the template gives the recipient
         * two of them. Not worth stopping a send over, but worth saying.
         */
        if (trim((string) ($settings['html_footer'] ?? '')) !== '') {
            Mage::log(
                "EmailCampaigns: the Mailgun domain {$domain} has an unsubscribe footer configured, which is "
                . 'appended to every message on top of the link in the campaign template. Clear the footer under '
                . 'the domain\'s tracking settings to leave the template in charge of where the link goes.',
                Zend_Log::WARN,
                'system.log',
                // Forced: this fires once per send and describes a
                // misconfiguration that puts two unsubscribe links in front of
                // every recipient. Too costly to lose to a logging setting.
                true
            );
        }

        $this->_unsubscribeTrackingChecked = true;
    }

    /**
     * @param  string $url
     * @return array
     * @throws Mage_Core_Exception
     */
    private function _get(string $url): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $this->getApiKey(),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Mage::throwException('Mailgun request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            Mage::throwException("Mailgun error (HTTP {$status}): " . substr((string) $body, 0, 300));
        }

        $decoded = Mage::helper('core')->jsonDecode((string) $body);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array $params
     * @return mixed Decoded response
     * @throws Mage_Core_Exception
     */
    private function _post(array $params)
    {
        $url = self::API_BASE . $this->getDomain() . '/messages';
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => 'api:' . $this->getApiKey(),
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            // Thrown, so the queue records the failure and retries with backoff
            Mage::throwException('Mailgun request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            Mage::throwException("Mailgun error (HTTP {$status}): " . substr((string) $body, 0, 500));
        }

        return Mage::helper('core')->jsonDecode((string) $body);
    }
}
