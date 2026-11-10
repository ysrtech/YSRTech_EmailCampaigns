<?php
/**
 * The automation canvas's rules and the engine's guarantees around an edit.
 *
 *   cd <openmage-core> && php <module>/dev/tests/automation.php
 *
 * Covers the graph translator (round trips, every rejection, the bounds),
 * publishing (an invalid flow is never switched on, a live one is not
 * changed, each publication is a version), and the queue (what a row is
 * stamped with, that a trigger is queued once, and that a queued message
 * survives its step being edited or removed and still knows its template).
 *
 * Nothing is sent. Every step used here is days away from due, and the one
 * piece of the sender that matters - which template a queued row resolves
 * to - is asked directly. Everything created is removed at the end, and at
 * the start too in case an earlier run died halfway.
 */
require_once 'app/Mage.php';
Mage::app('admin');

$res  = Mage::getSingleton('core/resource');
$db   = $res->getConnection('core_write');
$t    = fn ($n) => $res->getTableName($n);
$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };

$source = YSRTech_EmailCampaigns_Model_System_Config_Source_Automation_Event::class;
$G      = YSRTech_EmailCampaigns_Model_Automation_Graph::class;
/** @var YSRTech_EmailCampaigns_Model_Automation_Graph $graph */
$graph  = Mage::getSingleton('ysrtech_emailcampaigns/automation_graph');

$marker = '[automation-test]';
$clean  = function () use ($db, $t, $marker) {
    // Queue and step rows go with the automation, by its foreign keys
    $db->delete($t('ysrtech_emailcampaigns/automation'), ['name LIKE ?' => $marker . '%']);
    $db->delete($t('ysrtech_emailcampaigns/template'), ['name LIKE ?' => $marker . '%']);
};
$clean();

$throws = function (callable $fn): ?string {
    try {
        $fn();
    } catch (Mage_Core_Exception $e) {
        return $e->getMessage();
    }

    return null;
};
$messages = fn (array $problems) => implode(' | ', array_column($problems, 'message'));
$has      = function (array $problems, string $needle, $node = false, ?bool $fatal = null): bool {
    foreach ($problems as $p) {
        if (stripos($p['message'], $needle) !== false
            && ($node === false || $p['node'] === $node)
            && ($fatal === null || (bool) $p['fatal'] === $fatal)
        ) {
            return true;
        }
    }

    return false;
};

// Small builders, so each case below reads as the drawing it is
$trigger = fn (array $config = [], string $id = 'trigger-1') => ['id' => $id, 'type' => $G::NODE_TRIGGER, 'config' => $config + ['trigger_type' => $source::NEW_ORDER]];
$delay   = fn (string $id, $value, string $unit = 'days') => ['id' => $id, 'type' => $G::NODE_DELAY, 'config' => ['value' => $value, 'unit' => $unit]];
$send    = fn (string $id, $template) => ['id' => $id, 'type' => $G::NODE_SEND, 'config' => ['template_id' => $template]];
$chain   = function (array $nodes): array {
    $edges = [];

    for ($i = 1; $i < count($nodes); $i++) {
        $edges[] = ['source' => $nodes[$i - 1]['id'], 'target' => $nodes[$i]['id']];
    }

    return ['nodes' => $nodes, 'edges' => $edges];
};

// Two templates of our own, so nothing depends on what staging was seeded with
$templateIds = [];

foreach (['A', 'B'] as $letter) {
    $db->insert($t('ysrtech_emailcampaigns/template'), ['name' => "{$marker} {$letter}", 'subject' => $letter, 'html' => '<p>x</p>']);
    $templateIds[] = (int) $db->lastInsertId();
}

[$tplA, $tplB] = $templateIds;
$missing       = $tplB + 100000;
$context       = ['template_ids' => $templateIds, 'events' => array_keys(Mage::getSingleton('ysrtech_emailcampaigns/system_config_source_automation_event')->toArray())];

/* ---------------------------------------------------------------------- *
 * Graph: round trips
 * ---------------------------------------------------------------------- */

$steps = [
    ['template_id' => $tplA, 'after_days' => 2, 'after_hours' => 0, 'after_minutes' => 0],
    ['template_id' => $tplB, 'after_days' => 7, 'after_hours' => 3, 'after_minutes' => 30],
    ['template_id' => $tplA, 'after_days' => 7, 'after_hours' => 3, 'after_minutes' => 30],
];
$drawn = $graph->toGraph($source::ORDER_STATUS, $steps, ['order_status' => 'complete']);
$back  = $graph->toSteps($drawn);
$strip = fn (array $rows) => array_map(fn ($r) => [$r['template_id'], $r['after_days'], $r['after_hours'], $r['after_minutes']], $rows);

$ok('steps -> graph -> steps comes back the same', $strip($back['steps']) === $strip($steps));
$ok('the gap is drawn relative: day 2 then day 7h3m30 is a wait of 7,410 minutes', $drawn['nodes'][3]['config'] === ['value' => 7410, 'unit' => 'minutes']);
$ok('two messages at the same moment are drawn with no wait between', count($drawn['nodes']) === 6);
$ok('the trigger and its condition survive the round trip', $back['trigger'] === $source::ORDER_STATUS && $back['order_status'] === 'complete' && $back['product_id'] === null);
$ok('a drawing made from valid steps has no problems', $graph->validate($drawn, $context) === []);
$ok('graph -> steps -> graph is stable', $graph->toGraph($source::ORDER_STATUS, $back['steps'], ['order_status' => 'complete']) === $drawn);
$ok('node order in the payload does not matter, only the edges', $strip($graph->toSteps(['nodes' => array_reverse($drawn['nodes']), 'edges' => array_reverse($drawn['edges'])])['steps']) === $strip($steps));

$good = $chain([$trigger(), $delay('d1', 5), $send('s1', $tplA), $delay('d2', 5), $send('s2', $tplB)]);
$ok('a plain valid chain has no problems', $graph->validate($good, $context) === []);

/* ---------------------------------------------------------------------- *
 * Graph: each rejection, pinned to the node it concerns
 * ---------------------------------------------------------------------- */

$p = $graph->validate($chain([$trigger(), $send('s1', 0)]), $context);
$ok('send with no template', $has($p, 'Choose the template', 's1', false));

$p = $graph->validate($chain([$trigger(), $send('s1', $missing)]), $context);
$ok('send whose template no longer exists', $has($p, 'no longer exists', 's1', false));

$p = $graph->validate($chain([$trigger(), $delay('d1', 0), $send('s1', $tplA)]), $context);
$ok('zero delay', $has($p, 'at least 1', 'd1'));

$p = $graph->validate($chain([$trigger(), $delay('d1', -3), $send('s1', $tplA)]), $context);
$ok('negative delay', $has($p, 'at least 1', 'd1'));
$ok('a negative delay never becomes a negative offset', $graph->toSteps($chain([$trigger(), $delay('d1', -3), $send('s1', $tplA)]))['steps'][0]['after_days'] === 0);

$orphan = $chain([$trigger(), $send('s1', $tplA)]);
$orphan['nodes'][] = $send('s2', $tplB);
$p = $graph->validate($orphan, $context);
$ok('node not connected to the chain', $has($p, 'not connected', 's2') && !$has($p, 'not connected', 's1'));
$ok('an unconnected step is left out of the steps', count($graph->toSteps($orphan)['steps']) === 1);

$two = $chain([$trigger(), $send('s1', $tplA)]);
$two['nodes'][] = $trigger([], 'trigger-2');
$p = $graph->validate($two, $context);
$ok('more than one trigger', $has($p, 'one trigger', 'trigger-2') && !$has($p, 'one trigger', 'trigger-1'));

$loop = $chain([$trigger(), $delay('d1', 1), $send('s1', $tplA)]);
$loop['edges'][] = ['source' => 's1', 'target' => 'd1'];
$p = $graph->validate($loop, $context);
$ok('cycle', $has($p, 'loops back', 's1'));
$ok('a cycle is walked once and ends', count($graph->toSteps($loop)['steps']) === 1);

$p = $graph->validate($chain([$trigger(), $delay('d1', 1)]), $context);
$ok('no send step at all', $has($p, 'sends nothing', null));
$ok('a wait with nothing after it', $has($p, 'Nothing follows', 'd1'));

$p = $graph->validate(['nodes' => [$send('s1', $tplA)], 'edges' => []], $context);
$ok('no trigger', $has($p, 'no trigger', null));

$p = $graph->validate($chain([$trigger(['trigger_type' => $source::ORDER_STATUS]), $send('s1', $tplA)]), $context);
$ok('order-status trigger without a status', $has($p, 'which order status', 'trigger-1'));

$p = $graph->validate($chain([$trigger(['trigger_type' => $source::PRODUCT_PURCHASED, 'product_id' => 0]), $send('s1', $tplA)]), $context);
$ok('product trigger without a product id', $has($p, 'id of the product', 'trigger-1'));

$p = $graph->validate($chain([$trigger(['trigger_type' => 'order_placed']), $send('s1', $tplA)]), $context);
$ok('a trigger code that is not one of the module\'s (the old default, order_placed)', $has($p, 'what starts', 'trigger-1'));

$branch = $chain([$trigger(), $send('s1', $tplA)]);
$branch['nodes'][] = $send('s2', $tplB);
$branch['edges'][] = ['source' => 'trigger-1', 'target' => 's2'];
$ok('a second connection out of a step', $has($graph->validate($branch, $context), 'do not branch', 'trigger-1'));

/* ---------------------------------------------------------------------- *
 * Graph: caps and bounds - refused, not trimmed
 * ---------------------------------------------------------------------- */

$many = [$trigger()];
for ($i = 1; $i <= $G::MAX_SENDS + 1; $i++) {
    $many[] = $send("s{$i}", $tplA);
}
$tooMany = $chain($many);
$ok('21 sends: a fatal problem on the 21st', $has($graph->validate($tooMany, $context), 'at most', 's21', true));
$ok('21 sends: toSteps refuses rather than returning 20', $throws(fn () => $graph->toSteps($tooMany)) !== null);
array_pop($tooMany['nodes']);
array_pop($tooMany['edges']);
$ok('20 sends is allowed', $graph->validate($tooMany, $context) === [] && count($graph->toSteps($tooMany)['steps']) === 20);

$long = $chain([$trigger(), $delay('d1', 200), $delay('d2', 166), $send('s1', $tplA)]);
$ok('366 days of waiting: a fatal problem on the wait that crosses the line', $has($graph->validate($long, $context), 'more than 365', 'd2', true));
$ok('366 days of waiting: toSteps refuses', $throws(fn () => $graph->toSteps($long)) !== null);
$ok('365 days exactly is allowed', $graph->validate($chain([$trigger(), $delay('d1', 365), $send('s1', $tplA)]), $context) === []);

$dangling = $chain([$trigger(), $send('s1', $tplA)]);
$dangling['edges'][] = ['source' => 's1', 'target' => 'ghost'];
$ok('an edge to a node that is not there: fatal', $has($graph->validate($dangling, $context), 'not in the flow', 's1', true));
$ok('an edge to a node that is not there: toSteps refuses', $throws(fn () => $graph->toSteps($dangling)) !== null);

$crowd = [$trigger()];
for ($i = 0; $i < $G::MAX_NODES; $i++) {
    $crowd[] = $delay("d{$i}", 1);
}
$ok('201 nodes is refused', $throws(fn () => $graph->normalise(['nodes' => $crowd, 'edges' => []])) !== null);
$ok('201 nodes reads as one fatal problem', $has($graph->validate(['nodes' => $crowd, 'edges' => []], $context), 'at most', null, true));

$ok('a payload over 256 KB is refused before it is parsed', $throws(fn () => $graph->decode('{"nodes":[],"edges":[],"pad":"' . str_repeat('x', $G::MAX_BYTES) . '"}')) !== null);
$ok('a payload that is not JSON is refused', $throws(fn () => $graph->decode('nodes=1')) !== null);
$ok('a payload that is JSON but not an object is refused', $throws(fn () => $graph->decode('"hello"')) !== null);
$ok('a payload that is an array instead of a string is refused', $throws(fn () => $graph->decode(['nodes' => []])) !== null);
$ok('nodes that are not a list are refused', $throws(fn () => $graph->decode('{"nodes":"x","edges":[]}')) !== null);
$ok('a node of an unknown type is refused', $throws(fn () => $graph->normalise(['nodes' => [['id' => 'x', 'type' => 'webhook']], 'edges' => []])) !== null);
$ok('a node id made of markup is refused', $throws(fn () => $graph->normalise(['nodes' => [['id' => '<b>', 'type' => $G::NODE_DELAY]], 'edges' => []])) !== null);
$ok('two nodes sharing an id are refused', $throws(fn () => $graph->normalise(['nodes' => [$delay('d1', 1), $delay('d1', 2)], 'edges' => []])) !== null);
$ok('an edge that is not an edge is refused', $throws(fn () => $graph->normalise(['nodes' => [$trigger()], 'edges' => ['x']])) !== null);

$wild = $graph->normalise(['nodes' => [
    ['id' => 'trigger-1', 'type' => $G::NODE_TRIGGER, 'position' => ['x' => 1e30, 'y' => 'abc'], 'config' => ['trigger_type' => ['x'], 'order_status' => '<script>', 'product_id' => -5, 'extra' => 'kept?']],
    $delay('d1', 1e30, 'fortnights'),
    $send('s1', '9e99'),
    $send('s2', '12abc'),
], 'edges' => []]);
$ok('positions are clamped to ints', $wild['nodes'][0]['position'] === ['x' => 100000, 'y' => 0]);
$ok('trigger config is reduced to codes and a non-negative id', $wild['nodes'][0]['config'] === ['trigger_type' => '', 'order_status' => '', 'product_id' => 0]);
$ok('an absurd wait is clamped and an unknown unit falls back to days', $wild['nodes'][1]['config'] === ['value' => $G::MAX_DELAY_MINUTES + 1, 'unit' => 'days']);
$ok('an absurd template id is clamped; a non-number is no template', $wild['nodes'][2]['config']['template_id'] === 4294967295 && $wild['nodes'][3]['config']['template_id'] === 0);

/* ---------------------------------------------------------------------- *
 * Publishing
 * ---------------------------------------------------------------------- */

$load = fn (int $id) => Mage::getModel('ysrtech_emailcampaigns/automation')->load($id);
$row  = fn (int $id) => $db->fetchRow("SELECT * FROM {$t('ysrtech_emailcampaigns/automation')} WHERE automation_id = ?", [$id]);

/** @var YSRTech_EmailCampaigns_Model_Automation $flow */
$flow = Mage::getModel('ysrtech_emailcampaigns/automation');
$flow->setRespectSubscription(0);

$bad    = $chain([$trigger(), $delay('d1', 5), $send('s1', $tplA), $delay('d2', 5), $send('s2', 0)]);
$result = $flow->saveFlow($bad, ['name' => "{$marker} flow"], true, true);
$id     = (int) $flow->getId();

$ok('publishing a flow with a problem saves it', $id > 0);
$ok('...as a draft, never active', $result['active'] === false && $result['demoted'] === true && (int) $row($id)['is_active'] === 0);
$ok('...and reports the problem against its node', $result['problems'] === [['node' => 's2', 'message' => $result['problems'][0]['message']]] && stripos($result['problems'][0]['message'], 'template') !== false);
$ok('...and has not been given a publication date', $row($id)['published_at'] === null);
$ok('the step without a template stays in the drawing, not in the step rows', count($load($id)->getStepRows()) === 1 && count($load($id)->getCanvasGraph()['nodes']) === 5);

$result = $load($id)->saveFlow($bad, ['name' => "{$marker} flow"], false, true);
$ok('the same flow saved as a draft is not "demoted", just a draft with problems', $result['demoted'] === false && count($result['problems']) === 1);

$ok('a fatal problem is not saved even as a draft', $throws(fn () => $load($id)->saveFlow($chain($many), ['name' => "{$marker} flow"], false, true)) !== null
    && count($load($id)->getStepRows()) === 1);
$ok('a flow needs a name', $throws(fn () => $load($id)->saveFlow($good, ['name' => '  '], false, true)) !== null);

$positioned = $good;
$positioned['nodes'][2]['position'] = ['x' => 777, 'y' => 333];
$result = $load($id)->saveFlow($positioned, ['name' => "{$marker} flow"], true, true);
$stored = $row($id);
$ok('publishing a valid flow switches it on', $result['active'] === true && $result['demoted'] === false && $result['problems'] === [] && (int) $stored['is_active'] === 1);
$ok('the first publication is version 1, dated', (int) $stored['version'] === 1 && $stored['published_at'] !== null);
$ok('its steps are the chain as drawn: day 5 A, day 10 B', $strip($load($id)->getStepRows()) === [[$tplA, 5, 0, 0], [$tplB, 10, 0, 0]]);
$ok('the canvas gets its own drawing back, positions and all', $load($id)->getCanvasGraph()['nodes'][2]['position'] === ['x' => 777, 'y' => 333]);

$changed = $chain([$trigger(), $delay('d1', 6), $send('s1', $tplA), $delay('d2', 5), $send('s2', $tplB)]);
$refused = $throws(fn () => $load($id)->saveFlow($changed, ['name' => "{$marker} flow"], true, true));
$ok('a live flow refuses a change to its steps', $refused !== null && stripos($refused, 'live') !== false && $strip($load($id)->getStepRows())[0] === [$tplA, 5, 0, 0]);
$ok('a live flow refuses a change of trigger', $throws(fn () => $load($id)->saveFlow($chain([$trigger(['trigger_type' => $source::SHIPMENT]), $delay('d1', 5), $send('s1', $tplA), $delay('d2', 5), $send('s2', $tplB)]), ['name' => "{$marker} flow"], true, true)) !== null && $row($id)['event'] === $source::NEW_ORDER);
$ok('a live flow refuses a rename', $throws(fn () => $load($id)->saveFlow($positioned, ['name' => "{$marker} renamed"], true, true)) !== null);

$result = $load($id)->saveFlow($positioned, ['name' => "{$marker} flow", 'store_id' => 0], true, true);
$ok('saving a live flow unchanged is allowed and is not a new version', $result['active'] === true && (int) $row($id)['version'] === 1);

// The form's way in: rows, drawn as a chain, through the same method
$formGraph = fn (array $rows) => $graph->toGraph($source::NEW_ORDER, $rows);
$ok('the form cannot change a live flow either', $throws(fn () => $load($id)->saveFlow($formGraph([['template_id' => $tplB, 'after_days' => 1]]), ['name' => "{$marker} flow"], true, false)) !== null);
$ok('the form may save a live flow whose rows are unchanged', $load($id)->saveFlow($formGraph([['template_id' => $tplA, 'after_days' => 5], ['template_id' => $tplB, 'after_days' => 10]]), ['name' => "{$marker} flow", 'cancel_on' => 'order_placed'], true, false)['active'] === true
    && $row($id)['cancel_on'] === 'order_placed' && (int) $row($id)['version'] === 1);

/* ---------------------------------------------------------------------- *
 * Queue: stamping and dedupe
 * ---------------------------------------------------------------------- */

$queueRows = fn (int $objectId) => $db->fetchAll("SELECT * FROM {$t('ysrtech_emailcampaigns/queue')} WHERE automation_id = ? AND object_id = ? ORDER BY step_order", [$id, $objectId]);
$email     = 'automation-test@sync-test.invalid';
$order     = 987650001;

$queued = $load($id)->queue($email, 'order', $order);
$rows   = $queueRows($order);
$ok('a trigger queues every step of the chain', $queued === 2 && count($rows) === 2);
$ok('each row is stamped with its template', array_map('intval', array_column($rows, 'template_id')) === [$tplA, $tplB]);
$ok('...the version that queued it', array_map('intval', array_column($rows, 'automation_version')) === [1, 1]);
$ok('...and its position in the chain', array_map('intval', array_column($rows, 'step_order')) === [0, 1]);
$ok('nothing queued here is due for days', min(array_column($rows, 'send_at')) > gmdate('Y-m-d H:i:s', time() + 4 * 86400));

$ok('the same trigger a second time queues nothing', $load($id)->queue($email, 'order', $order) === 0 && count($queueRows($order)) === 2);
$ok('the same object from the same loaded automation queues nothing either', (function () use ($load, $id, $email, $order) {
    $a = $load($id);
    return $a->queue($email, 'order', $order) + $a->queue($email, 'order', $order) === 0;
})());

// Two requests that both got past the "already queued?" question: only the
// unique key stands between them, so it is asked directly.
$duplicate = null;
try {
    $db->insert($t('ysrtech_emailcampaigns/queue'), ['automation_id' => $id, 'step_order' => 0, 'object_type' => 'order', 'object_id' => $order, 'email' => $email, 'status' => 'pending', 'tracking_token' => 'automationtestdup000000000000001']);
} catch (Exception $e) {
    $duplicate = $e;
}
$isDuplicate = new ReflectionMethod($flow, '_isDuplicateKey');
$isDuplicate->setAccessible(true);
$ok('the unique key refuses a second row for the same object and position', $duplicate !== null && count($queueRows($order)) === 2);
$ok('...and that refusal is recognised as a duplicate', $duplicate !== null && $isDuplicate->invoke($flow, $duplicate) === true);

$other = null;
try {
    $db->insert($t('ysrtech_emailcampaigns/queue'), ['automation_id' => 4294967000, 'step_order' => 0, 'object_type' => 'order', 'object_id' => $order, 'email' => $email, 'status' => 'pending']);
} catch (Exception $e) {
    $other = $e;
}
$ok('a foreign key failure is not mistaken for a duplicate', $other !== null && $isDuplicate->invoke($flow, $other) === false);

$ok('a different object is queued as usual', $load($id)->queue($email, 'order', $order + 1) === 2);

/* ---------------------------------------------------------------------- *
 * A queued message survives an edit
 * ---------------------------------------------------------------------- */

$stale = $load($id); // an observer holding the flow as it was when live

$result = $load($id)->saveFlow($positioned, ['name' => "{$marker} flow"], false, true);
$ok('pausing is a save as draft with nothing else changed', $result['paused'] === true && (int) $row($id)['is_active'] === 0 && (int) $row($id)['version'] === 1);
$ok('a paused flow takes no new entries, even from an object loaded while it was live', $stale->queue($email, 'order', $order + 2) === 0 && $queueRows($order + 2) === []);
$ok('what was queued before the pause is still queued', count($queueRows($order)) === 2);

// Now the edit: the first message swaps template, the second is removed
$edited = $chain([$trigger(), $delay('d1', 1), $send('s1', $tplB)]);
$result = $load($id)->saveFlow($edited, ['name' => "{$marker} flow edited"], false, true);
$rows   = $queueRows($order);
$ok('once paused, the flow can be edited', $result['problems'] === [] && $strip($load($id)->getStepRows()) === [[$tplB, 1, 0, 0]]);
$ok('both queued messages are still there after the edit', count($rows) === 2 && array_column($rows, 'status') === ['pending', 'pending']);
$ok('the one whose step was removed has lost only the pointer', $rows[1]['step_id'] === null && (int) $rows[1]['template_id'] === $tplB);
$ok('the one whose step was changed still points at it', (int) $rows[0]['step_id'] > 0);

$item = fn (array $data) => new Varien_Object($data);
$ok('it is sent with the template it was queued with, not the step\'s new one', $load($id)->resolveTemplateId($item($rows[0])) === $tplA);
$ok('the message whose step is gone still resolves its template', $load($id)->resolveTemplateId($item($rows[1])) === $tplB);
$ok('a row from before templates were stamped falls back to its step', $load($id)->resolveTemplateId($item(['template_id' => null, 'step_id' => $rows[0]['step_id']])) === $tplB);
$ok('a row with neither resolves to nothing rather than to a guess', $load($id)->resolveTemplateId($item(['template_id' => null, 'step_id' => null])) === 0);
$ok('the frozen template is one the sender can load', (bool) Mage::getModel('ysrtech_emailcampaigns/template')->load($load($id)->resolveTemplateId($item($rows[1])))->getId());

// Publish the edited flow: version 2, with a second step added back, which
// gets a brand new step row
$again  = $chain([$trigger(), $delay('d1', 1), $send('s1', $tplB), $delay('d2', 9), $send('s2', $tplA)]);
$result = $load($id)->saveFlow($again, ['name' => "{$marker} flow edited"], true, true);
$ok('going live again is version 2', $result['active'] === true && (int) $row($id)['version'] === 2);
$ok('an object already in the flow is not queued again by the new version, new step row or not', $load($id)->queue($email, 'order', $order) === 0 && count($queueRows($order)) === 2);

$queued = $load($id)->queue($email, 'order', $order + 3);
$rows   = $queueRows($order + 3);
$ok('a new object gets the new chain, stamped version 2', $queued === 2 && array_map('intval', array_column($rows, 'automation_version')) === [2, 2] && array_map('intval', array_column($rows, 'template_id')) === [$tplB, $tplA]);
$ok('the old object\'s rows still say version 1', array_map('intval', array_column($queueRows($order), 'automation_version')) === [1, 1]);

// The form changing the steps makes the stored drawing stale
$load($id)->saveFlow($formGraph([['template_id' => $tplA, 'after_days' => 3]]), ['name' => "{$marker} flow edited"], false, false);
$redrawn = $load($id)->getCanvasGraph();
$ok('after the form changes the steps the canvas is drawn from the steps, not the old drawing', count($redrawn['nodes']) === 3 && $redrawn['nodes'][1]['config'] === ['value' => 3, 'unit' => 'days']);
$ok('the form cannot save a step whose template does not exist', $throws(fn () => $load($id)->saveFlow($formGraph([['template_id' => $missing, 'after_days' => 3]]), ['name' => "{$marker} flow edited"], false, false)) !== null);

$demoted = $load($id)->saveFlow($graph->toGraph('order_placed', [['template_id' => $tplA, 'after_days' => 3]]), ['name' => "{$marker} flow edited"], true, false);
$ok('the form asking for active with a problem gets a draft and the problem', $demoted['demoted'] === true && count($demoted['problems']) === 1 && (int) $row($id)['is_active'] === 0);
$ok('...and a bad trigger code never reaches the event column', $row($id)['event'] === $source::NEW_ORDER);

/* ---------------------------------------------------------------------- */

$clean();
$left = (int) $db->fetchOne("SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/queue')} WHERE email = ?", [$email]);
$ok('everything this test queued is gone', $left === 0);

echo $fail ? "\n{$fail} FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
