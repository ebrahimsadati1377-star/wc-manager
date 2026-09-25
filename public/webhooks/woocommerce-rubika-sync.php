<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/RubikaClient.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 2097152) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_body']);
    exit;
}

$secret = trim((string)getSetting('woocommerce_rubika_webhook_secret', ''));
$signature = trim((string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? ''));
$expected = $secret !== ''
    ? base64_encode(hash_hmac('sha256', $raw, $secret, true))
    : '';

if ($secret === '' || $signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'invalid_signature']);
    exit;
}

if ((string)getSetting('rubika_auto_publish_enabled', '0') !== '1') {
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
$lockName = 'rubika_auto_publish_' . $productId;
$lockStmt = $db->prepare('SELECT GET_LOCK(:name, 15)');
$lockStmt->execute(['name' => $lockName]);
$locked = (int)$lockStmt->fetchColumn() === 1;

if (!$locked) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'publish_lock_timeout']);
    exit;
}

$target = 'product:' . $productId;

try {
    $seen = $db->prepare(
        "SELECT id FROM activity_log
         WHERE action = 'rubika_auto_publish_success' AND target = :target
         LIMIT 1"
    );
    $seen->execute(['target' => $target]);
    if ($seen->fetchColumn()) {
        http_response_code(200);
        echo json_encode(['success' => true, 'status' => 'already_posted']);
        exit;
    }

    $wc = new WooCommerceClient();
    $productRes = $wc->getProduct($productId);
    if (!empty($productRes['error'])) {
        throw new RuntimeException('Woo product read failed: ' . $productRes['error']);
    }

    $product = is_array($productRes['body'] ?? null) ? $productRes['body'] : [];
    $status = strtolower((string)($product['status'] ?? ''));
    if ($status !== 'publish') {
        logActivity('rubika_auto_publish_ignored', $target, 'Woo status=' . $status);
        http_response_code(200);
        echo json_encode(['success' => true, 'status' => 'ignored_not_published']);
        exit;
    }

    $rubika = new RubikaClient();
    if (!$rubika->isConfigured()) {
        throw new RuntimeException('Rubika connection is not configured.');
    }

    $result = $rubika->sendProduct($product);
    if (empty($result['ok'])) {
        throw new RuntimeException('Rubika publish failed: ' . (string)($result['error'] ?? 'unknown'));
    }

    $messageId = '';
    if (is_array($result['result'] ?? null)) {
        $messageId = (string)($result['result']['message_id'] ?? '');
    }

    logActivity(
        'rubika_auto_publish_success',
        $target,
        'message_id=' . $messageId
    );

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'status' => 'posted',
        'product_id' => $productId,
        'message_id' => $messageId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[wc-manager] Woo->Rubika auto publish failed product=' . $productId . ': ' . $e->getMessage());
    logActivity('rubika_auto_publish_failed', $target, mb_substr($e->getMessage(), 0, 400));
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'temporary_failure']);
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute(['name' => $lockName]);
}