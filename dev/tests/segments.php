<?php
/**
 * Segments: the rules in words, the editor's preview, and the member list.
 *
 *   cd <openmage-core> && php <module>/dev/tests/segments.php
 */
require_once 'app/Mage.php';
Mage::app('admin');

$fail = 0;
$ok   = function (string $what, bool $pass) use (&$fail) { echo ($pass ? 'PASS ' : 'FAIL ') . $what . "\n"; $fail += $pass ? 0 : 1; };
$h    = Mage::helper('ysrtech_emailcampaigns');

$post = ['conditions' => [
    '1'    => ['type' => 'ysrtech_emailcampaigns/segment_condition_combine', 'aggregator' => 'all', 'value' => '1', 'new_child' => ''],
    '1--1' => ['type' => 'ysrtech_emailcampaigns/segment_condition_subscriber', 'attribute' => 'order_count', 'operator' => '>=', 'value' => '2'],
]];

// ---- an unsaved segment can be previewed
$draft   = Mage::getModel('ysrtech_emailcampaigns/segment')->loadPost($post);
$preview = $draft->preview(5);
$ok('preview counts without saving', $preview['count'] > 0 && !$draft->getId());
$ok('preview sample is capped', count($preview['sample']) === min(5, $preview['count']));
$ok('preview sample carries an email', strpos($preview['sample'][0]['email'], '@') !== false);

$everyone = Mage::getModel('ysrtech_emailcampaigns/segment')->loadPost(['conditions' => [
    '1' => ['type' => 'ysrtech_emailcampaigns/segment_condition_combine', 'aggregator' => 'all', 'value' => '1', 'new_child' => ''],
]]);
$ok('rules narrow the list', $preview['count'] < $everyone->preview(0)['count']);
$ok('no conditions reads as no rule lines', $h->segmentRuleLines($everyone) === []);

// ---- saved, the member list is what the preview promised
$segment = Mage::getModel('ysrtech_emailcampaigns/segment')->loadPost($post);
$segment->addData(['name' => 'TEST segments.php', 'is_active' => 1])->save();
$count = $segment->reindex();
$ok('saved count equals preview', $count === $preview['count']);

$lines = $h->segmentRuleLines(Mage::getModel('ysrtech_emailcampaigns/segment')->load($segment->getId()));
$ok('rules in words', count($lines) === 1 && stripos($lines[0], 'Number Of Orders') !== false && strpos($lines[0], '2') !== false);

// The member list on the page is this table joined to the newsletter
$r       = Mage::getSingleton('core/resource');
$members = (int) $r->getConnection('core_read')->fetchOne(
    'SELECT COUNT(*) FROM ' . $r->getTableName('ysrtech_emailcampaigns/segment_subscriber') . ' WHERE segment_id = ?',
    (int) $segment->getId()
);
$ok('membership rows equal the count', $members === $count);

$reach = YSRTech_EmailCampaigns_Model_Campaign::countRecipients([(int) $segment->getId()], [], false);
$ok('reachable never exceeds members', $reach > 0 && $reach <= $count);

$segment->delete();

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
