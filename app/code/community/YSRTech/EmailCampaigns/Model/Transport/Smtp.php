<?php
/**
 * Sends through the store's own mail configuration rather than a provider API.
 *
 * This is the default because it is the only transport that works on a fresh
 * install: the others need an API key before they can send anything, and a
 * store running campaigns already has working transactional email. Whatever
 * that is - sendmail, an SMTP relay, or a module that intercepts Zend_Mail -
 * this goes through it.
 */
class YSRTech_EmailCampaigns_Model_Transport_Smtp
    implements YSRTech_EmailCampaigns_Model_Transport_Interface
{
    /**
     * @return array{0: string, 1: string} sender email and name
     */
    private function getSender($storeId = null): array
    {
        $identity = (string) Mage::helper('ysrtech_emailcampaigns')->getConfig('sending/identity', $storeId)
            ?: 'general';

        return [
            (string) Mage::getStoreConfig('trans_email/ident_' . $identity . '/email', $storeId),
            (string) Mage::getStoreConfig('trans_email/ident_' . $identity . '/name', $storeId),
        ];
    }

    /**
     * @inheritdoc
     *
     * $timestamp is ignored: there is nothing to hand a send-at to here. The
     * queue is what defers a campaign, and it only releases a row once it is
     * due, so by the time this is called the message is meant to go now.
     */
    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null)
    {
        list($fromEmail, $fromName) = $this->getSender();

        $mail = new Zend_Mail('utf-8');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addTo($to, isset($vars['customer']['firstname']) ? (string) $vars['customer']['firstname'] : '');
        $mail->setSubject($subject);
        $mail->setBodyHtml($html);

        if (!empty($vars['unsubscribe_url'])) {
            // One-click unsubscribe, so a recipient's mail client can offer it
            $mail->addHeader('List-Unsubscribe', '<' . $vars['unsubscribe_url'] . '>');
        }

        $mail->send();

        return null;
    }

    /**
     * @inheritdoc
     *
     * One message per recipient. There is no provider here to expand merge
     * variables, so each person gets the copy rendered for them - which is
     * also why a recipient's own html is used when the caller supplies it.
     */
    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null)
    {
        foreach ($recipients as $recipient) {
            $this->send(
                (string) $recipient['email'],
                $subject,
                isset($recipient['html']) ? (string) $recipient['html'] : $html,
                isset($recipient['vars']) ? (array) $recipient['vars'] : []
            );
        }

        return null;
    }
}
