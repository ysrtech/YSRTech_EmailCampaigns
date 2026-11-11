<?php
/**
 * "Design with AI": a written brief becomes an editable template.
 *
 * The model chooses which sections the email has and writes the words. It
 * never writes HTML: Template_Starter builds the email from the same pieces
 * the ready-made starters use, in the store view's colours, with every word
 * escaped. Links are not the model's to choose either - every button goes to
 * the address given with the brief - and the products are real ones, drawn
 * from the catalog by a product block when the email is sent.
 *
 * What goes out: the brief, the tone, the store's name. Nothing else.
 */
class YSRTech_EmailCampaigns_Model_Ai_Designer
{
    public const TONES = [
        'friendly' => 'warm and friendly, like a shopkeeper who knows the customer',
        'plain'    => 'plain and direct, short sentences, no selling language',
        'festive'  => 'festive and celebratory, without being loud',
        'elegant'  => 'calm and elegant, few words, no exclamation marks',
        'urgent'   => 'brisk, with one clear reason to act now',
    ];

    /**
     * @return array<string, string> code => label, for the form
     */
    public static function toneLabels(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            'friendly' => $h->__('Friendly'),
            'plain'    => $h->__('Plain and direct'),
            'festive'  => $h->__('Festive'),
            'elegant'  => $h->__('Elegant'),
            'urgent'   => $h->__('Urgent'),
        ];
    }

    /**
     * @return YSRTech_EmailCampaigns_Model_Template The saved template
     * @throws Mage_Core_Exception
     */
    public function design(string $brief, string $link, string $tone, $storeId = null)
    {
        $h     = Mage::helper('ysrtech_emailcampaigns');
        $brief = trim($brief);

        if (mb_strlen($brief) < 15) {
            Mage::throwException($h->__('Describe the email in a sentence or two: what it is about, and what the reader should do.'));
        }

        $link = $this->link($link, $storeId);

        $messages = [
            ['role' => 'system', 'content' => $this->prompt($tone, $storeId)],
            ['role' => 'user', 'content' => mb_substr($brief, 0, 1500)],
        ];

        /** @var YSRTech_EmailCampaigns_Model_Ai_Client $client */
        $client = Mage::getSingleton('ysrtech_emailcampaigns/ai_client');

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $reply = $client->chat('template_design', $messages, ['json' => true, 'max_tokens' => 2000, 'temperature' => 0.6]);
            $spec  = $this->parse($reply['text']);

            if ($spec !== null) {
                return $this->create($spec, $link, $storeId);
            }

            $messages[] = ['role' => 'assistant', 'content' => mb_substr($reply['text'], 0, 6000)];
            $messages[] = ['role' => 'user', 'content' => 'That could not be used: it was not the JSON object described, or it had no usable sections. Reply again with the corrected JSON object only.'];
        }

        Mage::throwException($h->__('The AI could not produce a usable design this time. Try again, or describe the email a little differently.'));
    }

    public function prompt(string $tone, $storeId = null): string
    {
        $store = (string) Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($storeId);
        $tone  = self::TONES[$tone] ?? self::TONES['friendly'];

        return "You plan marketing emails for the online store \"{$store}\". Tone: {$tone}.\n"
            . "Reply with ONE JSON object and nothing else: {\"name\": short template name, \"subject\": subject line under 55 characters, "
            . "\"preheader\": inbox preview of 40 to 100 characters, \"sections\": [...]}.\n"
            . "Sections, in reading order, 3 to 7 of them. The store's header with its logo and the legal footer with the unsubscribe link are added automatically: do not write either.\n"
            . "- {\"type\": \"hero\", \"heading\": up to 60 characters, \"text\": one line under it, optional}\n"
            . "- {\"type\": \"text\", \"heading\": optional, \"text\": one to three short paragraphs separated by a blank line}\n"
            . "- {\"type\": \"button\", \"label\": up to 30 characters}\n"
            . "- {\"type\": \"coupon\", \"code\": the code, \"note\": one line of terms, optional}\n"
            . "- {\"type\": \"products\", \"show\": \"new\" or \"sale\", \"heading\": optional, \"count\": 2, 3, 4 or 6}  (the store's real products are placed here automatically: do not name or price any)\n"
            . "- {\"type\": \"divider\"}\n"
            . "Rules: start with a hero. Use exactly one button, or two when the email is long. Use a products section only when the brief is about products, new arrivals or a sale, and a coupon section only when the brief gives a code. "
            . "Text is plain: no HTML, no markdown. Write in the language of the brief. "
            . "State only facts from the brief: when a detail is missing (a date, a discount, a code), write a clear placeholder in square brackets, like [date] or [code], for the sender to fill in. "
            . "Greet the reader with {{ customer.firstname|there }} at most once, in the first text section; use no other placeholder.";
    }

    /**
     * The model's reply as a spec Template_Starter can build, or null when
     * there is nothing usable in it. Every value is cut to length and typed
     * here; escaping happens where the HTML is made.
     *
     * @return array{name:string, subject:string, preheader:string, sections:array<int, array<string, mixed>>}|null
     */
    public function parse(string $text): ?array
    {
        $json = YSRTech_EmailCampaigns_Model_Ai_Client::decodeObject($text);

        if (!$json || !is_array($json['sections'] ?? null)) {
            return null;
        }

        $line = static fn ($v, int $max) => is_scalar($v) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $v))), 0, $max) : '';
        $para = static fn ($v, int $max) => is_scalar($v) ? mb_substr(trim(strip_tags((string) $v)), 0, $max) : '';

        $sections = [];

        foreach ($json['sections'] as $s) {
            if (!is_array($s) || count($sections) >= 8) {
                continue;
            }

            switch ((string) ($s['type'] ?? '')) {
                case 'hero':
                    if (($heading = $line($s['heading'] ?? '', 90)) !== '') {
                        $sections[] = ['type' => 'hero', 'heading' => $heading, 'text' => $line($s['text'] ?? '', 200)];
                    }
                    break;

                case 'text':
                    if (($body = $para($s['text'] ?? '', 1500)) !== '') {
                        $sections[] = ['type' => 'text', 'heading' => $line($s['heading'] ?? '', 90), 'text' => $body];
                    }
                    break;

                case 'button':
                    if (($label = $line($s['label'] ?? '', 40)) !== '') {
                        $sections[] = ['type' => 'button', 'label' => $label];
                    }
                    break;

                case 'coupon':
                    $code = strtoupper((string) preg_replace('/[^A-Za-z0-9_\[\]-]/', '', (string) (is_scalar($s['code'] ?? null) ? $s['code'] : '')));

                    if ($code !== '') {
                        $sections[] = ['type' => 'coupon', 'code' => mb_substr($code, 0, 30), 'note' => $line($s['note'] ?? '', 160)];
                    }
                    break;

                case 'products':
                    $count = (int) ($s['count'] ?? 4);

                    $sections[] = [
                        'type'    => 'products',
                        'show'    => ($s['show'] ?? '') === 'sale' ? 'sale' : 'new',
                        'heading' => $line($s['heading'] ?? '', 90),
                        'count'   => in_array($count, [2, 3, 4, 6], true) ? $count : 4,
                    ];
                    break;

                case 'divider':
                    $sections[] = ['type' => 'divider'];
                    break;
            }
        }

        // A design is something to read: dividers alone are not one
        if (!array_filter($sections, static fn ($s) => in_array($s['type'], ['hero', 'text'], true))) {
            return null;
        }

        return [
            'name'      => $line($json['name'] ?? '', 120) ?: Mage::helper('ysrtech_emailcampaigns')->__('AI design'),
            'subject'   => $line($json['subject'] ?? '', 255),
            'preheader' => $line($json['preheader'] ?? '', 255),
            'sections'  => $sections,
        ];
    }

    /**
     * Save a parsed spec as a template.
     *
     * @return YSRTech_EmailCampaigns_Model_Template
     */
    public function create(array $spec, string $link, $storeId = null)
    {
        /** @var YSRTech_EmailCampaigns_Model_Design_Doc $docs */
        $docs = Mage::getModel('ysrtech_emailcampaigns/design_doc');

        return Mage::getModel('ysrtech_emailcampaigns/template')
            ->setData([
                'name'      => $spec['name'],
                'subject'   => $spec['subject'],
                'preheader' => $spec['preheader'] !== '' ? $spec['preheader'] : null,
                'is_active' => 1,
            ])
            ->setDoc($docs->fromSections($spec['sections'], $link, $storeId), $storeId)
            ->save();
    }

    /**
     * Where every button goes: the address given, when it is on one of this
     * install's own stores; the store's front page otherwise.
     */
    public function link(string $link, $storeId = null): string
    {
        $store = $storeId ? Mage::app()->getStore($storeId) : Mage::app()->getDefaultStoreView();
        $home  = (string) $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
        $link  = trim($link);

        if ($link === '') {
            return $home;
        }

        if (!preg_match('#^https?://#i', $link)) {
            return rtrim($home, '/') . '/' . ltrim($link, '/');
        }

        $host = strtolower((string) parse_url($link, PHP_URL_HOST));

        foreach (Mage::app()->getStores() as $candidate) {
            if ($host !== '' && $host === strtolower((string) parse_url((string) $candidate->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK), PHP_URL_HOST))) {
                return $link;
            }
        }

        Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('The link has to be a page on this store. Leave it empty to use the front page.'));
    }
}
