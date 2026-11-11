<?php
/**
 * Everything the campaign editor shows about a campaign, worked out in one
 * place and sent to the page after every change.
 *
 * The page does not decide whether a campaign is ready, how many people it
 * reaches or what is wrong with it. It asks here, so that the answer on
 * screen and the answer the Send button gets are the same answer.
 */
class YSRTech_EmailCampaigns_Model_Campaign_Editor
{
    /**
     * @param  YSRTech_EmailCampaigns_Model_Campaign $campaign
     * @return array
     */
    public function state($campaign): array
    {
        $h        = Mage::helper('ysrtech_emailcampaigns');
        $C        = YSRTech_EmailCampaigns_Model_Campaign::class;
        $included = $campaign->getIncludedSegmentIds();
        $excluded = $campaign->getExcludedSegmentIds();
        $template = $campaign->getContentTemplate();
        $problems = [];

        // ---- To
        $recipients = null;
        $names      = [];

        if ($included) {
            foreach (Mage::getResourceModel('ysrtech_emailcampaigns/segment_collection')
                         ->addFieldToFilter('segment_id', ['in' => $included]) as $segment) {
                $names[] = (string) $segment->getName();
            }

            try {
                // Counted from membership as it stands; the queue build
                // recalculates the segments before it reads them
                $recipients = $C::countRecipients($included, $excluded, false);
            } catch (Exception $e) {
                $recipients = null;
            }

            if ($recipients === 0) {
                $problems['aud'] = $h->__('Nobody in these segments can be emailed right now.');
            }
        } else {
            $problems['aud'] = $h->__('Choose who to send to.');
        }

        // ---- From
        $domain    = $C::senderDomain();
        $fromEmail = $campaign->getFromEmail();

        if ($domain === '' || $fromEmail === '') {
            $problems['from'] = $h->__('No sending address is configured. Set Default From Email under System > Configuration > Email Campaigns > Sending.');
        } elseif (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $problems['from'] = $h->__('"%s" is not a valid sender address.', $fromEmail);
        }

        // ---- Subject
        $subject = trim((string) $campaign->getSubject());

        if ($subject === '') {
            $problems['subj'] = $h->__('Write a subject line.');
        }

        // ---- Content
        $checks  = [];
        $html    = '';
        $sizeKb  = 0;

        /** @var YSRTech_EmailCampaigns_Model_Letter $letter */
        $letter   = Mage::getSingleton('ysrtech_emailcampaigns/letter');
        $isLetter = $campaign->isLetter();

        if ($template === null) {
            $problems['content'] = $isLetter ? $h->__('Write the letter.') : $h->__('Design the email.');
        } else {
            $checks = $isLetter
                ? $letter->checks((string) $campaign->getLetterText(), $subject, (bool) $campaign->getLetterTrack())
                : Mage::getSingleton('ysrtech_emailcampaigns/template_checks')->run($template, $subject, $campaign->getStoreId());

            foreach ($checks as $check) {
                if ($check['level'] === YSRTech_EmailCampaigns_Model_Template_Checks::BAD && $check['code'] !== 'subject') {
                    $problems['content'] = $check['label'] . ': ' . $check['message'];
                    break;
                }
            }

            try {
                $html   = Mage::getSingleton('ysrtech_emailcampaigns/sender')->renderPreview($template, $campaign->getStoreId());
                $sizeKb = round(strlen($html) / 1024, 1);
            } catch (Exception $e) {
                $problems['content'] = $e->getMessage();
            }
        }

        $editable = in_array($campaign->getStatus(), [$C::STATUS_DRAFT, $C::STATUS_SCHEDULED], true);

        return [
            'id'        => (int) $campaign->getId(),
            'name'      => (string) $campaign->getName(),
            'status'    => (string) $campaign->getStatus(),
            'editable'  => $editable,
            'to'        => [
                'included'   => $included,
                'excluded'   => $excluded,
                'names'      => $names,
                'recipients' => $recipients,
            ],
            'from'      => [
                'name'         => (string) $campaign->getFromName(),
                'local'        => (string) $campaign->getFromLocal(),
                'domain'       => $domain,
                'reply_to'     => (string) $campaign->getReplyTo(),
                'display_name' => $campaign->getFromNameOrDefault(),
                'email'        => $fromEmail,
                'is_default'   => trim((string) $campaign->getFromLocal()) === '',
            ],
            'subject'   => (string) $campaign->getSubject(),
            'preheader' => (string) $campaign->getPreheader(),
            'store_id'  => (int) $campaign->getStoreId(),
            'store_name' => Mage::getSingleton('ysrtech_emailcampaigns/sender')->storeName($campaign->getStoreId() ?: null),
            'brand'     => [
                'use'     => (int) $campaign->getUseBrand() === 1,
                // 2: a complete design of its own, which nothing goes around
                // 2 and 3: a complete design of its own, which nothing goes around
                'own'     => (int) $campaign->getUseBrand() >= 2,
                'doc'     => (int) $campaign->getUseBrand() === YSRTech_EmailCampaigns_Model_Renderer::FRAME_DOC,
                'enabled' => Mage::getSingleton('ysrtech_emailcampaigns/brand')->isEnabled($campaign->getStoreId()),
            ],
            'content'   => [
                'has'     => $template !== null,
                'own'     => $campaign->hasOwnContent(),
                'size_kb' => $sizeKb,
                'checks'  => $checks,
                'html'    => $html,
                'letter'  => [
                    'is'         => $isLetter,
                    'text'       => $isLetter ? (string) $campaign->getLetterText() : '',
                    'track'      => (bool) $campaign->getLetterTrack(),
                    'own_optout' => $isLetter && $letter->hasOwnOptOut((string) $campaign->getLetterText()),
                    'optout'     => $letter->optOutLine(),
                ],
            ],
            // Once it has started, what became of it
            'report'    => $editable ? null : Mage::getSingleton('ysrtech_emailcampaigns/campaign_report')->compute($campaign),
            'when'      => [
                'scheduled_at' => $this->_local($campaign->getScheduledAt()),
                'timezone'     => (string) Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE),
            ],
            'problems'  => $problems,
            'saved_at'  => Mage::helper('core')->formatTime(null, 'short'),
        ];
    }

    /**
     * A stored UTC time as date and time in the store's timezone, for the
     * two inputs it is edited in.
     *
     * @return array{date:string, time:string, label:string}|null
     */
    protected function _local($utc)
    {
        if (!$utc) {
            return null;
        }

        $date = Mage::app()->getLocale()->date(strtotime($utc . ' UTC'), null, null, true);

        return [
            'date'  => $date->toString('yyyy-MM-dd'),
            'time'  => $date->toString('HH:mm'),
            'label' => Mage::helper('core')->formatDate($date, 'medium', true),
        ];
    }

    /**
     * A date and time typed in the store's timezone, as the UTC the
     * scheduler compares against.
     *
     * @throws Mage_Core_Exception When it is not a date and time
     */
    public function toUtc(string $date, string $time): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            Mage::throwException(Mage::helper('ysrtech_emailcampaigns')->__('Choose a date and a time.'));
        }

        $timezone = (string) Mage::app()->getStore()->getConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE) ?: 'UTC';
        $local    = new DateTime($date . ' ' . $time . ':00', new DateTimeZone($timezone));

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
