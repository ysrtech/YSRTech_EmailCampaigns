<?php
/**
 * Synthetic volume for the staging install: enough subscribers, customers,
 * orders, suppressions and sent mail for the dashboard, the grids and the
 * segment builder to look and behave as they would on a real store.
 *
 *   cd <openmage-core> && php <module>/dev/staging/seed-volume.php [subscribers]
 *
 * Everything is invented. Every address is at example.invalid, a domain
 * reserved so that it can never resolve, let alone deliver. The "mailbox
 * provider" each address belongs to is carried in its local part instead
 * (ava.cohen.gmail.17@example.invalid), which is also what the dashboard's
 * provider breakdown falls back to reading on this install.
 *
 * It also switches the store's own mail off (system/smtp/disable) and leaves
 * the module on the store's-own-mail transport with no credentials, so there
 * is no path by which a queue processed here reaches anybody.
 *
 * Written with plain inserts rather than through the models: forty thousand
 * customer saves take the better part of an hour, and nothing here needs the
 * observers that fire on them. Re-running replaces what the last run made
 * and leaves alone anything created by hand.
 */
require_once 'app/Mage.php';
Mage::app('admin');

$subscriberTarget = max(200, (int) ($argv[1] ?? 24000));
mt_srand(20261110);

$resource = Mage::getSingleton('core/resource');
$db       = $resource->getConnection('core_write');
$t        = static fn (string $name) => $resource->getTableName($name);

$firstNames = ['Ava', 'Noah', 'Chaya', 'Moshe', 'Sarah', 'David', 'Rivka', 'Yosef', 'Leah', 'Daniel', 'Miriam',
    'Aaron', 'Esther', 'Jacob', 'Hannah', 'Eli', 'Rachel', 'Benjamin', 'Dina', 'Samuel', 'Naomi', 'Isaac',
    'Tamar', 'Levi', 'Shira', 'Ezra', 'Maya', 'Asher', 'Talia', 'Jonah'];
$lastNames = ['Cohen', 'Levy', 'Rosenberg', 'Friedman', 'Katz', 'Goldberg', 'Weiss', 'Stern', 'Klein', 'Schwartz',
    'Segal', 'Adler', 'Berger', 'Kaplan', 'Roth', 'Simon', 'Fisher', 'Gross', 'Marks', 'Neuman'];
// Roughly how consumer mail divides between providers
$providers = ['gmail' => 46, 'yahoo' => 12, 'outlook' => 11, 'hotmail' => 6, 'icloud' => 13, 'aol' => 3, 'other' => 9];

$pick = static function (array $weights) {
    $roll = mt_rand(1, array_sum($weights));

    foreach ($weights as $key => $weight) {
        if (($roll -= $weight) <= 0) {
            return $key;
        }
    }

    return array_key_first($weights);
};
$ago = static fn (int $seconds) => gmdate('Y-m-d H:i:s', time() - $seconds);

echo "Clearing the previous synthetic set...\n";
$db->query('SET FOREIGN_KEY_CHECKS = 0');
$db->delete($t('ysrtech_emailcampaigns/queue'), "email LIKE '%@example.invalid'");
$db->delete($t('ysrtech_emailcampaigns/suppression'), "email LIKE '%@example.invalid'");
$db->delete($t('newsletter/subscriber'), "subscriber_email LIKE '%@example.invalid'");
$db->delete($t('sales/order'), "customer_email LIKE '%@example.invalid'");
$db->delete($t('sales/order_grid'), "billing_name = 'Synthetic Order'");
$db->delete($t('customer/entity'), "email LIKE '%@example.invalid'");
$db->delete($t('ysrtech_emailcampaigns/campaign'), "name LIKE '[seed]%'");
$db->delete($t('ysrtech_emailcampaigns/automation'), "name LIKE '[seed]%'");
$db->query('SET FOREIGN_KEY_CHECKS = 1');

// ---------------------------------------------------------------- people
echo "Subscribers and customers...\n";
$attr = [];

foreach (['firstname', 'lastname'] as $code) {
    $attr[$code] = (int) Mage::getSingleton('eav/config')->getAttribute('customer', $code)->getId();
}

$customerTarget = (int) round($subscriberTarget * 0.4);
$people         = [];
$rows           = ['customer' => [], 'varchar' => [], 'subscriber' => []];
$nextCustomerId = (int) $db->fetchOne("SELECT COALESCE(MAX(entity_id), 0) + 1 FROM {$t('customer/entity')}");

$flush = static function (bool $force = false) use (&$rows, $db, $t) {
    if (!$force && count($rows['subscriber']) < 1000) {
        return;
    }

    // Together and in this order: the name rows have a foreign key to the
    // customer rows, so those have to be in first.
    foreach ([
        'customer'   => $t('customer/entity'),
        'varchar'    => $t('customer/entity') . '_varchar',
        'subscriber' => $t('newsletter/subscriber'),
    ] as $key => $table) {
        if ($rows[$key]) {
            $db->insertMultiple($table, $rows[$key]);
            $rows[$key] = [];
        }
    }
};

for ($i = 1; $i <= $subscriberTarget; $i++) {
    $first    = $firstNames[array_rand($firstNames)];
    $last     = $lastNames[array_rand($lastNames)];
    $provider = $pick($providers);
    $email    = strtolower("{$first}.{$last}.{$provider}.{$i}@example.invalid");
    $created  = $ago(mt_rand(86400, 86400 * 900));

    $customerId = null;

    if ($i <= $customerTarget) {
        $customerId = $nextCustomerId++;
        $rows['customer'][] = [
            'entity_id' => $customerId, 'entity_type_id' => 1, 'attribute_set_id' => 0, 'website_id' => 1,
            'email' => $email, 'group_id' => 1, 'store_id' => 1, 'created_at' => $created,
            'updated_at' => $created, 'is_active' => 1,
        ];

        foreach (['firstname' => $first, 'lastname' => $last] as $code => $value) {
            $rows['varchar'][] = [
                'entity_type_id' => 1, 'attribute_id' => $attr[$code], 'entity_id' => $customerId, 'value' => $value,
            ];
        }
    }

    // 88% subscribed, 9% unsubscribed, the rest never confirmed
    $roll   = mt_rand(1, 100);
    $status = $roll <= 88 ? 1 : ($roll <= 97 ? 3 : 4);

    $rows['subscriber'][] = [
        'store_id' => 1, 'change_status_at' => $created, 'customer_id' => (int) $customerId,
        'subscriber_email' => $email, 'subscriber_status' => $status,
        'subscriber_confirm_code' => bin2hex(random_bytes(8)),
        // A third of the non-customers left no name, as on a real list
        'subscriber_firstname' => ($customerId || mt_rand(1, 3) > 1) ? $first : null,
        'subscriber_lastname'  => ($customerId || mt_rand(1, 3) > 1) ? $last : null,
    ];

    $people[] = ['email' => $email, 'customer_id' => $customerId, 'status' => $status, 'provider' => $provider];
    $flush();
}

$flush(true);

$subscriberIds = $db->fetchPairs(
    "SELECT subscriber_email, subscriber_id FROM {$t('newsletter/subscriber')} WHERE subscriber_email LIKE '%@example.invalid'"
);

// ---------------------------------------------------------------- orders
echo "Orders...\n";
$orders = [];
$orderN = 0;

foreach ($people as $person) {
    if (!$person['customer_id'] || mt_rand(1, 100) > 55) {
        continue;
    }

    // Most buy once or twice; a few are regulars
    $count = mt_rand(1, 100) <= 80 ? mt_rand(1, 2) : mt_rand(3, 9);

    for ($n = 0; $n < $count; $n++) {
        $total   = round(mt_rand(1800, 26000) / 100, 2);
        $created = $ago(mt_rand(3600, 86400 * 540));
        $orders[] = [
            'state' => 'complete', 'status' => 'complete', 'store_id' => 1,
            'customer_id' => $person['customer_id'], 'customer_email' => $person['email'],
            'customer_is_guest' => 0, 'customer_group_id' => 1,
            'base_grand_total' => $total, 'grand_total' => $total, 'base_subtotal' => $total, 'subtotal' => $total,
            'total_qty_ordered' => mt_rand(1, 6), 'base_currency_code' => 'USD', 'order_currency_code' => 'USD',
            'global_currency_code' => 'USD', 'store_currency_code' => 'USD',
            'increment_id' => 'SEED' . str_pad((string) ++$orderN, 8, '0', STR_PAD_LEFT),
            'created_at' => $created, 'updated_at' => $created,
        ];

        if (count($orders) >= 1000) {
            $db->insertMultiple($t('sales/order'), $orders);
            $orders = [];
        }
    }
}

if ($orders) {
    $db->insertMultiple($t('sales/order'), $orders);
}

// ----------------------------------------------------------- suppressions
echo "Suppressions...\n";
$bounceDetails = [
    '550 5.1.1 The email account that you tried to reach does not exist',
    '550 5.1.1 User unknown',
    '554 5.7.1 Recipient address rejected: Access denied',
    '550 5.4.1 Recipient address rejected: mailbox unavailable',
    '553 5.1.2 Domain of recipient address does not exist',
];
$suppressed = [];
$batch      = [];

foreach ($people as $index => $person) {
    $reason = null;

    if ($person['status'] === 3) {
        // Most unsubscribes came through the email's own link, so they are on
        // the list; the rest unticked the box in their account and are not.
        $reason = mt_rand(1, 100) <= 70 ? 'unsubscribe' : null;
        $roll   = mt_rand(1, 1000);

        if ($roll <= 180) {
            $reason = 'hard_bounce';
        } elseif ($roll <= 196) {
            $reason = 'complaint';
        } elseif ($roll <= 215) {
            $reason = 'manual';
        }
    }

    if ($reason === null) {
        continue;
    }

    $suppressed[$index] = $reason;
    $batch[] = [
        'email'  => $person['email'],
        'reason' => $reason,
        'detail' => $reason === 'hard_bounce' ? $bounceDetails[array_rand($bounceDetails)]
            : ($reason === 'manual' ? 'Asked by phone' : null),
        'source' => $reason === 'manual' ? 'admin' : (mt_rand(1, 4) === 1 ? 'mailgun_sync' : 'webhook'),
        'prev_subscriber_status' => 1,
        'created_at' => $ago(mt_rand(3600, 86400 * 60)),
    ];
}

foreach (array_chunk($batch, 1000) as $chunk) {
    $db->insertMultiple($t('ysrtech_emailcampaigns/suppression'), $chunk);
}

// ------------------------------------------------------- campaigns + mail
echo "Campaigns and sent mail...\n";
$templateId = (int) $db->fetchOne("SELECT MIN(template_id) FROM {$t('ysrtech_emailcampaigns/template')}");

/*
 * How each provider's recipients behave: [open, machine-open share of those
 * opens, click given a real open]. Apple's are nearly all "opens", most of
 * them the proxy; that contrast is what the machine-open split is for.
 */
$behaviour = [
    'gmail'   => [0.34, 0.06, 0.16], 'yahoo'   => [0.24, 0.02, 0.12], 'outlook' => [0.27, 0.02, 0.13],
    'hotmail' => [0.22, 0.02, 0.10], 'icloud'  => [0.71, 0.78, 0.15], 'aol'     => [0.20, 0.02, 0.09],
    'other'   => [0.29, 0.10, 0.14],
];

$campaigns = [
    // name, days ago, share of the list, bounce rate, complaint rate
    ['[seed] Chanukah gift guide',        55, 1.00, 0.011, 0.0006],
    ['[seed] Free shipping weekend',      47, 0.60, 0.007, 0.0004],
    ['[seed] New arrivals: Judaica',      38, 1.00, 0.006, 0.0005],
    ['[seed] Back in stock',              27, 0.35, 0.004, 0.0003],
    ['[seed] Shabbat table sale',         19, 1.00, 0.005, 0.0007],
    ['[seed] Customer favourites',        12, 0.70, 0.004, 0.0004],
    ['[seed] Last days: 20% off sets',     5, 1.00, 0.003, 0.0005],
    ['[seed] Weekend flash sale',          1, 0.80, 0.003, 0.0003],
];

$queueTable = $t('ysrtech_emailcampaigns/queue');
$insertMail = static function (array &$rows, bool $force = false) use ($db, $queueTable) {
    if ($rows && ($force || count($rows) >= 1000)) {
        $db->insertMultiple($queueTable, $rows);
        $rows = [];
    }
};

$mail = static function (array $person, int $sentTs, float $bounceRate, float $complaintRate) use ($behaviour) {
    [$open, $machineShare, $click] = $behaviour[$person['provider']];

    $row = [
        'status' => 'sent', 'attempts' => 1, 'error_message' => null,
        'sent_at' => gmdate('Y-m-d H:i:s', $sentTs),
        'tracking_token' => bin2hex(random_bytes(16)),
        'opened_at' => null, 'open_count' => 0, 'machine_open_count' => 0,
        'clicked_at' => null, 'click_count' => 0,
        'bounced_at' => null, 'complained_at' => null,
    ];
    $roll = mt_rand() / mt_getrandmax();

    if ($roll < $bounceRate) {
        $row['bounced_at'] = gmdate('Y-m-d H:i:s', $sentTs + mt_rand(5, 600));

        return $row;
    }

    if ($roll < $bounceRate + 0.004) {
        // Refused before it left: the provider said no to the request
        $row['status']        = 'failed';
        $row['attempts']      = 3;
        $row['sent_at']       = null;
        $row['error_message'] = 'Mailgun error (HTTP 400): to parameter is not a valid address.';

        return $row;
    }

    if (mt_rand() / mt_getrandmax() < $open) {
        $opens = mt_rand(1, 3);
        $row['open_count'] = $opens;
        $row['opened_at']  = gmdate('Y-m-d H:i:s', $sentTs + mt_rand(60, 86400 * 2));

        if (mt_rand() / mt_getrandmax() < $machineShare) {
            $row['machine_open_count'] = $opens;
        } elseif (mt_rand() / mt_getrandmax() < $click) {
            $row['click_count'] = mt_rand(1, 2);
            $row['clicked_at']  = gmdate('Y-m-d H:i:s', strtotime($row['opened_at']) + mt_rand(5, 900));
        }

        if (!$row['machine_open_count'] && mt_rand() / mt_getrandmax() < $complaintRate * 4) {
            $row['complained_at'] = gmdate('Y-m-d H:i:s', strtotime($row['opened_at']) + mt_rand(5, 300));
        }
    }

    return $row;
};

$rows = [];

foreach ($campaigns as [$name, $daysAgo, $share, $bounceRate, $complaintRate]) {
    $sentTs = time() - $daysAgo * 86400 - mt_rand(0, 3600 * 5);

    $db->insert($t('ysrtech_emailcampaigns/campaign'), [
        'name' => $name, 'subject' => substr($name, 7), 'template_id' => $templateId ?: null,
        'status' => 'sent', 'scheduled_at' => gmdate('Y-m-d H:i:s', $sentTs),
        'sent_at' => gmdate('Y-m-d H:i:s', $sentTs + 1800), 'store_id' => 0,
    ]);
    $campaignId = (int) $db->lastInsertId();

    foreach ($people as $index => $person) {
        if ($person['status'] !== 1 && !isset($suppressed[$index])) {
            continue;
        }

        if (mt_rand() / mt_getrandmax() > $share) {
            continue;
        }

        // Spread over the half hour a send of this size takes
        $rows[] = [
            'campaign_id' => $campaignId, 'automation_id' => null, 'step_id' => null,
            'object_type' => null, 'object_id' => null,
            'email' => $person['email'], 'customer_id' => $person['customer_id'],
            'subscriber_id' => $subscriberIds[$person['email']] ?? null,
        ] + $mail($person, $sentTs + mt_rand(0, 1800), $bounceRate, $complaintRate);

        $insertMail($rows);
    }
}

// An automation too, so the dashboard's type filter has both kinds to show
$db->insert($t('ysrtech_emailcampaigns/automation'), [
    'name' => '[seed] Order follow-up', 'event' => 'new_order', 'respect_subscription' => 0,
    'is_active' => 0, 'store_id' => 0, 'updated_at' => $ago(0),
]);
$automationId = (int) $db->lastInsertId();
$db->insert($t('ysrtech_emailcampaigns/automation_step'), [
    'automation_id' => $automationId, 'sort_order' => 0, 'template_id' => $templateId ?: null,
    'after_days' => 3, 'after_hours' => 0, 'after_minutes' => 0,
]);
$stepId = (int) $db->lastInsertId();

$objectId = 900000;

foreach ($people as $person) {
    if (!$person['customer_id'] || mt_rand(1, 100) > 22) {
        continue;
    }

    $rows[] = [
        'campaign_id' => null, 'automation_id' => $automationId, 'step_id' => $stepId,
        'object_type' => 'order', 'object_id' => ++$objectId,
        'email' => $person['email'], 'customer_id' => $person['customer_id'],
        'subscriber_id' => $subscriberIds[$person['email']] ?? null,
    ] + $mail($person, time() - mt_rand(3600, 86400 * 58), 0.002, 0.0002);

    $insertMail($rows);
}

$insertMail($rows, true);

// ------------------------------------------------------------ safety, UI
$config = Mage::getModel('core/config');
// No path out: the store's own mail is off, and it is the only transport set
$config->saveConfig('system/smtp/disable', 1);
$config->saveConfig('ysrtech_emailcampaigns/sending/transport', 'smtp');
// So an admin URL can be opened directly, by a person or a browser script
$config->saveConfig('admin/security/use_form_key', 0);
Mage::app()->getCacheInstance()->flush();

foreach ([
    'subscribers'  => "SELECT COUNT(*) FROM {$t('newsletter/subscriber')}",
    'customers'    => "SELECT COUNT(*) FROM {$t('customer/entity')}",
    'orders'       => "SELECT COUNT(*) FROM {$t('sales/order')}",
    'suppressions' => "SELECT COUNT(*) FROM {$t('ysrtech_emailcampaigns/suppression')}",
    'queue rows'   => "SELECT COUNT(*) FROM {$queueTable}",
] as $label => $sql) {
    printf("%-13s %s\n", $label, number_format((int) $db->fetchOne($sql)));
}
