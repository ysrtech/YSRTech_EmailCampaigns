<?php
/**
 * Keeps the names on the newsletter list in step with the customer accounts.
 *
 * The newsletter table has a name of its own beside each address, filled in
 * by whatever put the address there - and by nothing afterwards. A subscriber
 * who later opens an account, or whose account is made for them, or who
 * corrects their name in it, stays as they were on the list. This puts the
 * list right: the account is where a person keeps their name, so the account
 * wins.
 */
class YSRTech_EmailCampaigns_Model_Subscriber_Names
{
    /**
     * Tie each subscriber to the customer with the same address on the same
     * website, then give every subscriber who has an account that account's
     * name.
     *
     * Two or three statements over the whole list. Nobody's subscription
     * status is touched; a subscriber with no account keeps the name they
     * have; an account with an empty name changes nothing.
     *
     * @return array{linked:int, named:int}
     */
    public function sync(): array
    {
        $resource    = Mage::getSingleton('core/resource');
        $write       = $resource->getConnection('core_write');
        $subscribers = $resource->getTableName('newsletter/subscriber');
        $customers   = $resource->getTableName('customer/entity');
        $stores      = $resource->getTableName('core/store');
        $result      = ['linked' => 0, 'named' => 0];

        // A subscriber row belongs to a store view; the account to a website
        $result['linked'] = (int) $write->query(
            "UPDATE {$subscribers} s
               JOIN {$stores} st ON st.store_id = s.store_id
               JOIN {$customers} c ON c.email = s.subscriber_email AND c.website_id = st.website_id
                SET s.customer_id = c.entity_id
              WHERE s.customer_id = 0 OR s.customer_id IS NULL"
        )->rowCount();

        // Older installs have no name columns on the list at all
        if (!$write->tableColumnExists($subscribers, 'subscriber_firstname')
            || !$write->tableColumnExists($subscribers, 'subscriber_lastname')
        ) {
            return $result;
        }

        $first = Mage::getSingleton('eav/config')->getAttribute('customer', 'firstname');
        $last  = Mage::getSingleton('eav/config')->getAttribute('customer', 'lastname');

        if (!$first || !$first->getId() || !$last || !$last->getId()) {
            return $result;
        }

        /*
         * Both names in one statement, so "how many subscribers changed" is
         * one count rather than two that overlap. Binary comparison: a name
         * retyped with different capitals is a change worth carrying over.
         */
        $result['named'] = (int) $write->query(
            "UPDATE {$subscribers} s
               LEFT JOIN {$first->getBackendTable()} f ON f.entity_id = s.customer_id AND f.attribute_id = " . (int) $first->getId() . "
               LEFT JOIN {$last->getBackendTable()} l ON l.entity_id = s.customer_id AND l.attribute_id = " . (int) $last->getId() . "
                SET s.subscriber_firstname = IF(TRIM(IFNULL(f.value, '')) = '', s.subscriber_firstname, TRIM(f.value)),
                    s.subscriber_lastname  = IF(TRIM(IFNULL(l.value, '')) = '', s.subscriber_lastname, TRIM(l.value))
              WHERE s.customer_id > 0
                AND ((TRIM(IFNULL(f.value, '')) != '' AND NOT (BINARY IFNULL(s.subscriber_firstname, '') = BINARY TRIM(f.value)))
                  OR (TRIM(IFNULL(l.value, '')) != '' AND NOT (BINARY IFNULL(s.subscriber_lastname, '') = BINARY TRIM(l.value))))"
        )->rowCount();

        return $result;
    }
}
