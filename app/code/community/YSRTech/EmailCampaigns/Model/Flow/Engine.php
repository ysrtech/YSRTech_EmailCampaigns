<?php
/**
 * Walks customers through a Flow's graph_json one node at a time.
 *
 * Phase 1 supports a linear chain of trigger -> delay -> action_send_email
 * nodes (no branching/condition nodes yet — those land once the segment-style
 * rule engine is wired in as a Condition node type). graph_json shape:
 *
 *   {"nodes": [{"id": "n1", "type": "trigger", "config": {...}}, ...],
 *    "edges": [{"source": "n1", "target": "n2"}, ...]}
 */
class YSRTech_EmailCampaigns_Model_Flow_Engine
{
    /**
     * Start a customer at a flow's trigger node. Called by observers
     * (e.g. onOrderPlaced) when a real trigger event fires.
     */
    public function enroll(YSRTech_EmailCampaigns_Model_Flow $flow, int $customerId, string $email, array $context = []): void
    {
        if ($flow->getStatus() !== YSRTech_EmailCampaigns_Model_Flow::STATUS_ACTIVE) {
            return;
        }
        $graph = $this->_graph($flow);
        $trigger = $this->_findTriggerNode($graph);
        if (!$trigger) {
            return;
        }

        Mage::getModel('ysrtech_emailcampaigns/flow_enrollment')->addData([
            'flow_id'         => (int) $flow->getId(),
            'customer_id'     => $customerId,
            'email'           => $email,
            'current_node_id' => $trigger['id'],
            'status'          => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_ACTIVE,
            'enrolled_at'     => Varien_Date::now(),
            'context_json'    => Mage::helper('core')->jsonEncode($context),
        ])->save();
    }

    /**
     * Cron entry point: advance every enrollment that's ready to move
     * (active, or waiting with its delay elapsed).
     */
    public function processEnrollments(): void
    {
        $collection = Mage::getResourceModel('ysrtech_emailcampaigns/flow_enrollment_collection')
            ->addFieldToFilter('status', ['in' => [
                YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_ACTIVE,
                YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_WAITING,
            ]]);

        foreach ($collection as $enrollment) {
            $waitUntil = $enrollment->getWaitUntil();
            if ($enrollment->getStatus() === YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_WAITING
                && $waitUntil && strtotime($waitUntil) > time()
            ) {
                continue;
            }
            try {
                $this->_advance($enrollment);
            } catch (Throwable $e) {
                Mage::logException($e);
            }
        }
    }

    private function _advance(YSRTech_EmailCampaigns_Model_Flow_Enrollment $enrollment): void
    {
        /** @var YSRTech_EmailCampaigns_Model_Flow $flow */
        $flow = Mage::getModel('ysrtech_emailcampaigns/flow')->load($enrollment->getFlowId());
        if (!$flow->getId() || $flow->getStatus() !== YSRTech_EmailCampaigns_Model_Flow::STATUS_ACTIVE) {
            $enrollment->addData(['status' => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_EXITED])->save();
            return;
        }

        $graph = $this->_graph($flow);
        $nodeId = $enrollment->getCurrentNodeId();
        $node = $graph['nodesById'][$nodeId] ?? null;
        if (!$node) {
            $enrollment->addData(['status' => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_EXITED])->save();
            return;
        }

        // Both the trigger node and an elapsed delay are pass-through: step
        // onto whatever follows rather than re-evaluating the same node,
        // which for a delay would just reset its wait_until forever and the
        // enrollment would never advance. processEnrollments() only reaches
        // a 'waiting' enrollment here once wait_until has already elapsed.
        $isElapsedDelay = $enrollment->getStatus() === YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_WAITING
            && $node['type'] === 'delay';
        if ($node['type'] === 'trigger' || $isElapsedDelay) {
            $nextId = $graph['next'][$nodeId] ?? null;
            if (!$nextId) {
                $enrollment->addData(['status' => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_COMPLETED])->save();
                return;
            }
            $nodeId = $nextId;
            $node = $graph['nodesById'][$nodeId];
            $this->_log($enrollment, $nodeId, 'entered');
        }

        switch ($node['type']) {
            case 'delay':
                $enrollment->addData([
                    'current_node_id' => $nodeId,
                    'status'          => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_WAITING,
                    'wait_until'      => $this->_resolveDelay($node['config'] ?? []),
                ])->save();
                break;

            case 'action_send_email':
                $this->_sendNode($enrollment, $flow, $node);
                $nextId = $graph['next'][$nodeId] ?? null;
                $enrollment->addData($nextId
                    ? ['current_node_id' => $nextId, 'status' => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_ACTIVE]
                    : ['current_node_id' => $nodeId, 'status' => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_COMPLETED]
                )->save();
                break;

            default:
                $this->_log($enrollment, $nodeId, 'exited', "Unknown node type '{$node['type']}'");
                $enrollment->addData(['status' => YSRTech_EmailCampaigns_Model_Flow_Enrollment::STATUS_EXITED])->save();
        }
    }

    private function _sendNode(
        YSRTech_EmailCampaigns_Model_Flow_Enrollment $enrollment,
        YSRTech_EmailCampaigns_Model_Flow $flow,
        array $node
    ): void {
        $templateId = (int) ($node['config']['template_id'] ?? 0);
        /** @var YSRTech_EmailCampaigns_Model_Template $template */
        $template = Mage::getModel('ysrtech_emailcampaigns/template')->load($templateId);
        if (!$template->getId()) {
            $this->_log($enrollment, $node['id'], 'skipped', "Template #{$templateId} not found");
            return;
        }

        $customer = Mage::getModel('customer/customer')->load($enrollment->getCustomerId());
        $trackingToken = Mage::helper('core')->getRandomString(32);

        // A queue row (not just the flow_log entry above) so this send shows up
        // in the same place every other send does: unsubscribe links resolve a
        // tracking_token via the queue table (PreferencesController), and so
        // does open/click tracking (WebhookController) — a flow-triggered send
        // needs both exactly like a campaign-triggered one does.
        /** @var YSRTech_EmailCampaigns_Model_Queue $queueItem */
        $queueItem = Mage::getModel('ysrtech_emailcampaigns/queue')->addData([
            'flow_id'            => (int) $flow->getId(),
            'flow_enrollment_id' => (int) $enrollment->getId(),
            'email'              => $enrollment->getEmail(),
            'customer_id'        => $enrollment->getCustomerId(),
            'status'             => 'sending',
            'tracking_token'     => $trackingToken,
        ]);
        $queueItem->save();

        $helper = Mage::helper('ysrtech_emailcampaigns');
        $isMailgun = $helper->getConfig('sending/transport') === 'mailgun';
        $vars = [
            'customer' => [
                'firstname' => $customer->getFirstname(),
                'lastname'  => $customer->getLastname(),
                'email'     => $customer->getEmail(),
            ],
            'store'           => ['name' => Mage::app()->getStore()->getName()],
            'unsubscribe_url' => $isMailgun
                ? '%recipient.unsubscribe_url%'
                : Mage::getUrl('emailcampaigns/preferences/unsubscribe', ['_token' => $trackingToken]),
            'tracking_token'  => $trackingToken,
        ];

        try {
            $html = Mage::getSingleton('ysrtech_emailcampaigns/renderer')->render($template, $vars);
            $transport = Mage::getSingleton('ysrtech_emailcampaigns/transport_factory')->get();
            $transport->sendBatch(
                [['email' => $enrollment->getEmail(), 'name' => trim((string) $customer->getFirstname()), 'vars' => $vars]],
                (string) $template->getSubject(),
                $html
            );
        } catch (Throwable $e) {
            $queueItem->addData(['status' => 'failed', 'error_message' => substr($e->getMessage(), 0, 60000)])->save();
            $this->_log($enrollment, $node['id'], 'skipped', $e->getMessage());
            return;
        }

        $queueItem->addData(['status' => 'sent', 'sent_at' => Varien_Date::now()])->save();
        $this->_log($enrollment, $node['id'], 'sent');
    }

    private function _resolveDelay(array $config): string
    {
        $value = max(0, (int) ($config['value'] ?? 1));
        $unit = in_array($config['unit'] ?? 'days', ['minutes', 'hours', 'days'], true) ? $config['unit'] : 'days';
        return date('Y-m-d H:i:s', strtotime("+{$value} {$unit}"));
    }

    /** @return array{nodesById: array<string, array>, next: array<string, string>} */
    private function _graph(YSRTech_EmailCampaigns_Model_Flow $flow): array
    {
        $decoded = json_decode((string) $flow->getGraphJson(), true) ?: [];

        $nodesById = [];
        foreach ($decoded['nodes'] ?? [] as $node) {
            $nodesById[$node['id']] = $node;
        }

        // Linear graphs only for now: first outgoing edge from a node wins.
        $next = [];
        foreach ($decoded['edges'] ?? [] as $edge) {
            if (!isset($next[$edge['source']])) {
                $next[$edge['source']] = $edge['target'];
            }
        }

        return ['nodesById' => $nodesById, 'next' => $next];
    }

    private function _findTriggerNode(array $graph): ?array
    {
        foreach ($graph['nodesById'] as $node) {
            if ($node['type'] === 'trigger') {
                return $node;
            }
        }
        return null;
    }

    private function _log(YSRTech_EmailCampaigns_Model_Flow_Enrollment $enrollment, string $nodeId, string $action, string $message = ''): void
    {
        Mage::getModel('ysrtech_emailcampaigns/flow_log')->addData([
            'enrollment_id' => (int) $enrollment->getId(),
            'node_id'       => $nodeId,
            'action'        => $action,
            'message'       => $message,
        ])->save();
    }
}
