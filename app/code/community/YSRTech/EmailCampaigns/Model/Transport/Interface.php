<?php
/**
 * Transport adapter contract. Implementations send one message (or a batch)
 * via their provider API and throw on failure.
 */
interface YSRTech_EmailCampaigns_Model_Transport_Interface
{
    /**
     * @param string   $to
     * @param string   $subject
     * @param string   $html
     * @param array    $vars  merge variables for provider-side templating (optional)
     * @param int|null $timestamp Unix timestamp for scheduled delivery, null = now
     * @return mixed provider message id
     */
    public function send(string $to, string $subject, string $html, array $vars = [], ?int $timestamp = null);

    /**
     * Batch send the same content to many recipients.
     *
     * @param string[][] $recipients [['email' => ..., 'name' => ..., 'vars' => [...]], ...]
     */
    public function sendBatch(array $recipients, string $subject, string $html, ?int $timestamp = null);

    /**
     * Whether this provider expands per-recipient variables itself.
     *
     * True means one message body carrying provider placeholders is posted for
     * the whole batch and the provider fills in each person's values - which is
     * what makes a twelve thousand recipient send a hundred and twenty API
     * calls instead of twelve thousand. False means every recipient needs the
     * copy already rendered for them, and sending the shared body would post
     * the first person's email, their name in the greeting, to everybody.
     */
    public function supportsRecipientVariables(): bool;
}
