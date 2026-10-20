<?php
// Seeds a couple of test customers plus one template/segment/campaign through the
// module's own models, then reindexes the segment and builds the campaign's queue,
// so the staging instance has something to click through immediately after install.
// Invoked by setup.sh with the OpenMage core directory as the working directory.
require_once 'app/Mage.php';
Mage::setIsDeveloperMode(true);
Mage::app('admin');
Mage::register('isSecureArea', true);

// setup.sh always runs against a freshly created database, so these are
// always new rows — no need to check for an existing customer first.
foreach ([
    ['jane@example.com', 'Jane', 'Doe'],
    ['john@example.com', 'John', 'Smith'],
] as [$email, $first, $last]) {
    $customer = Mage::getModel('customer/customer');
    $customer->setWebsiteId(1)->setStore(Mage::app()->getStore(0))
        ->setEmail($email)->setFirstname($first)->setLastname($last)
        ->setPassword('Password123')->setGroupId(1);
    $customer->save();
    echo "customer id={$customer->getId()} email={$email}\n";
}

/** @var YSRTech_EmailCampaigns_Model_Template $template */
$template = Mage::getModel('ysrtech_emailcampaigns/template');
$template->addData([
    'name'      => 'Welcome Series #1',
    'subject'   => 'Welcome to {{store.name}}!',
    'html'      => '<html><body><p>Hi {{customer.firstname}}, welcome!</p>'
        . '<a href="{{unsubscribe_url}}">Unsubscribe</a></body></html>',
    'is_active' => 1,
])->save();
echo "template id={$template->getId()}\n";

/** @var YSRTech_EmailCampaigns_Model_Segment $segment */
$segment = Mage::getModel('ysrtech_emailcampaigns/segment');
$segment->addData(['name' => 'All Active Customers', 'is_active' => 1])->save();
$count = $segment->reindex();
echo "segment id={$segment->getId()} matched={$count}\n";

/** @var YSRTech_EmailCampaigns_Model_Campaign $campaign */
$campaign = Mage::getModel('ysrtech_emailcampaigns/campaign');
$campaign->addData([
    'name'        => 'Staging Test Campaign',
    'subject'     => 'Hello from staging',
    'template_id' => $template->getId(),
    'segment_id'  => $segment->getId(),
    'store_id'    => 0,
    'status'      => YSRTech_EmailCampaigns_Model_Campaign::STATUS_DRAFT,
])->save();
echo "campaign id={$campaign->getId()} (draft — schedule it from the admin grid to build its queue)\n";
