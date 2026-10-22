<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

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

$spoolDir = __DIR__ . '/../../storage/basalam-chat-spool';
if (!is_dir($spoolDir) && !@mkdir($spoolDir, 0700, true) && !is_dir($spoolDir)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'queue_unavailable']);
    exit;
}

try {
    $name = 'chat-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
} catch (Throwable $e) {
    $name = 'chat-' . date('Ymd-His') . '-' . uniqid('', true) . '.json';
}

$file = $spoolDir . '/' . $name;
if (@file_put_contents($file, $raw, LOCK_EX) === false) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'queue_write_failed']);
    exit;
}
@chmod($file, 0600);

$worker = realpath(__DIR__ . '/../../bin/basalam-chat-worker.php');
if (!$worker || !function_exists('exec')) {
    @unlink($file);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'worker_unavailable']);
    exit;
}

$cmd = '/usr/bin/php ' . escapeshellarg($worker) . ' ' . escapeshellarg($file)
    . ' > /dev/null 2>&1 & echo $!';
$output = [];
$code = 0;
@exec($cmd, $output, $code);
$pid = isset($output[0]) ? (int)$output[0] : 0;

if ($code !== 0 || $pid <= 0) {
    @unlink($file);
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'worker_start_failed']);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'status' => 'queued'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
