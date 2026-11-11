<?php
/**
 * A campaign written as a letter: plain text, the way one person writes to
 * another, instead of a designed email.
 *
 * It goes out as text and nothing else. Mailbox providers sort mail partly
 * on what it looks like, and a message with no HTML, no images, no tracking
 * and none of a bulk mailing's headers looks like correspondence. That is
 * the point of the format, and also its limit: nothing here can promise
 * which tab a provider files it under.
 *
 * When opens and clicks are to be counted the letter needs an HTML copy,
 * because both are counted through HTML. The copy is the same words in
 * paragraphs with no styling at all, so it still reads as typed.
 *
 * The way out is in the words: a line at the end with the reader's own
 * unsubscribe address, unless the writer has placed {{ unsubscribe_url }}
 * themselves.
 */
class YSRTech_EmailCampaigns_Model_Letter
{
    public const FORMAT = 'letter';

    /**
     * The letter's text for one reader, or with a provider's placeholders
     * left in for it to fill.
     *
     * @param array $vars customer.*, store.*, unsubscribe_url
     */
    public function text(string $body, array $vars): string
    {
        $body = $this->_withOptOut($this->_normalise($body));

        // A letter is written by hand; the HTML footer token has no place
        // in it, but somebody used to templates may type it all the same
        $body = str_replace('{{unsubscribe}}', '{{ unsubscribe_url }}', $body);

        return Mage::helper('ysrtech_emailcampaigns')->renderMergeVars($body, $vars);
    }

    /**
     * The same words as the plainest HTML there is: paragraphs, line breaks
     * and links, in the reader's own default font.
     */
    public function html(string $text): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim($text)) ?: [];
        $html       = '';

        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . nl2br($this->_linkify(htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8')), false) . "</p>\n";
        }

        return "<html><body>\n" . $html . '</body></html>';
    }

    /**
     * Whether the writer has put the way out in the letter themselves.
     */
    public function hasOwnOptOut(string $body): bool
    {
        return (bool) preg_match('/\{\{\s*unsubscribe(_url)?\s*\}\}/', $body);
    }

    /**
     * The closing line added to a letter that has none of its own.
     */
    public function optOutLine(): string
    {
        return Mage::helper('ysrtech_emailcampaigns')->__('If you would rather not get these emails, you can unsubscribe here: %s', '{{ unsubscribe_url }}');
    }

    /**
     * What is wrong or worth knowing about a letter, in the same shape as
     * Template_Checks, for the same list on the campaign page.
     *
     * @return array<int, array{code:string, label:string, level:string, message:string}>
     */
    public function checks(string $body, string $subject, bool $tracked): array
    {
        $h      = Mage::helper('ysrtech_emailcampaigns');
        $T      = YSRTech_EmailCampaigns_Model_Template_Checks::class;
        $body   = $this->_normalise($body);
        $checks = [];
        $add    = static function (string $code, string $label, string $level, string $message) use (&$checks) {
            $checks[] = ['code' => $code, 'label' => $label, 'level' => $level, 'message' => $message];
        };

        $shown = mb_strlen(Mage::getSingleton('ysrtech_emailcampaigns/sender')->asSeen($subject));
        $add('subject', $h->__('Subject'), $subject === '' ? $T::BAD : ($shown > 70 ? $T::WARN : $T::OK),
            $subject === '' ? $h->__('There is no subject line.')
                : ($shown > 70 ? $h->__('%d characters: most inboxes cut it off after about 60.', $shown) : $h->__('%d characters.', $shown)));

        if ($body === '') {
            $add('content', $h->__('Content'), $T::BAD, $h->__('The letter has no words yet.'));

            return $checks;
        }

        $words = count(preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $add('length', $h->__('Length'), $words > 400 ? $T::WARN : $T::OK,
            $words > 400 ? $h->__('%d words. A letter this long reads as a newsletter; a designed email may suit it better.', $words) : $h->__('%d words.', $words));

        $add('unsubscribe', $h->__('Way to unsubscribe'), $T::OK, $this->hasOwnOptOut($body)
            ? $h->__('In your own words, where you placed it.')
            : $h->__('A closing line with the reader\'s unsubscribe link is added for you.'));

        if (preg_match('/<\s*(a|p|br|div|span|b|i|strong|em|img|table)\b[^>]*>/i', $body)) {
            $add('html', $h->__('HTML'), $T::WARN, $h->__('There are HTML tags in the letter. They are sent as typed, and the reader will see them.'));
        }

        $links = preg_match_all('#https?://#i', $body);
        $add('links', $h->__('Links'), $links > 3 ? $T::WARN : $T::OK, $links > 3
            ? $h->__('%d links. More than two or three makes a letter look like a promotion.', $links)
            : ($links ? $h->__('%d, written out in full.', $links) : $h->__('None.')));

        $add('tracking', $h->__('Tracking'), $T::OK, $tracked
            ? $h->__('Opens and clicks are counted, so a plain-looking HTML copy is sent with the text.')
            : $h->__('Off: text only. Deliveries, bounces and unsubscribes are still reported; opens and clicks are not.'));

        return $checks;
    }

    private function _withOptOut(string $body): string
    {
        return $this->hasOwnOptOut($body) ? $body : rtrim($body) . "\n\n" . $this->optOutLine();
    }

    private function _normalise(string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $body));
    }

    /**
     * Addresses in already-escaped text, as links. A provider's own
     * placeholder (%recipient.x%) may sit inside one and is kept whole.
     */
    private function _linkify(string $escaped): string
    {
        return (string) preg_replace_callback(
            '#https?://[^\s<]+#i',
            static function (array $m) {
                // Punctuation that ends the sentence, not the address
                $url  = rtrim($m[0], '.,;:!?)');
                $tail = substr($m[0], strlen($url));

                return '<a href="' . $url . '">' . $url . '</a>' . $tail;
            },
            $escaped
        );
    }
}
