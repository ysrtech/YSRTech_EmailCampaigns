<?php
/**
 * Writes imported people into newsletter_subscriber.
 *
 * Straight into the table, never through
 * Mage_Newsletter_Model_Subscriber::subscribe(): that method sends each
 * person a confirmation or a "you are subscribed" email, which on an import
 * is one unrequested message to every address in the file.
 *
 * What it will not do, however the file is marked:
 *  - subscribe an address that is on the suppression list. It is imported,
 *    as unsubscribed, so the record of it exists and it is never mailed;
 *  - change the status of somebody already in the table. A person who
 *    unsubscribed in the store does not become subscribed because an old
 *    export says they once were.
 */
class YSRTech_EmailCampaigns_Model_Import_Contacts
{
    /**
     * @param  YSRTech_EmailCampaigns_Model_Import $import
     * @param  array<string, array> $people email => [first_name, last_name, status, dup, extras]
     *         status is subscribed|unsubscribed|bounced|complained|pending
     * @param  array $stats   Counted into, by reference
     * @param  array $options update_existing: bool
     */
    public function save($import, array $people, array &$stats, array $options): void
    {
        if (!$people) {
            return;
        }

        $resource    = Mage::getSingleton('core/resource');
        $adapter     = $resource->getConnection('core_write');
        $subscribers = $resource->getTableName('newsletter/subscriber');
        $seenTable   = $resource->getTableName('ysrtech_emailcampaigns/import_seen');
        $importId    = (int) $import->getId();
        $now         = Varien_Date::now();
        $update      = !isset($options['update_existing']) || $options['update_existing'];

        /** @var YSRTech_EmailCampaigns_Model_Resource_Suppression $suppression */
        $suppression = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression');

        foreach (array_chunk(array_keys($people), 500) as $emails) {
            // Seen earlier in this import: a duplicate, already counted once
            $hashes = [];

            foreach ($emails as $email) {
                $hashes[$email] = sha1($email);
            }

            $seenBefore = array_flip($adapter->fetchCol(
                $adapter->select()->from($seenTable, 'email_hash')
                    ->where('import_id = ?', $importId)
                    ->where('email_hash IN (?)', array_values($hashes))
            ));

            $adapter->insertOnDuplicate($seenTable, array_map(
                static fn ($hash) => ['import_id' => $importId, 'email_hash' => $hash],
                array_values($hashes)
            ), ['email_hash']);

            /*
             * What the source says about the address goes onto the
             * suppression list first, so that the read of that list below
             * already includes it.
             */
            $toSuppress = [];

            foreach ($emails as $email) {
                $status = (string) ($people[$email]['status'] ?? 'subscribed');
                $reason = [
                    'unsubscribed' => YSRTech_EmailCampaigns_Model_Suppression::REASON_UNSUBSCRIBE,
                    'bounced'      => YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE,
                    'complained'   => YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT,
                ][$status] ?? null;

                if ($reason !== null) {
                    $toSuppress[$reason][$email] = Mage::helper('ysrtech_emailcampaigns')->__('Marked %s in an import', $status);

                    if (!isset($seenBefore[$hashes[$email]])) {
                        $stats['marked_unsubscribed']++;
                    }
                }
            }

            $suppressed = array_change_key_case($adapter->fetchPairs(
                $adapter->select()
                    ->from($resource->getTableName('ysrtech_emailcampaigns/suppression'), ['email', 'reason'])
                    ->where('email IN (?)', $emails)
            ), CASE_LOWER);

            $existing = [];

            foreach ($adapter->fetchAll(
                $adapter->select()
                    ->from($subscribers, ['subscriber_id', 'subscriber_email', 'subscriber_firstname', 'subscriber_lastname', 'customer_id'])
                    ->where('subscriber_email IN (?)', $emails)
            ) as $row) {
                $existing[strtolower((string) $row['subscriber_email'])] = $row;
            }

            // An address that is also a customer's gets linked to the account
            $customers = [];

            foreach ($adapter->fetchAll(
                $adapter->select()
                    ->from($resource->getTableName('customer/entity'), ['email', 'entity_id', 'store_id'])
                    ->where('email IN (?)', $emails)
            ) as $row) {
                $customers[strtolower((string) $row['email'])] = $row;
            }

            $defaultStoreId = (int) Mage::app()->getDefaultStoreView()->getId();
            $insert         = [];

            foreach ($emails as $email) {
                $person  = $people[$email];
                $counted = isset($seenBefore[$hashes[$email]]);

                if ($counted || !empty($person['dup'])) {
                    $stats['duplicates']++;
                }

                if (!empty($person['extras']) && !$counted) {
                    $stats['extras_skipped']++;
                }

                $isSuppressed = isset($suppressed[$email]) || isset($toSuppress[YSRTech_EmailCampaigns_Model_Suppression::REASON_UNSUBSCRIBE][$email])
                    || isset($toSuppress[YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE][$email])
                    || isset($toSuppress[YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT][$email]);

                if (isset($suppressed[$email]) && !$counted) {
                    $stats['suppressed']++;
                }

                if (isset($existing[$email])) {
                    $row  = $existing[$email];
                    $bind = [];

                    // Names only, and only where the import has one: status
                    // is theirs to change, not a file's.
                    if ($update) {
                        foreach (['first_name' => 'subscriber_firstname', 'last_name' => 'subscriber_lastname'] as $from => $to) {
                            $value = trim((string) ($person[$from] ?? ''));

                            if ($value !== '' && $value !== (string) $row[$to]) {
                                $bind[$to] = $value;
                            }
                        }

                        if (!(int) $row['customer_id'] && isset($customers[$email])) {
                            $bind['customer_id'] = (int) $customers[$email]['entity_id'];
                        }
                    }

                    if ($bind) {
                        $adapter->update($subscribers, $bind, ['subscriber_id = ?' => (int) $row['subscriber_id']]);
                    }

                    if (!$counted) {
                        $stats[$bind ? 'updated' : 'unchanged']++;
                    }

                    continue;
                }

                $status = Mage_Newsletter_Model_Subscriber::STATUS_SUBSCRIBED;

                if ($isSuppressed) {
                    $status = Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED;
                } elseif (($person['status'] ?? '') === 'pending') {
                    // Never confirmed with the old provider either
                    $status = Mage_Newsletter_Model_Subscriber::STATUS_UNCONFIRMED;
                }

                $customer = $customers[$email] ?? null;

                $insert[] = [
                    'store_id'                => $customer ? (int) $customer['store_id'] : $defaultStoreId,
                    'change_status_at'        => $now,
                    'customer_id'             => $customer ? (int) $customer['entity_id'] : 0,
                    'subscriber_email'        => $email,
                    'subscriber_status'       => $status,
                    'subscriber_confirm_code' => Mage::helper('core')->getRandomString(32),
                    'subscriber_firstname'    => $this->_name($person['first_name'] ?? null),
                    'subscriber_lastname'     => $this->_name($person['last_name'] ?? null),
                ];

                $stats['created']++;
            }

            if ($insert) {
                $adapter->insertMultiple($subscribers, $insert);
            }

            // After the inserts, so that the new rows' status is turned and
            // recorded with everything else's.
            foreach ($toSuppress as $reason => $addresses) {
                $suppression->addMany($addresses, $reason, 'import');
            }
        }
    }

    private function _name($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
