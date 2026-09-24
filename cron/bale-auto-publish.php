<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ((string)getSetting('bale_auto_publish_enabled', '0') !== '1') {
    exit(0);
}

$bale = new BaleClient();
if (!$bale->isConfigured()) {
    fwrite(STDERR, "Bale not configured\n");
    exit(1);
}

$db = Database::get();
$sentRows = $db->query(
    "SELECT target FROM activity_log
     WHERE action IN ('bale_auto_publish_success','bale_bulk_publish_success','bale_manual_publish_success')"
);

$sent = [];
foreach ($sentRows as $row) {
    if (preg_match('/^product:(\d+)$/', (string)$row['target'], $m)) {
        $sent[(int)$m[1]] = true;
    }
}

$wc = new WooCommerceClient();
$products = [];
$page = 1;

do {
    $res = $wc->getProducts([
        'status' => 'publish',
        'per_page' => 100,
        'page' => $page,
        'orderby' => 'date',
        'order' => 'asc',
    ]);

    if (!empty($res['error'])) {
        fwrite(STDERR, "Woo read failed: " . $res['error'] . "\n");
        exit(1);
    }

    $items = is_array($res['body'] ?? null) ? $res['body'] : [];
    foreach ($items as $product) {
        $products[] = $product;
    }

    $page++;
} while (count($items) === 100);

$attempts = 0;
$sentNow = 0;

foreach ($products as $product) {
    $id = (int)($product['id'] ?? 0);
    if ($id < 1 || isset($sent[$id])) {
        continue;
    }

    $attempts++;
    $result = $bale->sendProduct($product);

    if (!empty($result['ok'])) {
        logActivity('bale_auto_publish_success', 'product:' . $id, 'cron fallback');
        $sent[$id] = true;
        $sentNow++;
        echo "SENT #{$id} " . ($product['name'] ?? '') . "\n";
    } else {
        $error = (string)($result['error'] ?? 'unknown');
        logActivity('bale_auto_publish_failed', 'product:' . $id, mb_substr($error, 0, 350));
        fwrite(STDERR, "FAILED #{$id}: {$error}\n");
    }

    if ($attempts >= 20) {
        break;
    }

    usleep(500000);
}

echo "attempts={$attempts} sent={$sentNow}\n";
