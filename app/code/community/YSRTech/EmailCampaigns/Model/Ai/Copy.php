<?php
/**
 * Subject lines and inbox previews for an email that has already been
 * written: the model reads the email's words and offers a few of each.
 *
 * Only the email goes out - its text, the store's name, what is in the
 * subject box now. No recipient, no segment, no address.
 */
class YSRTech_EmailCampaigns_Model_Ai_Copy
{
    /** How much of the email's text is sent: enough to know what it is about */
    private const MAX_TEXT = 4000;

    /**
     * @return array{subjects:string[], preheaders:string[]}
     * @throws Mage_Core_Exception
     */
    public function suggest(string $html, string $subject = '', string $preheader = '', $storeId = null): array
    {
        $h    = Mage::helper('ysrtech_emailcampaigns');
        $text = self::plainText($html);

        if (mb_strlen($text) < 40) {
            Mage::throwException($h->__('Write the email first: suggestions are made from what it says.'));
        }

        $store = (string) Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($storeId);

        $system = "You write subject lines for marketing emails sent by the online store \"{$store}\".\n"
            . "Reply with ONE JSON object and nothing else: {\"subjects\": [5 strings], \"preheaders\": [3 strings]}.\n"
            . "Subjects: under 55 characters each, five different angles (plain and direct, curiosity, benefit, urgency only if the email has a real deadline, personal). "
            . "Preheaders: the inbox preview shown after the subject, 40 to 100 characters, adding to the subject instead of repeating it.\n"
            . "Rules: write in the language the email is written in. State only what the email says: no invented discounts, dates, prices or product names. "
            . "No ALL CAPS words, no more than one exclamation mark across everything, no emoji unless the email itself uses them, no spam words such as FREE!!! or Act now. "
            . "Text between {{ and }} in the email is a placeholder filled in later: you may use {{ customer.firstname|there }} in at most one subject, and no other placeholder.";

        $user = "The email:\n\n" . mb_substr($text, 0, self::MAX_TEXT)
            . ($subject !== '' ? "\n\nThe subject at the moment (offer different ones): " . mb_substr($subject, 0, 200) : '')
            . ($preheader !== '' ? "\nThe preheader at the moment: " . mb_substr($preheader, 0, 200) : '');

        $reply = Mage::getSingleton('ysrtech_emailcampaigns/ai_client')->chat(
            'subject_suggest',
            [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ['json' => true, 'max_tokens' => 700, 'temperature' => 0.8]
        );

        $json = YSRTech_EmailCampaigns_Model_Ai_Client::decodeObject($reply['text']);

        $subjects   = self::clean($json['subjects'] ?? null, 5, 120);
        $preheaders = self::clean($json['preheaders'] ?? null, 3, 200);

        if (!$subjects) {
            Mage::throwException($h->__('The AI did not return any usable suggestions this time. Try again.'));
        }

        return ['subjects' => $subjects, 'preheaders' => $preheaders];
    }

    /**
     * An email's HTML as the words a reader would see.
     */
    public static function plainText(string $html): string
    {
        // Product blocks are empty elements until send time; say what they are
        $html = (string) preg_replace('#<div\b[^>]*\bdata-ec-widget\s*=\s*"products"[^>]*\bdata-heading\s*=\s*"([^"]*)"[^>]*>[^<]*</div>#i', ' [products: $1] ', $html);
        $html = (string) preg_replace('#<(style|script|head)\b.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/h[1-6]|/tr|/li)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/', "\n", $text);

        return trim($text);
    }

    /**
     * A list of short one-line strings out of whatever the model returned.
     *
     * @return string[]
     */
    public static function clean($list, int $max, int $length): array
    {
        if (!is_array($list)) {
            return [];
        }

        $out = [];

        foreach ($list as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim((string) preg_replace('/\s+/u', ' ', strip_tags($item)), " \t\"'");

            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = mb_substr($item, 0, $length);
            }
        }

        return array_slice($out, 0, $max);
    }
}
