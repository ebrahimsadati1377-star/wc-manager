<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/BasalamChatSmsNotifier.php';

$spoolDir = realpath(__DIR__ . '/../storage/basalam-chat-spool');
$file = $argv[1] ?? '';
$real = is_string($file) && $file !== '' ? realpath($file) : false;

if (!$spoolDir || !$real || !str_starts_with($real, $spoolDir . DIRECTORY_SEPARATOR)) {
    exit(2);
}

$raw = @file_get_contents($real);
if (!is_string($raw) || $raw === '') {
    @unlink($real);
    exit(3);
}

$payload = json_decode($raw, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    @unlink($real);
    exit(4);
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

    @unlink($real);
    exit(0);
} catch (Throwable $e) {
    error_log('[wc-manager] Basalam chat SMS worker failed: ' . $e->getMessage());
    exit(5);
}
