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
 * Nothing here persists anything or reads config; it is given data and
 * returns data, which is what makes the conversion testable on its own.
 */
class YSRTech_EmailCampaigns_Model_Automation_Graph
{
    /** Node types the editor's bundle knows how to draw */
    public const NODE_TRIGGER = 'trigger';
    public const NODE_DELAY   = 'delay';
    public const NODE_SEND    = 'action_send_email';

    /** Laid out down the canvas, since a chain is a column */
    private const COLUMN_X   = 250;
    private const FIRST_Y    = 40;
    private const ROW_HEIGHT = 130;

    /**
     * Steps to a graph the editor can draw.
     *
     * @param  string $event    the automation's trigger
     * @param  array  $steps    [['template_id' => int, 'after_days' => int,
     *                           'after_hours' => int, 'after_minutes' => int], ...]
     *                          in sort order
     * @return array{nodes: array, edges: array}
     */
    public function toGraph(string $event, array $steps): array
    {
        $nodes = [[
            'id'       => 'trigger-1',
            'type'     => self::NODE_TRIGGER,
            'position' => ['x' => self::COLUMN_X, 'y' => self::FIRST_Y],
            'config'   => ['trigger_type' => $event],
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
            $elapsed    = $offset;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * A graph back to steps.
     *
     * Walked from the trigger along the edges rather than taken in array
     * order: the editor is free to list nodes however it likes, and the
     * drawn order is the only one that means anything. Anything not reachable
     * from the trigger is dropped, which is also what the engine would do
     * with it.
     *
     * @param  array $graph {nodes, edges}
     * @return array{steps: array, trigger: string|null}
     */
    public function toSteps(array $graph): array
    {
        $nodesById = [];

        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (isset($node['id'])) {
                $nodesById[(string) $node['id']] = (array) $node;
            }
        }

        $next = [];

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');

            // First edge out of a node wins. The editor draws one, and a
            // second would be a branch, which neither it nor the engine has.
            if ($source !== '' && $target !== '' && !isset($next[$source])) {
                $next[$source] = $target;
            }
        }

        $triggerId = null;
        $trigger   = null;

        foreach ($nodesById as $id => $node) {
            if (($node['type'] ?? '') === self::NODE_TRIGGER) {
                $triggerId = $id;
                $trigger   = isset($node['config']['trigger_type'])
                    ? (string) $node['config']['trigger_type']
                    : null;
                break;
            }
        }

        if ($triggerId === null) {
            return ['steps' => [], 'trigger' => null];
        }

        $steps   = [];
        $elapsed = 0;
        $seen    = [];
        $current = $next[$triggerId] ?? null;

        while ($current !== null && isset($nodesById[$current]) && !isset($seen[$current])) {
            // A cycle cannot be walked and the editor can draw one.
            $seen[$current] = true;
            $node = $nodesById[$current];
            $type = (string) ($node['type'] ?? '');

            if ($type === self::NODE_DELAY) {
                $elapsed += $this->_configToMinutes((array) ($node['config'] ?? []));
            } elseif ($type === self::NODE_SEND) {
                $steps[] = [
                    'sort_order'    => count($steps),
                    'template_id'   => (int) ($node['config']['template_id'] ?? 0),
                    // Cumulative: this is what the engine measures from the
                    // trigger, which is the conversion this class is for.
                    'after_days'    => intdiv($elapsed, 1440),
                    'after_hours'   => intdiv($elapsed % 1440, 60),
                    'after_minutes' => $elapsed % 60,
                ];
            }

            $current = $next[$current] ?? null;
        }

        return ['steps' => $steps, 'trigger' => $trigger];
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
