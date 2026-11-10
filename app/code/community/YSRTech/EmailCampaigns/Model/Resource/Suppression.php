<?php
/**
 * Where an address is suppressed and unsuppressed.
 *
 * In sets rather than one at a time, because the callers that matter work in
 * thousands: the hourly Mailgun sync, an import, a pasted list.
 */
class YSRTech_EmailCampaigns_Model_Resource_Suppression extends YSRTech_EmailCampaigns_Model_Resource_Abstract
{
    private const CHUNK = 500;

    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/suppression', 'suppression_id');
    }

    /**
     * Suppress one address.
     *
     * @return bool Whether it was newly added
     */
    public function add(string $email, string $reason, string $detail = '', string $source = ''): bool
    {
        $result = $this->addMany([$email => $detail], $reason, $source);

        return $result['added'] > 0;
    }

    /**
     * Suppress a set of addresses for one reason.
     *
     * Idempotent: an address already here is left as it was, except that a
     * stronger reason replaces a weaker one. Every provider retries its
     * webhooks, and the sync re-reads the whole of Mailgun's lists each hour.
     *
     * @param  array  $emails Either a list of addresses, or address => detail
     * @return array{added:int, already:int}
     */
    public function addMany(array $emails, string $reason, string $source = ''): array
    {
        $reason = YSRTech_EmailCampaigns_Model_Suppression::reasonFor($reason);
        $rank   = YSRTech_EmailCampaigns_Model_Suppression::rank($reason);

        // Normalise to address => detail, lower-cased, without blanks
        $details = [];

        foreach ($emails as $key => $value) {
            [$email, $detail] = is_int($key) ? [$value, ''] : [$key, $value];
            $email = strtolower(trim((string) $email));

            if ($email !== '' && strlen($email) <= 255) {
                $details[$email] = (string) $detail;
            }
        }

        $adapter     = $this->_getWriteAdapter();
        $table       = $this->getMainTable();
        $subscribers = $this->getTable('newsletter/subscriber');
        $queue       = $this->getTable('ysrtech_emailcampaigns/queue');
        $now         = Varien_Date::now();
        $added       = 0;
        $already     = 0;

        foreach (array_chunk(array_keys($details), self::CHUNK) as $chunk) {
            $existing = $adapter->fetchPairs(
                $adapter->select()->from($table, ['email', 'reason'])->where('email IN (?)', $chunk)
            );
            $existing = array_change_key_case($existing, CASE_LOWER);

            // As it stands now, so that lifting the suppression later can put
            // back exactly what this is about to overwrite.
            $statuses = array_change_key_case($adapter->fetchPairs(
                $adapter->select()
                    ->from($subscribers, ['subscriber_email', 'subscriber_status'])
                    ->where('subscriber_email IN (?)', $chunk)
            ), CASE_LOWER);

            $insert  = [];
            $upgrade = [];

            foreach ($chunk as $email) {
                if (isset($existing[$email])) {
                    $already++;

                    if ($rank > YSRTech_EmailCampaigns_Model_Suppression::rank((string) $existing[$email])) {
                        $upgrade[] = $email;
                    }

                    continue;
                }

                $insert[] = [
                    'email'                  => $email,
                    'reason'                 => $reason,
                    'detail'                 => $details[$email] !== '' ? mb_substr($details[$email], 0, 500) : null,
                    'source'                 => $source !== '' ? substr($source, 0, 32) : null,
                    'prev_subscriber_status' => isset($statuses[$email]) ? (int) $statuses[$email] : null,
                    'created_at'             => $now,
                ];
            }

            if ($insert) {
                // On duplicate rather than a plain insert: two webhooks for
                // the same address can pass the read above together.
                $adapter->insertOnDuplicate($table, $insert, ['email']);
                $added += count($insert);
            }

            foreach ($upgrade as $email) {
                $bind = ['reason' => $reason, 'source' => $source !== '' ? substr($source, 0, 32) : null];

                if ($details[$email] !== '') {
                    $bind['detail'] = mb_substr($details[$email], 0, 500);
                }

                $adapter->update($table, $bind, ['email = ?' => $email]);
            }

            /*
             * Magento's own newsletter status, which is what the rest of the
             * store reads: the admin's Newsletter Subscribers grid, the
             * customer's account page, and the campaign queue builder.
             */
            $adapter->update(
                $subscribers,
                [
                    'subscriber_status' => Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED,
                    'change_status_at'  => $now,
                ],
                [
                    'subscriber_email IN (?)'  => $chunk,
                    'subscriber_status != ?'   => Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED,
                ]
            );

            /*
             * Anything already queued goes now, or the suppression only
             * covers what is queued afterwards. Pending only: a row in
             * "sending" is claimed by a run that may be posting it as this
             * writes. One more message goes out; the next does not.
             */
            $adapter->update(
                $queue,
                ['status' => 'cancelled', 'error_message' => 'Suppressed: ' . $reason],
                ['email IN (?)' => $chunk, 'status = ?' => 'pending']
            );
        }

        return ['added' => $added, 'already' => $already];
    }

    /**
     * Whether an address is suppressed, and for which reason.
     *
     * @return string|null The reason, or null when it is not on the list
     */
    public function reasonOf(string $email)
    {
        $adapter = $this->_getReadAdapter();
        $reason  = $adapter->fetchOne(
            $adapter->select()->from($this->getMainTable(), 'reason')->where('email = ?', strtolower(trim($email)))
        );

        return $reason === false ? null : (string) $reason;
    }

    /**
     * Take addresses off the list.
     *
     * A bounce, complaint or hand-added entry that had turned a subscribed
     * address to unsubscribed turns it back. An unsubscribe does not: the
     * person asked to stop, and taking a row off a list is not them asking to
     * start again.
     *
     * @param  int[] $ids
     * @return array<int, array{email:string, reason:string}> What was removed
     */
    public function removeByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return [];
        }

        $adapter     = $this->_getWriteAdapter();
        $subscribers = $this->getTable('newsletter/subscriber');
        $removed     = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $adapter->fetchAll(
                $adapter->select()->from($this->getMainTable())->where('suppression_id IN (?)', $chunk)
            );

            $restore = [];

            foreach ($rows as $row) {
                $removed[] = ['email' => (string) $row['email'], 'reason' => (string) $row['reason']];

                if ($row['reason'] !== YSRTech_EmailCampaigns_Model_Suppression::REASON_UNSUBSCRIBE
                    && (int) $row['prev_subscriber_status'] === Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED
                ) {
                    $restore[] = (string) $row['email'];
                }
            }

            $adapter->delete($this->getMainTable(), ['suppression_id IN (?)' => $chunk]);

            if ($restore) {
                $adapter->update(
                    $subscribers,
                    [
                        'subscriber_status' => Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED,
                        'change_status_at'  => Varien_Date::now(),
                    ],
                    [
                        'subscriber_email IN (?)' => $restore,
                        'subscriber_status = ?'   => Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED,
                    ]
                );
            }
        }

        return $removed;
    }

    /**
     * @return array<string, int> reason => how many
     */
    public function countByReason(): array
    {
        $adapter = $this->_getReadAdapter();

        return array_map('intval', $adapter->fetchPairs(
            $adapter->select()
                ->from($this->getMainTable(), ['reason', new Zend_Db_Expr('COUNT(*)')])
                ->group('reason')
        ));
    }
}
