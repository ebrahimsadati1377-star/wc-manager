<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/TelegramClient.php';

if ((string)getSetting('telegram_auto_publish_enabled', '0') !== '1') {
    exit(0);
}

$db = Database::get();
$lock = $db->query("SELECT GET_LOCK('telegram_auto_publish_cron', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    exit(0);
}

try {
    $wc = new WooCommerceClient();
    $res = $wc->get('products', [
        'status' => 'publish',
        'per_page' => 100,
        'orderby' => 'date',
        'order' => 'desc',
    ]);

    $products = $res['body'] ?? [];
    if (!is_array($products)) {
        throw new RuntimeException('Invalid WooCommerce products response');
    }

    $seen = $db->prepare(
        "SELECT 1 FROM activity_log
         WHERE target = :target
           AND action IN (
             'telegram_auto_publish_success',
             'telegram_bulk_publish_success',
             'telegram_manual_publish_success'
           )
         LIMIT 1"
    );

    $telegram = new TelegramClient();
    $sentCount = 0;

    foreach (array_reverse($products) as $product) {
        $productId = (int)($product['id'] ?? 0);
        if ($productId < 1) {
            continue;
        }

        $seen->execute(['target' => 'product:' . $productId]);
        if ($seen->fetchColumn()) {
            continue;
        }

        $result = $telegram->sendProduct($product);

        if (!empty($result['ok'])) {
            logActivity(
                'telegram_auto_publish_success',
                'product:' . $productId,
                'cron fallback'
            );
            $sentCount++;
            echo date('c') . " sent product {$productId}" . PHP_EOL;
            usleep(1200000);
            continue;
        }

        $error = (string)($result['error'] ?? 'unknown');
        logActivity(
            'telegram_auto_publish_failed',
            'product:' . $productId,
            mb_substr($error, 0, 350)
        );
        echo date('c') . " failed product {$productId}: {$error}" . PHP_EOL;
    }

    echo date('c') . " done sent={$sentCount}" . PHP_EOL;
} catch (Throwable $e) {
    echo date('c') . ' error: ' . $e->getMessage() . PHP_EOL;
} finally {
    $db->query("SELECT RELEASE_LOCK('telegram_auto_publish_cron')");
}
