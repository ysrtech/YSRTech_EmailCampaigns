<?php
/**
 * One address that must not be mailed, with why and since when.
 *
 * The rows are written through the resource model's add() and addMany(),
 * never by saving one of these directly: suppressing an address is three
 * writes - this table, the newsletter status the rest of the store reads, and
 * whatever is already queued for the address - and doing one without the
 * others is the bug.
 */
class YSRTech_EmailCampaigns_Model_Suppression extends Mage_Core_Model_Abstract
{
    public const REASON_HARD_BOUNCE = 'hard_bounce';
    public const REASON_COMPLAINT   = 'complaint';
    public const REASON_UNSUBSCRIBE = 'unsubscribe';
    public const REASON_MANUAL      = 'manual';

    /** The most that can be pasted or uploaded in one go */
    public const MAX_BULK = 10000;

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/suppression');
    }

    /**
     * @return array<string, string> reason => label
     */
    public static function reasons(): array
    {
        $h = Mage::helper('ysrtech_emailcampaigns');

        return [
            self::REASON_HARD_BOUNCE => $h->__('Hard bounce'),
            self::REASON_COMPLAINT   => $h->__('Spam complaint'),
            self::REASON_UNSUBSCRIBE => $h->__('Unsubscribed'),
            self::REASON_MANUAL      => $h->__('Added by hand'),
        ];
    }

    /**
     * How much a reason outranks another, for when an address already on the
     * list turns up again with a different one. A complaint about an address
     * that was added by hand is a complaint; an unsubscribe from somebody who
     * once complained is still a complaint.
     */
    public static function rank(string $reason): int
    {
        return [
            self::REASON_MANUAL      => 1,
            self::REASON_UNSUBSCRIBE => 2,
            self::REASON_HARD_BOUNCE => 3,
            self::REASON_COMPLAINT   => 4,
        ][$reason] ?? 0;
    }

    /**
     * The reason for a provider's event name, or for one of ours.
     *
     * Mailgun says "failed", "complained", "unsubscribed"; Resend says
     * "email.bounced", "email.complained". Anything unrecognised is an
     * unsubscribe, which is what every caller that passes nothing means.
     */
    public static function reasonFor(string $event): string
    {
        $event = strtolower(trim($event));

        if (isset(self::reasons()[$event])) {
            return $event;
        }

        return [
            'failed'           => self::REASON_HARD_BOUNCE,
            'bounced'          => self::REASON_HARD_BOUNCE,
            'bounces'          => self::REASON_HARD_BOUNCE,
            'email.bounced'    => self::REASON_HARD_BOUNCE,
            'complained'       => self::REASON_COMPLAINT,
            'complaints'       => self::REASON_COMPLAINT,
            'email.complained' => self::REASON_COMPLAINT,
        ][$event] ?? self::REASON_UNSUBSCRIBE;
    }

    /**
     * Every address in a blob of text: a pasted column, a CSV export with
     * other things in it, a forwarded complaint.
     *
     * @return array{0: string[], 1: int} Unique lower-cased addresses, and how
     *                                    many address-shaped things were not
     *                                    valid ones
     */
    public static function extractEmails(string $text): array
    {
        preg_match_all('/[^\s,;"\'<>]+@[^\s,;"\'<>]+\.[A-Za-z]{2,}/', $text, $matches);

        $valid   = [];
        $invalid = 0;

        foreach ($matches[0] as $candidate) {
            $candidate = strtolower(trim($candidate, ".:()[]"));

            if (strlen($candidate) <= 255 && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $valid[$candidate] = true;
            } else {
                $invalid++;
            }
        }

        return [array_keys($valid), $invalid];
    }
}
