<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_body']);
    exit;
}

$secret = trim((string)getSetting('woocommerce_basalam_webhook_secret', ''));
$signature = trim((string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? ''));
$expected = $secret !== ''
    ? base64_encode(hash_hmac('sha256', $raw, $secret, true))
    : '';
if ($secret === '' || $signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'invalid_signature']);
    exit;
}

if ((string)getSetting('woocommerce_basalam_auto_sync_enabled', '1') !== '1') {
    http_response_code(200);
    echo json_encode(['success' => true, 'status' => 'disabled']);
    exit;
}

$topic = strtolower(trim((string)($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? '')));
if ($topic !== '' && !in_array($topic, ['product.created', 'product.updated'], true)) {
    http_response_code(200);
    echo json_encode(['success' => true, 'status' => 'ignored_topic']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_json']);
    exit;
}

$productId = (int)($payload['id'] ?? 0);
if ($productId <= 0) {
    http_response_code(200);
    echo json_encode(['success' => true, 'status' => 'ignored_missing_product_id']);
    exit;
}
$db = Database::get();
$lockName = 'basalam_auto_sync_' . $productId;
$lockStmt = $db->prepare('SELECT GET_LOCK(:name, 15)');
$lockStmt->execute(['name' => $lockName]);
$locked = (int)$lockStmt->fetchColumn() === 1;

if (!$locked) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'sync_lock_timeout']);
    exit;
}

try {
    $wc = new WooCommerceClient();
    $productRes = $wc->getProduct($productId);

    if ($productRes['error']) {
        throw new RuntimeException('Woo product read failed: ' . $productRes['error']);
    }

    $status = strtolower((string)($productRes['body']['status'] ?? ''));
    if ($status !== 'publish') {
        logActivity(
            'basalam_auto_sync_ignored',
            'product:' . $productId,
            'Woo status=' . $status
        );
        http_response_code(200);
        echo json_encode(['success' => true, 'status' => 'ignored_not_published']);
        exit;
    }
    $sync = new BasalamSync($wc);
    $result = $sync->syncProduct($productId, false);

    $success = (bool)($result['success'] ?? false);
    $details = $success
        ? ('basalam_product_id=' . (int)($result['basalam_product_id'] ?? 0))
        : ('error=' . mb_substr((string)($result['error'] ?? $result['message'] ?? 'unknown'), 0, 300));

    logActivity(
        $success ? 'basalam_auto_sync_success' : 'basalam_auto_sync_failed',
        'product:' . $productId,
        $details
    );

    http_response_code(200);
    echo json_encode([
        'success' => $success,
        'status' => $success ? 'synced' : 'sync_failed',
        'product_id' => $productId,
        'basalam_product_id' => (int)($result['basalam_product_id'] ?? 0),
        'skipped' => (bool)($result['skipped'] ?? false),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[wc-manager] Woo->Basalam auto sync failed product=' . $productId . ': ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'temporary_failure']);
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute(['name' => $lockName]);
}
