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

        /*
         * Going live is a publication, and each one gets a number. Every
         * message queued from here on is stamped with it, so "which flow was
         * this person sent" has an answer after the flow has been paused,
         * changed and switched on again.
         *
         * The first publication keeps the 1 the column starts at; it is only
         * the ones after it that count up.
         */
        $wasActive = !$this->isObjectNew() && (int) $this->getOrigData('is_active') === 1;

        if ((int) $this->getIsActive() === 1 && !$wasActive) {
            $this->setVersion($this->getPublishedAt() ? max(1, (int) $this->getVersion()) + 1 : max(1, (int) $this->getVersion()));
            $this->setPublishedAt(Varien_Date::now());
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function _afterSave()
    {
        // Nothing refreshes this after a save, and without it a second save
        // of the same object while live would read as going live again.
        $this->setOrigData('is_active', (int) $this->getIsActive());

        return parent::_afterSave();
    }

    /**
     * Take the row for writing, so that one save at a time decides what the
     * flow is.
     *
     * Two things wait on this lock. Another save of the same automation,
     * which would otherwise interleave its step rows with this one's; and
     * queue(), which holds the row shared while it lays a chain down, so a
     * chain is always queued from the steps of one version and never from
     * half of an edit.
     *
     * Only means anything inside a transaction on the write connection.
     *
     * @param  int $id
     * @return $this
     */
    public function loadForUpdate(int $id)
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');

        $adapter->fetchOne(
            $adapter->select()
                ->from($resource->getTableName('ysrtech_emailcampaigns/automation'), 'automation_id')
                ->where('automation_id = ?', $id)
                ->forUpdate(true)
        );

        return $this->load($id);
    }

    /**
     * Save this flow from a drawing of it - the one way in for both editors.
     *
     * Three rules live here, and here only, so that neither the canvas nor
     * the form can be used to get round them:
     *
     *  - A flow that has problems is never switched on. Asking for active
     *    with problems outstanding saves the work as a draft and says what
     *    is wrong; the alternative is losing the work or mailing customers
     *    from a flow that is known to be broken.
     *  - A live flow is not changed. People are partway through it. It is
     *    paused first - which this same call may do - and then edited, and
     *    going live again is a new version.
     *  - One save at a time, under a lock on the row, which is also what
     *    keeps queue() from laying a chain down out of half an edit.
     *
     * @param  array $graph      {nodes, edges}, as the canvas posts it or as
     *                           Graph::toGraph() draws the form's rows
     * @param  array $fields     name, and any settings to store alongside
     * @param  bool  $wantActive whether this save asks for the flow to be live
     * @param  bool  $fromCanvas keep the drawing itself, with its positions
     *                           and any half-finished steps
     * @return array{active: bool, demoted: bool, paused: bool, steps: int, problems: array}
     * @throws Mage_Core_Exception when nothing can be saved at all
     */
    public function saveFlow(array $graph, array $fields, bool $wantActive, bool $fromCanvas): array
    {
        $h    = Mage::helper('ysrtech_emailcampaigns');
        $name = trim((string) ($fields['name'] ?? ''));

        if ($name === '') {
            Mage::throwException($h->__('Give the flow a name before saving.'));
        }

        if (mb_strlen($name) > 255) {
            Mage::throwException($h->__('That name is too long. Keep it under 255 characters.'));
        }

        /** @var YSRTech_EmailCampaigns_Model_Automation_Graph $translator */
        $translator = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph');
        $source     = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;
        $adapter    = Mage::getSingleton('core/resource')->getConnection('core_write');

        $adapter->beginTransaction();

        try {
            if ($this->getId()) {
                $id = (int) $this->getId();
                $this->loadForUpdate($id);

                if (!$this->getId()) {
                    Mage::throwException($h->__('Automation no longer exists.'));
                }
            }

            $graph    = $translator->normalise($graph);
            $context  = $this->validationContext();
            $problems = $translator->validate($graph, $context);

            foreach ($problems as $problem) {
                if (!empty($problem['fatal'])) {
                    // Not even as a draft: this cannot be written as steps.
                    Mage::throwException($problem['message']);
                }
            }

            $translated = $translator->toSteps($graph);
            $wasActive  = $this->getId() && (int) $this->getIsActive() === 1;

            // A trigger that is not one of ours is a problem already and so
            // cannot go live; what is stored meanwhile has to be a real one.
            $event = in_array($translated['trigger'], $context['events'], true)
                ? $translated['trigger']
                : ((string) $this->getEvent() ?: $source::NEW_ORDER);

            $definition = [
                'name'         => $name,
                'event'        => $event,
                'order_status' => $translated['order_status'],
                'product_id'   => $translated['product_id'],
                'steps'        => $translated['steps'],
            ];

            if ($wasActive && $wantActive && self::signatureOf($definition) !== $this->getSignature()) {
                Mage::throwException($h->__('This flow is live. Pause it to make changes.'));
            }

            $steps = self::storableSteps($translated['steps'], $context['template_ids']);

            if (!$fromCanvas && count($steps) !== count($translated['steps'])) {
                // The canvas keeps such a step in its drawing until it is
                // fixed. The form has nowhere to keep it.
                Mage::throwException($h->__('One of the messages uses a template that no longer exists.'));
            }

            $active = $wantActive && !$problems;

            $this->addData(['name' => $name] + $fields)
                ->addData([
                    'event'        => $event,
                    'order_status' => $translated['order_status'],
                    'product_id'   => $translated['product_id'],
                    'is_active'    => (int) $active,
                ]);

            if ($fromCanvas) {
                $this->setGraphJson(json_encode($graph));
            }

            $this->save();
            $this->_writeSteps($steps);

            $adapter->commit();
        } catch (Exception $e) {
            $adapter->rollBack();
            throw $e;
        }

        // Strip the internal flag; what is left is what callers show people.
        $problems = array_map(
            static fn(array $problem): array => ['node' => $problem['node'], 'message' => $problem['message']],
            $problems
        );

        return [
            'active'   => $active,
            'demoted'  => $wantActive && !$active,
            'paused'   => $wasActive && !$active,
            'steps'    => count($steps),
            'problems' => $problems,
        ];
    }

    /**
     * Rewrite the chain's step rows.
     *
     * Steps that survive keep their ids, position for position, so a queue
     * row's pointer to "the second message" still lands on the second
     * message. Nothing depends on that any more - a queued message carries
     * its own template and the pointer is cleared, not cascaded, when a step
     * goes - but it keeps the history joinable for as long as it can be.
     *
     * @param  array $steps
     * @return void
     */
    protected function _writeSteps(array $steps): void
    {
        $existing = [];

        foreach (Mage::getResourceModel('ysrtech_emailcampaigns/automation_step_collection')
                     ->addAutomationFilter((int) $this->getId()) as $step) {
            $existing[] = $step;
        }

        foreach (array_values($steps) as $position => $values) {
            $step = array_shift($existing) ?: Mage::getModel('ysrtech_emailcampaigns/automation_step');

            $step->addData([
                'template_id'   => (int) $values['template_id'],
                'after_days'    => (int) ($values['after_days'] ?? 0),
                'after_hours'   => (int) ($values['after_hours'] ?? 0),
                'after_minutes' => (int) ($values['after_minutes'] ?? 0),
            ])
                ->setAutomationId($this->getId())
                ->setSortOrder($position)
                ->save();
        }

        // Anything left over is no longer in the chain
        foreach ($existing as $step) {
            $step->delete();
        }

        $this->unsetData('steps');
    }

    /**
     * The steps as plain rows, in order - the shape the graph translator and
     * the controller pass around.
     *
     * @return array
     */
    public function getStepRows(): array
    {
        $rows = [];

        foreach ($this->getSteps() as $step) {
            $rows[] = [
                'template_id'   => (int) $step->getTemplateId(),
                'after_days'    => (int) $step->getAfterDays(),
                'after_hours'   => (int) $step->getAfterHours(),
                'after_minutes' => (int) $step->getAfterMinutes(),
            ];
        }

        return $rows;
    }

    /**
     * What validate() needs to know about the world outside the drawing.
     *
     * @return array{template_ids: int[], events: string[]}
     */
    public function validationContext(): array
    {
        return [
            'template_ids' => array_map(
                'intval',
                Mage::getResourceModel('ysrtech_emailcampaigns/template_collection')->getAllIds()
            ),
            'events'       => array_keys(
                Mage::getSingleton('ysrtech_emailcampaigns/system_config_source_automation_event')->toArray()
            ),
        ];
    }

    /**
     * Everything about a flow that decides who is mailed what and when, as
     * one comparable string.
     *
     * This is what may not change while the flow is live. The store, the
     * dates and the like are settings around it and are left out, so they
     * can still be adjusted without pausing.
     *
     * @param  array $definition name, event, order_status, product_id, steps
     * @return string
     */
    public static function signatureOf(array $definition): string
    {
        $source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;
        $event  = (string) ($definition['event'] ?? '');
        $steps  = [];

        foreach ((array) ($definition['steps'] ?? []) as $step) {
            $steps[] = [
                (int) ($step['template_id'] ?? 0),
                ((int) ($step['after_days'] ?? 0) * 1440)
                    + ((int) ($step['after_hours'] ?? 0) * 60)
                    + (int) ($step['after_minutes'] ?? 0),
            ];
        }

        return json_encode([
            trim((string) ($definition['name'] ?? '')),
            $event,
            // As _beforeSave stores them: a condition that does not apply to
            // the trigger is not part of the flow.
            $event === $source::ORDER_STATUS ? (string) ($definition['order_status'] ?? '') : '',
            $event === $source::PRODUCT_PURCHASED ? (int) ($definition['product_id'] ?? 0) : 0,
            $steps,
        ]);
    }

    /**
     * @return string the signature of this automation as stored
     */
    public function getSignature(): string
    {
        return self::signatureOf([
            'name'         => $this->getName(),
            'event'        => $this->getEvent(),
            'order_status' => $this->getOrderStatus(),
            'product_id'   => $this->getProductId(),
            'steps'        => $this->getStepRows(),
        ]);
    }

    /**
     * The flow as the canvas should draw it.
     *
     * The drawing saved from the canvas is used when it still describes the
     * steps on record - it has the positions somebody arranged, and in a
     * draft the half-finished parts that have no step row. When it does not,
     * because the form has changed the steps since, the steps win and the
     * drawing is made afresh from them: the steps are what would be sent.
     *
     * @return array{nodes: array, edges: array}
     */
    public function getCanvasGraph(): array
    {
        /** @var YSRTech_EmailCampaigns_Model_Automation_Graph $translator */
        $translator = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph');
        $source     = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;
        $trigger    = [
            'trigger_type' => (string) ($this->getEvent() ?: $source::NEW_ORDER),
            'order_status' => (string) $this->getOrderStatus(),
            'product_id'   => (int) $this->getProductId(),
        ];
        $rows       = $this->getStepRows();

        if ($this->getGraphJson()) {
            try {
                $graph  = $translator->decode((string) $this->getGraphJson());
                $stored = self::storableSteps(
                    $translator->toSteps($graph)['steps'],
                    $this->validationContext()['template_ids']
                );

                if (self::signatureOf(['steps' => $stored]) === self::signatureOf(['steps' => $rows])) {
                    foreach ($graph['nodes'] as $i => $node) {
                        if ($node['type'] === $translator::NODE_TRIGGER) {
                            // The trigger's conditions live in columns of
                            // their own, so those are the truth - except for
                            // a trigger nobody has chosen yet, which the
                            // columns cannot say and the drawing can.
                            if (($node['config']['trigger_type'] ?? '') !== '') {
                                $graph['nodes'][$i]['config'] = $trigger;
                            }
                            break;
                        }
                    }

                    return $graph;
                }
            } catch (Mage_Core_Exception $e) {
                // An unreadable drawing is just a missing one
            }
        }

        return $translator->toGraph($trigger['trigger_type'], $rows, $trigger);
    }

    /**
     * The steps of a drawing that can be written as step rows.
     *
     * A step row must name a template that exists - the schema insists - and
     * a draft may have send steps that do not yet. Those wait in the drawing;
     * a flow with any of them cannot be live, so nothing is ever sent from
     * the shorter list this returns.
     *
     * @param  array $steps
     * @param  int[] $templateIds templates that exist
     * @return array
     */
    public static function storableSteps(array $steps, array $templateIds): array
    {
        $out = [];

        foreach ($steps as $step) {
            if (in_array((int) ($step['template_id'] ?? 0), $templateIds, true)) {
                $out[] = ['sort_order' => count($out)] + $step;
            }
        }

        return $out;
    }

    /**
     * Which template a queued message should be sent with.
     *
     * The one the row was queued with, when it has one: the flow may have
     * been paused and changed since, and somebody halfway through a chain
     * was promised the chain as it was. Rows from before that was recorded
     * fall back to what their step says now, as they always did.
     *
     * @param  Varien_Object $item a queue row
     * @return int 0 when nothing on record says
     */
    public function resolveTemplateId($item): int
    {
        if ((int) $item->getTemplateId() > 0) {
            return (int) $item->getTemplateId();
        }

        if ((int) $item->getStepId() > 0) {
            return (int) Mage::getModel('ysrtech_emailcampaigns/automation_step')
                ->load((int) $item->getStepId())
                ->getTemplateId();
        }

        return 0;
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

        /*
         * The suppression list, whatever the rule above says. An automation
         * that ignores the newsletter flag is one about an order - a receipt,
         * a shipping notice - and somebody who unsubscribed from marketing is
         * still owed those. An address that hard-bounced or reported spam is
         * a different matter: nothing should go to it at all, and Mailgun
         * would drop it on its own list anyway while the queue called it sent.
         */
        $suppressedFor = Mage::getResourceSingleton('ysrtech_emailcampaigns/suppression')->reasonOf($email);

        if ($suppressedFor !== null
            && ($this->getRespectSubscription() || in_array($suppressedFor, [
                YSRTech_EmailCampaigns_Model_Suppression::REASON_HARD_BOUNCE,
                YSRTech_EmailCampaigns_Model_Suppression::REASON_COMPLAINT,
            ], true))
        ) {
            return 0;
        }

        $resource    = Mage::getSingleton('core/resource');
        $adapter     = $resource->getConnection('core_write');
        $table       = $resource->getTableName('ysrtech_emailcampaigns/queue');
        $automations = $resource->getTableName('ysrtech_emailcampaigns/automation');
        $queued      = 0;

        $adapter->beginTransaction();

        try {
            /*
             * Held shared for as long as the chain takes to lay down. A save
             * takes the same row for update, so the steps read below are the
             * steps of one whole version: an edit either finished before
             * this or waits until it is done.
             *
             * And asked again whether it is live, because the object in hand
             * was loaded by an observer some time ago. Pausing a flow has to
             * mean nothing new enters it, including what was already on its
             * way in.
             */
            $live = $adapter->fetchRow(
                "SELECT is_active, version FROM {$automations} WHERE automation_id = ? LOCK IN SHARE MODE",
                [(int) $this->getId()]
            );

            if (!$live || !(int) $live['is_active']) {
                return 0;
            }

            /*
             * One trigger object enters a flow once. Asked of the whole chain
             * rather than left to each step's unique key alone: after an edit
             * that added a step, the key would let that one new step through
             * for an order whose chain was queued - and perhaps finished -
             * under the old flow.
             */
            $already = $adapter->fetchOne(
                $adapter->select()
                    ->from($table, [new Zend_Db_Expr('1')])
                    ->where('automation_id = ?', (int) $this->getId())
                    ->where('object_type = ?', $objectType)
                    ->where('object_id = ?', $objectId)
                    ->limit(1)
            );

            if ($already) {
                return 0;
            }

            // Read under the lock, not whatever this object cached earlier
            $this->unsetData('steps');
            $steps = $this->getSteps();

            /*
             * Every step is queued now, each with its own due time, rather
             * than the next being added as the previous one sends. A chain
             * that only advances while the cron keeps running is a chain that
             * quietly stops halfway when something goes wrong for a day; laid
             * down in full, the later steps are simply due later.
             */
            $triggeredAt = time();
            $queuedAt    = gmdate('Y-m-d H:i:s', $triggeredAt);
            $subscriber  = $this->_subscriberIdFor($email);
            $position    = 0;

            foreach ($steps as $step) {
                try {
                    /*
                     * The row carries what it was queued with - the template
                     * and the version - so a later edit to the flow changes
                     * what new entries get and not what this one was
                     * promised.
                     *
                     * The unique key, on the step's position, is what stops
                     * two requests that both passed the check above from
                     * both queueing. Letting the database say no is the only
                     * answer that holds when they run at the same moment.
                     */
                    $adapter->insert($table, [
                        'automation_id'      => (int) $this->getId(),
                        'automation_version' => (int) $live['version'],
                        'step_id'            => (int) $step->getId(),
                        'step_order'         => $position++,
                        'template_id'        => (int) $step->getTemplateId(),
                        'campaign_id'        => null,
                        'object_type'        => $objectType,
                        'object_id'          => $objectId,
                        'email'              => $email,
                        'customer_id'        => $customerId ? (int) $customerId : null,
                        'subscriber_id'      => $subscriber,
                        'status'             => 'pending',
                        'queued_at'          => $queuedAt,
                        'send_at'            => $step->getSendAt($triggeredAt),
                        'tracking_token'     => Mage::helper('core')->getRandomString(32),
                    ]);

                    $queued++;
                } catch (Exception $e) {
                    if (!$this->_isDuplicateKey($e)) {
                        throw $e;
                    }

                    // Another request queued this step for this object
                    // between the check and here; it is theirs.
                }
            }
        } catch (Exception $e) {
            /*
             * Logged, not thrown: this runs inside somebody's checkout. And
             * not rolled back either - when the observer is already inside
             * the order's own transaction a rollback here would take the
             * order with it.
             */
            Mage::logException($e);
        } finally {
            $adapter->commit();
        }

        return $queued;
    }

    /**
     * Whether an insert failed because the row is already there.
     *
     * Told apart from every other failure on purpose. Swallowing any
     * exception as "probably a duplicate" also swallows a missing column or
     * a broken foreign key, and then a flow queues nothing and nobody is
     * told.
     *
     * @param  Exception $e
     * @return bool
     */
    protected function _isDuplicateKey(Exception $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            // 1062 is MySQL's duplicate entry; 23000 alone would include
            // foreign key failures.
            if ($cause instanceof PDOException && (int) ($cause->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
        }

        return strpos($e->getMessage(), 'SQLSTATE[23000]') !== false && strpos($e->getMessage(), ' 1062 ') !== false;
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
