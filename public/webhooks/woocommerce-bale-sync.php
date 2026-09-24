<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if ((string)getSetting('bale_auto_publish_enabled', '0') !== '1') {
    echo json_encode(['ok' => true, 'status' => 'disabled']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$secret = (string)getSetting('woocommerce_bale_webhook_secret', '');
$signature = (string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '');
$expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));

if ($secret === '' || $signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$topic = (string)($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? '');
if (!in_array($topic, ['product.created', 'product.updated'], true)) {
    echo json_encode(['ok' => true, 'status' => 'ignored_topic']);
    exit;
}

$payload = json_decode($raw, true);
$productId = (int)($payload['id'] ?? 0);
if ($productId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_product_id']);
    exit;
}

$db = Database::get();
$lockName = 'bale_auto_publish_' . $productId;
$lock = $db->prepare('SELECT GET_LOCK(:name, 5)');
$lock->execute(['name' => $lockName]);

try {
    $seen = $db->prepare(
        "SELECT 1 FROM activity_log
         WHERE target = :target
           AND action IN ('bale_auto_publish_success','bale_bulk_publish_success','bale_manual_publish_success')
         LIMIT 1"
    );
    $seen->execute(['target' => 'product:' . $productId]);

    if ($seen->fetchColumn()) {
        echo json_encode(['ok' => true, 'status' => 'already_posted']);
        exit;
    }

    $wc = new WooCommerceClient();
    $res = $wc->get('products/' . $productId);
    $product = $res['body'] ?? null;

    if (!is_array($product) || ($product['status'] ?? '') !== 'publish') {
        echo json_encode(['ok' => true, 'status' => 'not_published']);
        exit;
    }

    $bale = new BaleClient();
    $sent = $bale->sendProduct($product);

    if (!empty($sent['ok'])) {
        logActivity('bale_auto_publish_success', 'product:' . $productId, 'woocommerce webhook');
        echo json_encode(['ok' => true, 'status' => 'sent']);
        exit;
    }

    $error = (string)($sent['error'] ?? 'unknown');
    logActivity('bale_auto_publish_failed', 'product:' . $productId, mb_substr($error, 0, 350));
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => $error]);
} finally {
    $unlock = $db->prepare('SELECT RELEASE_LOCK(:name)');
    $unlock->execute(['name' => $lockName]);
}
