<?php
/**
 * The things worth knowing about an email before it goes to a list, each
 * with a verdict: ok, warn or bad.
 *
 * Run on the message as it will actually be sent - rendered, with the
 * unsubscribe footer and the document wrapper in place - because that is
 * what Gmail measures and what a spam filter reads. Only "bad" should stop a
 * send; a warning is something a person may well have meant.
 */
class YSRTech_EmailCampaigns_Model_Template_Checks
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const BAD  = 'bad';

    /** Gmail hides everything past this many bytes of HTML behind "View entire message" */
    public const GMAIL_CLIP_KB = 102;
    public const GMAIL_WARN_KB = 90;

    /**
     * @param  YSRTech_EmailCampaigns_Model_Template $template
     * @param  string|null $subject The campaign's subject, when checking for a campaign
     * @return array<int, array{code:string, label:string, level:string, message:string}>
     */
    public function run($template, ?string $subject = null, $storeId = null): array
    {
        $h   = Mage::helper('ysrtech_emailcampaigns');
        $raw = (string) $template->getHtml();

        try {
            $html = Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template, $storeId);
        } catch (Exception $e) {
            return [[
                'code' => 'content', 'label' => $h->__('Content'), 'level' => self::BAD,
                'message' => $h->__('The template has no content to send.'),
            ]];
        }

        $checks = [];
        $add    = static function (string $code, string $label, string $level, string $message) use (&$checks) {
            $checks[] = ['code' => $code, 'label' => $label, 'level' => $level, 'message' => $message];
        };

        // Subject
        $subject = trim($subject ?? (string) $template->getSubject());
        $add('subject', $h->__('Subject'), $subject === '' ? self::BAD : (mb_strlen($subject) > 70 ? self::WARN : self::OK),
            $subject === '' ? $h->__('There is no subject line.')
                : (mb_strlen($subject) > 70 ? $h->__('%d characters: most inboxes cut it off after about 60.', mb_strlen($subject))
                    : $h->__('%d characters.', mb_strlen($subject))));

        // Size
        $kb = strlen($html) / 1024;
        $add('size', $h->__('Gmail clipping'),
            $kb < self::GMAIL_WARN_KB ? self::OK : ($kb < self::GMAIL_CLIP_KB ? self::WARN : self::BAD),
            $h->__('%s KB of HTML. Gmail hides everything after %d KB behind a link, the unsubscribe footer included.', number_format($kb, 1), self::GMAIL_CLIP_KB));

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        // Images
        $images = [];
        $noAlt  = 0;

        foreach ($dom->getElementsByTagName('img') as $img) {
            // A 1x1 is a tracking pixel, not a picture
            if ((int) $img->getAttribute('width') === 1 || (int) $img->getAttribute('height') === 1) {
                continue;
            }

            $images[] = $img;

            if (trim($img->getAttribute('alt')) === '') {
                $noAlt++;
            }
        }

        $add('alt', $h->__('Alt text on images'), $noAlt ? self::WARN : self::OK,
            $noAlt ? $h->__('%d image(s) have no alt text. Many inboxes block images until asked, and show the alt text instead.', $noAlt)
                : ($images ? $h->__('Every image has alt text.') : $h->__('No images.')));

        // Words to images
        foreach (['style', 'script', 'head'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        $words = str_word_count(preg_replace('/\s+/u', ' ', (string) $dom->textContent));
        $heavy = count($images) > 1 && $words / count($images) < 25;
        $add('ratio', $h->__('Text and images'), $heavy ? self::WARN : self::OK,
            $h->__('%d words, %d image(s).', $words, count($images))
            . ($heavy ? ' ' . $h->__('Mostly pictures: spam filters distrust that, and it reads as nothing with images off. Add some text.') : ''));

        // Unsubscribe and address
        $hasOwnLink = (bool) preg_match('/{{\s*unsubscribe(_url)?\s*}}/', $raw);
        $address    = trim((string) Mage::getStoreConfig('general/store_information/address', $storeId));
        $firstLine  = trim((string) strtok($address, "\n"));
        $text       = html_entity_decode((string) $dom->textContent, ENT_QUOTES, 'UTF-8');

        if ($address === '') {
            $add('footer', $h->__('Unsubscribe link and address'), self::BAD,
                $h->__('The store has no postal address (System > Configuration > General > Store Information). Marketing email has to carry one by law.'));
        } elseif ($firstLine !== '' && mb_stripos($text, $firstLine) === false) {
            $add('footer', $h->__('Unsubscribe link and address'), self::WARN,
                $h->__('The unsubscribe link is there, but the store\'s postal address is not in the email. The law in most places asks for both.'));
        } else {
            $add('footer', $h->__('Unsubscribe link and address'), self::OK,
                (int) $template->getUseBrand() === 1 && Mage::getSingleton('ysrtech_emailcampaigns/brand')->isEnabled($storeId)
                    ? $h->__('Both are in the store\'s footer.')
                    : ($hasOwnLink ? $h->__('Has both.') : $h->__('Has the address; the unsubscribe footer is added at the end automatically.')));
        }

        // Links
        $dead = 0;

        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));

            // The preview stands "#preview-unsubscribe-link" in for the real one
            if ($href === '#preview-unsubscribe-link') {
                continue;
            }

            if ($href === '' || $href[0] === '#' || preg_match('#^(https?://)?$#i', $href)
                || stripos($href, 'javascript:') === 0 || stripos($href, 'example.com') !== false
            ) {
                $dead++;
            }
        }

        $add('links', $h->__('Links'), $dead ? self::WARN : self::OK,
            $dead ? $h->__('%d link(s) do not go anywhere yet.', $dead) : $h->__('Every link points somewhere.'));

        // Merge tags without a fallback
        preg_match_all('/{{\s*customer\.(firstname|lastname)\s*}}/', $raw . ' ' . $subject, $bare);
        $add('fallback', $h->__('Personalisation'), $bare[0] ? self::WARN : self::OK,
            $bare[0] ? $h->__('%d name placeholder(s) have no fallback. A subscriber with no name on file gets "Hi ," - write {{ customer.firstname|there }}.', count($bare[0]))
                : $h->__('Name placeholders have fallbacks.'));

        // Preview text
        $preheader = trim((string) $template->getPreheader());
        $add('preheader', $h->__('Preview text'), $preheader === '' ? self::WARN : self::OK,
            $preheader === '' ? $h->__('Empty: inboxes will show the first words of the email after the subject. Set it on the template.')
                : $h->__('Set.'));

        return $checks;
    }

    /**
     * The worst level among a set of checks.
     */
    public function worst(array $checks): string
    {
        $levels = array_column($checks, 'level');

        return in_array(self::BAD, $levels, true) ? self::BAD : (in_array(self::WARN, $levels, true) ? self::WARN : self::OK);
    }
}
