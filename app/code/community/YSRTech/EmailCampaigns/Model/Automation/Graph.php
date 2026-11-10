<?php
/**
 * Translates between the visual editor's graph and an automation's steps.
 *
 * The editor is React Flow, carried over from the other line of development
 * along with its built bundle, and it speaks in nodes and edges:
 *
 *   trigger -> delay(2 days) -> send(A) -> delay(5 days) -> send(B)
 *
 * The engine here speaks in ordered steps, and a step's delay is measured
 * from the moment the chain was triggered rather than from the step before
 * it, so the same chain is:
 *
 *   step 0: after 2 days,  template A
 *   step 1: after 7 days,  template B
 *
 * That difference is the whole of this class and the reason it exists rather
 * than the controller doing it inline. A diagram reads relatively - wait two
 * days, send, wait five more - and the steps are absolute, so going one way
 * accumulates and going the other way subtracts. Getting it backwards does
 * not fail loudly: it sends a whole chain at the wrong times.
 *
 * It is also the one place that says what a drawable flow must be before it
 * may go live. The canvas mirrors the cheap rules for immediate feedback, but
 * a browser can be made to post anything, so validate() here is the rule and
 * the canvas is a courtesy.
 *
 * Nothing here persists anything or reads config; it is given data and
 * returns data, which is what makes the conversion testable on its own. What
 * it needs to know about the world - which templates exist, which triggers
 * are real - is handed in as context.
 */
class YSRTech_EmailCampaigns_Model_Automation_Graph
{
    /** Node types the editor's bundle knows how to draw */
    public const NODE_TRIGGER = 'trigger';
    public const NODE_DELAY   = 'delay';
    public const NODE_SEND    = 'action_send_email';

    /**
     * Bounds. A flow is a handful of messages over days or weeks; these are
     * far above anything drawn by a person and far below anything that would
     * hurt, which is the point - what crosses them is a mistake or an attack,
     * and it is refused rather than trimmed to fit, because a flow quietly
     * shortened is a flow that does something other than what was drawn.
     */
    public const MAX_BYTES         = 262144;
    public const MAX_NODES         = 200;
    public const MAX_SENDS         = 20;
    public const MAX_DELAY_MINUTES = 525600; // 365 days

    /** Laid out down the canvas, since a chain is a column */
    private const COLUMN_X   = 250;
    private const FIRST_Y    = 40;
    private const ROW_HEIGHT = 130;

    /**
     * The posted drawing, read and bounded.
     *
     * @param  mixed $json
     * @return array{nodes: array, edges: array}
     * @throws Mage_Core_Exception when it is not a drawing at all
     */
    public function decode($json): array
    {
        if (!is_string($json) || $json === '') {
            Mage::throwException($this->_h()->__('The canvas sent something this could not read.'));
        }

        // Before decoding: the cap is there so that nothing has to parse it.
        if (strlen($json) > self::MAX_BYTES) {
            Mage::throwException($this->_h()->__(
                'This flow is too large to save (%d KB at most).',
                intdiv(self::MAX_BYTES, 1024)
            ));
        }

        $graph = json_decode($json, true, 16);

        if (!is_array($graph)) {
            Mage::throwException($this->_h()->__('The canvas sent something this could not read.'));
        }

        return $this->normalise($graph);
    }

    /**
     * Whatever arrived, reduced to the shape and ranges this class works in.
     *
     * Everything after this can index into nodes and edges without asking
     * what they are. Only what is understood is kept: an unknown key on a
     * node is dropped here rather than stored and handed back to a browser
     * later.
     *
     * @param  array $graph
     * @return array{nodes: array, edges: array}
     * @throws Mage_Core_Exception when the structure itself is wrong
     */
    public function normalise(array $graph): array
    {
        $h        = $this->_h();
        $rawNodes = $graph['nodes'] ?? [];
        $rawEdges = $graph['edges'] ?? [];

        if (!is_array($rawNodes) || !is_array($rawEdges)) {
            Mage::throwException($h->__('The canvas sent something this could not read.'));
        }

        if (count($rawNodes) > self::MAX_NODES) {
            Mage::throwException($h->__('A flow can have %d steps at most.', self::MAX_NODES));
        }

        // A linear chain has fewer edges than nodes; twice over is generous
        if (count($rawEdges) > self::MAX_NODES * 2) {
            Mage::throwException($h->__('This flow has more connections than it could need.'));
        }

        $nodes = [];
        $seen  = [];

        foreach ($rawNodes as $node) {
            $id   = is_array($node) && is_scalar($node['id'] ?? null) ? (string) $node['id'] : '';
            $type = is_array($node) && is_string($node['type'] ?? null) ? $node['type'] : '';

            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)
                || !in_array($type, [self::NODE_TRIGGER, self::NODE_DELAY, self::NODE_SEND], true)
            ) {
                Mage::throwException($h->__('The canvas sent a step this could not read.'));
            }

            if (isset($seen[$id])) {
                Mage::throwException($h->__('Two steps in this flow share an id.'));
            }

            $seen[$id] = true;
            $config    = is_array($node['config'] ?? null) ? $node['config'] : [];
            $position  = is_array($node['position'] ?? null) ? $node['position'] : [];

            $nodes[] = [
                'id'       => $id,
                'type'     => $type,
                'position' => [
                    'x' => $this->_int($position['x'] ?? self::COLUMN_X, -100000, 100000),
                    'y' => $this->_int($position['y'] ?? self::FIRST_Y, -100000, 100000),
                ],
                'config'   => $this->_normaliseConfig($type, $config),
            ];
        }

        $edges = [];

        foreach ($rawEdges as $edge) {
            if (!is_array($edge) || !is_scalar($edge['source'] ?? null) || !is_scalar($edge['target'] ?? null)) {
                Mage::throwException($h->__('The canvas sent a connection this could not read.'));
            }

            $edges[] = [
                'source' => substr((string) $edge['source'], 0, 64),
                'target' => substr((string) $edge['target'], 0, 64),
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * What stands between this drawing and going live.
     *
     * A draft may have any of these and still be saved - that is what a
     * draft is for - except the ones marked fatal, which describe a drawing
     * that cannot be stored as steps at all.
     *
     * @param  array $graph   {nodes, edges}
     * @param  array $context template_ids: int[] of templates that exist;
     *                        events: string[] of valid trigger codes. Either
     *                        left out means that check is not made.
     * @return array<int, array{node: string|null, message: string, fatal: bool}>
     */
    public function validate(array $graph, array $context = []): array
    {
        $h = $this->_h();

        try {
            $graph = $this->normalise($graph);
        } catch (Mage_Core_Exception $e) {
            return [['node' => null, 'message' => $e->getMessage(), 'fatal' => true]];
        }

        $problems = [];
        $add      = static function ($node, string $message, bool $fatal = false) use (&$problems): void {
            $problems[] = ['node' => $node, 'message' => $message, 'fatal' => $fatal];
        };

        $walk  = $this->_walk($graph);
        $nodes = $walk['nodes'];

        foreach ($walk['dangling'] as $edge) {
            $add(
                isset($nodes[$edge['source']]) ? $edge['source'] : (isset($nodes[$edge['target']]) ? $edge['target'] : null),
                $h->__('A connection leads to a step that is not in the flow.'),
                true
            );
        }

        if (!$walk['triggers']) {
            $add(null, $h->__('The flow has no trigger, so nothing would ever start it.'));
        }

        foreach (array_slice($walk['triggers'], 1) as $extra) {
            $add($extra, $h->__('A flow starts from one trigger. Remove this one.'));
        }

        if ($walk['triggers']) {
            $triggerId = $walk['triggers'][0];
            $config    = $nodes[$triggerId]['config'];
            $source    = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;

            if ($config['trigger_type'] === ''
                || (isset($context['events']) && !in_array($config['trigger_type'], $context['events'], true))
            ) {
                $add($triggerId, $h->__('Choose what starts this flow.'));
            } elseif ($config['trigger_type'] === $source::ORDER_STATUS && $config['order_status'] === '') {
                $add($triggerId, $h->__('Choose which order status starts this flow.'));
            } elseif ($config['trigger_type'] === $source::PRODUCT_PURCHASED && $config['product_id'] < 1) {
                $add($triggerId, $h->__('Give the id of the product this flow watches for.'));
            }
        }

        foreach ($walk['branches'] as $id) {
            $add($id, $h->__('Only one connection can leave a step. Flows here do not branch.'));
        }

        if ($walk['cycle'] !== null) {
            $add($walk['cycle'], $h->__('The flow loops back on itself here, so it would never end.'));
        }

        $onChain   = array_flip($walk['chain']);
        $templates = isset($context['template_ids']) ? array_flip(array_map('intval', $context['template_ids'])) : null;
        $sends     = 0;
        $elapsed   = 0;
        $tooLong   = false;

        foreach ($walk['chain'] as $position => $id) {
            $node = $nodes[$id];

            if ($node['type'] === self::NODE_SEND && ++$sends === self::MAX_SENDS + 1) {
                $add($id, $h->__('A flow can send %d messages at most.', self::MAX_SENDS), true);
            }

            if ($node['type'] === self::NODE_DELAY) {
                $elapsed += $this->_configToMinutes($node['config']);

                if ($elapsed > self::MAX_DELAY_MINUTES && !$tooLong) {
                    $tooLong = true;
                    $add($id, $h->__('The waits add up to more than %d days.', intdiv(self::MAX_DELAY_MINUTES, 1440)), true);
                }

                // Not wrong enough to send at the wrong time, but it is a
                // step that does nothing, and nobody draws one on purpose.
                if ($position === count($walk['chain']) - 1 && $walk['cycle'] === null) {
                    $add($id, $h->__('Nothing follows this wait. Add a message after it or remove it.'));
                }
            }
        }

        foreach ($nodes as $id => $node) {
            if ($node['type'] !== self::NODE_TRIGGER && !isset($onChain[$id])) {
                $add($id, $h->__('This step is not connected to the chain from the trigger, so it would never run.'));
            }

            if ($node['type'] === self::NODE_DELAY && $node['config']['value'] < 1) {
                $add($id, $h->__('A wait has to be at least 1.'));
            }

            if ($node['type'] === self::NODE_SEND) {
                if ($node['config']['template_id'] < 1) {
                    $add($id, $h->__('Choose the template this step sends.'));
                } elseif ($templates !== null && !isset($templates[$node['config']['template_id']])) {
                    $add($id, $h->__('The template this step used no longer exists. Choose another.'));
                }
            }
        }

        if ($walk['triggers'] && $sends === 0) {
            $add(null, $h->__('The flow sends nothing yet. Add a Send Email step after the trigger.'));
        }

        return $problems;
    }

    /**
     * Steps to a graph the editor can draw.
     *
     * @param  string $event    the automation's trigger
     * @param  array  $steps    [['template_id' => int, 'after_days' => int,
     *                           'after_hours' => int, 'after_minutes' => int], ...]
     *                          in sort order
     * @param  array  $trigger  the trigger's conditions: order_status, product_id
     * @return array{nodes: array, edges: array}
     */
    public function toGraph(string $event, array $steps, array $trigger = []): array
    {
        $nodes = [[
            'id'       => 'trigger-1',
            'type'     => self::NODE_TRIGGER,
            'position' => ['x' => self::COLUMN_X, 'y' => self::FIRST_Y],
            'config'   => $this->_normaliseConfig(self::NODE_TRIGGER, ['trigger_type' => $event] + $trigger),
        ]];
        $edges = [];

        $previousId = 'trigger-1';
        $elapsed    = 0;
        $row        = 1;
        $n          = 0;

        foreach ($steps as $step) {
            $offset = $this->_stepMinutes($step);
            /*
             * The gap since the step before, not the step's own offset. A
             * second step at day 7 following one at day 2 is drawn as "wait
             * five days", because that is what somebody reading the diagram
             * is being told will happen.
             *
             * max(0): steps out of order, or two at the same offset, would
             * otherwise produce a negative wait. Nothing stops an admin
             * saving that through the form, and a diagram that refuses to
             * draw is worse than one that draws it as "straight away".
             */
            $wait = max(0, $offset - $elapsed);

            if ($wait > 0) {
                $delayId = 'delay-' . (++$n);
                $nodes[] = [
                    'id'       => $delayId,
                    'type'     => self::NODE_DELAY,
                    'position' => ['x' => self::COLUMN_X, 'y' => self::FIRST_Y + (self::ROW_HEIGHT * $row++)],
                    'config'   => $this->_waitToConfig($wait),
                ];
                $edges[]    = ['source' => $previousId, 'target' => $delayId];
                $previousId = $delayId;
            }

            $sendId  = 'send-' . (++$n);
            $nodes[] = [
                'id'       => $sendId,
                'type'     => self::NODE_SEND,
                'position' => ['x' => self::COLUMN_X, 'y' => self::FIRST_Y + (self::ROW_HEIGHT * $row++)],
                'config'   => ['template_id' => (int) ($step['template_id'] ?? 0)],
            ];
            $edges[]    = ['source' => $previousId, 'target' => $sendId];
            $previousId = $sendId;
            // Never backwards, or the wait after an out-of-order step would
            // be measured from a moment the chain had already passed.
            $elapsed    = max($elapsed, $offset);
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A graph back to steps.
     *
     * Walked from the trigger along the edges rather than taken in array
     * order: the editor is free to list nodes however it likes, and the
     * drawn order is the only one that means anything. Anything not reachable
     * from the trigger is left out, which is also what the engine would do
     * with it - and validate() says so to whoever drew it.
     *
     * A send step with no template yet is returned with template_id 0. That
     * is a draft's business; whoever stores the steps decides what to do
     * about it.
     *
     * @param  array $graph {nodes, edges}
     * @return array{steps: array, trigger: string|null, order_status: string|null, product_id: int|null}
     * @throws Mage_Core_Exception when the drawing cannot be expressed as
     *                             steps: malformed, a connection to a step
     *                             that is not there, too many messages, or
     *                             waits beyond the bound
     */
    public function toSteps(array $graph): array
    {
        $h     = $this->_h();
        $graph = $this->normalise($graph);
        $walk  = $this->_walk($graph);
        $nodes = $walk['nodes'];

        if ($walk['dangling']) {
            Mage::throwException($h->__('A connection leads to a step that is not in the flow.'));
        }

        if (!$walk['triggers']) {
            return ['steps' => [], 'trigger' => null, 'order_status' => null, 'product_id' => null];
        }

        $steps   = [];
        $elapsed = 0;

        foreach ($walk['chain'] as $id) {
            $node = $nodes[$id];

            if ($node['type'] === self::NODE_DELAY) {
                $elapsed += $this->_configToMinutes($node['config']);

                if ($elapsed > self::MAX_DELAY_MINUTES) {
                    Mage::throwException(
                        $h->__('The waits add up to more than %d days.', intdiv(self::MAX_DELAY_MINUTES, 1440))
                    );
                }
            } elseif ($node['type'] === self::NODE_SEND) {
                if (count($steps) === self::MAX_SENDS) {
                    Mage::throwException($h->__('A flow can send %d messages at most.', self::MAX_SENDS));
                }

                $steps[] = [
                    'sort_order'    => count($steps),
                    'template_id'   => $node['config']['template_id'],
                    // Cumulative: this is what the engine measures from the
                    // trigger, which is the conversion this class is for.
                    'after_days'    => intdiv($elapsed, 1440),
                    'after_hours'   => intdiv($elapsed % 1440, 60),
                    'after_minutes' => $elapsed % 60,
                ];
            }
        }

        $config = $nodes[$walk['triggers'][0]]['config'];

        return [
            'steps'        => $steps,
            'trigger'      => $config['trigger_type'] !== '' ? $config['trigger_type'] : null,
            'order_status' => $config['order_status'] !== '' ? $config['order_status'] : null,
            'product_id'   => $config['product_id'] > 0 ? $config['product_id'] : null,
        ];
    }

    /**
     * The one reading of the drawing's structure that validate() and
     * toSteps() share, so they cannot come to disagree about what the chain
     * is.
     *
     * @param  array $graph normalised
     * @return array{nodes: array, triggers: string[], chain: string[], cycle: string|null,
     *               branches: string[], dangling: array}
     */
    private function _walk(array $graph): array
    {
        $nodes    = [];
        $triggers = [];

        foreach ($graph['nodes'] as $node) {
            $nodes[$node['id']] = $node;

            if ($node['type'] === self::NODE_TRIGGER) {
                $triggers[] = $node['id'];
            }
        }

        $next     = [];
        $branches = [];
        $dangling = [];

        foreach ($graph['edges'] as $edge) {
            if (!isset($nodes[$edge['source']]) || !isset($nodes[$edge['target']])) {
                $dangling[] = $edge;
                continue;
            }

            // First edge out of a node wins. The editor draws one, and a
            // second would be a branch, which neither it nor the engine has.
            if (isset($next[$edge['source']])) {
                if ($next[$edge['source']] !== $edge['target']) {
                    $branches[$edge['source']] = true;
                }

                continue;
            }

            $next[$edge['source']] = $edge['target'];
        }

        $chain = [];
        $cycle = null;

        if ($triggers) {
            $seen    = [$triggers[0] => true];
            $at      = $triggers[0];
            $current = $next[$at] ?? null;

            while ($current !== null) {
                // A cycle cannot be walked and the editor can draw one. An
                // edge into any trigger counts: nothing comes before a start.
                if (isset($seen[$current]) || $nodes[$current]['type'] === self::NODE_TRIGGER) {
                    $cycle = $at;
                    break;
                }

                $seen[$current] = true;
                $chain[]        = $current;
                $at             = $current;
                $current        = $next[$current] ?? null;
            }
        }

        return [
            'nodes'    => $nodes,
            'triggers' => $triggers,
            'chain'    => $chain,
            'cycle'    => $cycle,
            'branches' => array_keys($branches),
            'dangling' => $dangling,
        ];
    }

    /**
     * @param  string $type
     * @param  array  $config
     * @return array
     */
    private function _normaliseConfig(string $type, array $config): array
    {
        if ($type === self::NODE_TRIGGER) {
            return [
                'trigger_type' => $this->_code($config['trigger_type'] ?? ''),
                'order_status' => $this->_code($config['order_status'] ?? ''),
                'product_id'   => $this->_int($config['product_id'] ?? 0, 0, 4294967295),
            ];
        }

        if ($type === self::NODE_DELAY) {
            $unit = $config['unit'] ?? 'days';

            return [
                // Zero and below are kept as zero so validate() can say so;
                // the top is one bound's worth of minutes, so no sum of two
                // hundred of them comes anywhere near overflowing.
                'value' => $this->_int($config['value'] ?? 0, 0, self::MAX_DELAY_MINUTES + 1),
                'unit'  => in_array($unit, ['minutes', 'hours', 'days'], true) ? $unit : 'days',
            ];
        }

        return ['template_id' => $this->_int($config['template_id'] ?? 0, 0, 4294967295)];
    }

    /**
     * @param  mixed $value
     * @return int within [$min, $max]
     */
    private function _int($value, int $min, int $max): int
    {
        // A float past PHP_INT_MAX casts to something undefined, so it is
        // bounded as a float first.
        $number = is_numeric($value) ? (float) $value : 0.0;

        return (int) max($min, min($max, round($number)));
    }

    /**
     * A short identifier - an event or status code - or nothing.
     *
     * @param  mixed $value
     */
    private function _code($value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $value) ? $value : '';
    }

    /**
     * @return YSRTech_EmailCampaigns_Helper_Data
     */
    private function _h()
    {
        return Mage::helper('ysrtech_emailcampaigns');
    }

    /**
     * @param  array $step
     * @return int minutes after the trigger
     */
    private function _stepMinutes(array $step): int
    {
        return ((int) ($step['after_days'] ?? 0) * 1440)
            + ((int) ($step['after_hours'] ?? 0) * 60)
            + (int) ($step['after_minutes'] ?? 0);
    }

    /**
     * A wait in minutes, in the largest unit that expresses it exactly, since
     * the editor offers one number and one unit.
     *
     * @return array{value: int, unit: string}
     */
    private function _waitToConfig(int $minutes): array
    {
        if ($minutes % 1440 === 0) {
            return ['value' => intdiv($minutes, 1440), 'unit' => 'days'];
        }

        if ($minutes % 60 === 0) {
            return ['value' => intdiv($minutes, 60), 'unit' => 'hours'];
        }

        return ['value' => $minutes, 'unit' => 'minutes'];
    }

    /**
     * @param  array $config {value, unit}
     * @return int minutes
     */
    private function _configToMinutes(array $config): int
    {
        $value = max(0, (int) ($config['value'] ?? 0));

        switch ((string) ($config['unit'] ?? 'days')) {
            case 'minutes':
                return $value;
            case 'hours':
                return $value * 60;
            default:
                return $value * 1440;
        }
    }
}
