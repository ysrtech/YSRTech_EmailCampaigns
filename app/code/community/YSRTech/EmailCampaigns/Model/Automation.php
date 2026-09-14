<?php
/**
 * A rule that sends one message whenever a customer does something.
 *
 * Where a campaign is one send to many people chosen by a person, an
 * automation is many sends of one message, each occasioned by an order, a
 * shipment, an abandoned cart. Both end up in the same queue, so they share
 * the claim-before-send, the transports and the suppression checks.
 */
class YSRTech_EmailCampaigns_Model_Automation extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_emailcampaigns/automation');
    }

    /**
     * @return $this
     */
    protected function _beforeSave()
    {
        parent::_beforeSave();

        $this->setUpdatedAt(Varien_Date::now());

        // Only one of these applies, and a stale value on the other reads as
        // a condition that is being applied when it is not
        $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;

        if ($this->getEvent() !== $source::ORDER_STATUS) {
            $this->setOrderStatus(null);
        }

        if ($this->getEvent() !== $source::PRODUCT_PURCHASED) {
            $this->setProductId(null);
        }

        if ($this->getSendMoment() !== 'after') {
            $this->setAfterDays(0)->setAfterHours(0);
        }

        return $this;
    }

    /**
     * When a message triggered now should actually go out.
     *
     * @return string
     */
    public function getSendAt(): string
    {
        if ($this->getSendMoment() !== 'after') {
            return Varien_Date::now();
        }

        $seconds = ((int) $this->getAfterDays() * 86400) + ((int) $this->getAfterHours() * 3600);

        /*
         * gmdate, not core/date: the queue stores and compares UTC, and
         * core/date would hand back store-local time to be compared against
         * it - which quietly shifts every delay by the store's offset.
         */
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    /**
     * Put one message on the queue for this automation.
     *
     * Returns false rather than throwing when there is nothing to do - a
     * missing address or a repeat of something already queued is ordinary,
     * and an observer running inside somebody's checkout is the wrong place
     * to raise an exception.
     *
     * @param  string        $email
     * @param  string        $objectType
     * @param  int           $objectId
     * @param  int|null      $customerId
     * @param  int|null      $storeId
     * @return bool
     */
    public function queue(string $email, string $objectType, int $objectId, $customerId = null, $storeId = null): bool
    {
        $email = trim($email);

        if ($email === '' || !$this->getId()) {
            return false;
        }

        if ($this->getRespectSubscription() && !$this->_maySendMarketingTo($email)) {
            return false;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');

        try {
            /*
             * The unique key on (automation_id, object_type, object_id) is
             * what stops one order being mailed twice by the same rule - two
             * observers firing on the same save, a retroactive run covering
             * ground the live trigger already did. Letting the database say no
             * is cheaper and more reliable than asking it first.
             */
            $adapter->insert($resource->getTableName('ysrtech_emailcampaigns/queue'), [
                'automation_id'  => (int) $this->getId(),
                'campaign_id'    => null,
                'object_type'    => $objectType,
                'object_id'      => $objectId,
                'email'          => $email,
                'customer_id'    => $customerId ? (int) $customerId : null,
                'subscriber_id'  => $this->_subscriberIdFor($email),
                'status'         => 'pending',
                'send_at'        => $this->getSendAt(),
                'tracking_token' => Mage::helper('core')->getRandomString(32),
            ]);
        } catch (Exception $e) {
            // Duplicate key: already queued for this object, which is fine
            return false;
        }

        return true;
    }

    /**
     * Whether marketing mail may go to this address.
     *
     * The old follow-up module checked nothing, so an abandoned cart nudge
     * went to people who had unsubscribed. Mailgun would accept the call and
     * drop the message against its suppression list, so it even looked sent.
     *
     * @param  string $email
     * @return bool
     */
    protected function _maySendMarketingTo(string $email): bool
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $status = $adapter->fetchOne(
            $adapter->select()
                ->from($resource->getTableName('newsletter/subscriber'), 'subscriber_status')
                ->where('subscriber_email = ?', $email)
                ->limit(1)
        );

        /*
         * Never seen on the newsletter is not the same as having opted out.
         * Somebody who checked out as a guest and never subscribed has not
         * refused anything, and a cart nudge to them is the ordinary
         * expectation of the trade. Only an explicit unsubscribe holds it.
         */
        if ($status === false || $status === null || $status === '') {
            return true;
        }

        return (int) $status !== Mage_Newsletter_Model_Subscriber::STATUS_UNSUBSCRIBED;
    }

    /**
     * @param  string $email
     * @return int|null
     */
    protected function _subscriberIdFor(string $email)
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $id = $adapter->fetchOne(
            $adapter->select()
                ->from($resource->getTableName('newsletter/subscriber'), 'subscriber_id')
                ->where('subscriber_email = ?', $email)
                ->limit(1)
        );

        return $id ? (int) $id : null;
    }
}
