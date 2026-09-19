<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/BasalamChatSmsNotifier.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

$expected = trim((string)getSetting('basalam_chat_webhook_secret', ''));
$provided = trim((string)($_SERVER['HTTP_X_BAJI_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_TOKEN'] ?? ''));

if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 262144) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_body']);
    exit;
}
$payload = json_decode($raw, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_json']);
    exit;
}

try {
    $notifier = new BasalamChatSmsNotifier();
    $result = $notifier->handle($payload);

    if (isset($result['message_id'])) {
        logActivity(
            'basalam_chat_sms',
            'message:' . (int)$result['message_id'],
            'status=' . (string)($result['status'] ?? 'unknown')
        );
    }

    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_payload']);
} catch (Throwable $e) {
    error_log('[wc-manager] Basalam chat SMS webhook failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'temporary_failure']);
}
