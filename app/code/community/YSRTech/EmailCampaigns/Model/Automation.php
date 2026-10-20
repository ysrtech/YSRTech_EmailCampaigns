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
     * The messages this automation sends, in order.
     *
     * @return YSRTech_EmailCampaigns_Model_Resource_Automation_Step_Collection
     */
    public function getSteps()
    {
        if (!$this->hasData('steps')) {
            $this->setData('steps', Mage::getResourceModel('ysrtech_emailcampaigns/automation_step_collection')
                ->addAutomationFilter((int) $this->getId()));
        }

        return $this->getData('steps');
    }

    /**
     * Whether a later order should stop the rest of the chain.
     *
     * @return bool
     */
    public function cancelsOnOrder(): bool
    {
        return $this->getCancelOn() === 'order_placed';
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
    public function queue(string $email, string $objectType, int $objectId, $customerId = null, $storeId = null): int
    {
        $email = trim($email);

        if ($email === '' || !$this->getId()) {
            return 0;
        }

        if ($this->getRespectSubscription() && !$this->_maySendMarketingTo($email)) {
            return 0;
        }

        $steps = $this->getSteps();

        if (!count($steps)) {
            return 0;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_emailcampaigns/queue');

        /*
         * Every step is queued now, each with its own due time, rather than
         * the next being added as the previous one sends. A chain that only
         * advances while the cron keeps running is a chain that quietly stops
         * halfway when something goes wrong for a day; laid down in full, the
         * later steps are simply due later.
         */
        $triggeredAt = time();
        $queuedAt    = gmdate('Y-m-d H:i:s', $triggeredAt);
        $subscriber  = $this->_subscriberIdFor($email);
        $queued      = 0;

        foreach ($steps as $step) {
            try {
                /*
                 * The unique key - now including the step - is what stops one
                 * order being mailed twice by the same rule when two observers
                 * fire on the same save. Letting the database say no is
                 * cheaper and more reliable than asking it first.
                 */
                $adapter->insert($table, [
                    'automation_id'  => (int) $this->getId(),
                    'step_id'        => (int) $step->getId(),
                    'campaign_id'    => null,
                    'object_type'    => $objectType,
                    'object_id'      => $objectId,
                    'email'          => $email,
                    'customer_id'    => $customerId ? (int) $customerId : null,
                    'subscriber_id'  => $subscriber,
                    'status'         => 'pending',
                    'queued_at'      => $queuedAt,
                    'send_at'        => $step->getSendAt($triggeredAt),
                    'tracking_token' => Mage::helper('core')->getRandomString(32),
                ]);

                $queued++;
            } catch (Exception $e) {
                // Duplicate: this step is already queued for this object
                continue;
            }
        }

        return $queued;
    }

    /**
     * Whether the rest of this chain should be abandoned for one recipient.
     *
     * Asked as each message comes due rather than on a schedule of its own,
     * so the answer is as late as it can be: somebody who orders an hour
     * before the day-seven nudge should not receive it.
     *
     * @param  YSRTech_EmailCampaigns_Model_Queue $item
     * @return bool
     */
    public function shouldCancelFor($item): bool
    {
        if (!$this->cancelsOnOrder() || !$item->getQueuedAt()) {
            return false;
        }

        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_read');

        $select = $adapter->select()
            ->from($resource->getTableName('sales/order'), [new Zend_Db_Expr('1')])
            ->where('customer_email = ?', (string) $item->getEmail())
            ->where('created_at > ?', (string) $item->getQueuedAt())
            ->limit(1);

        /*
         * Matched on the address rather than the customer id: the cart that
         * started this may have been a guest's, and the order that answers it
         * may be a guest order too.
         */
        return (bool) $adapter->fetchOne($select);
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
