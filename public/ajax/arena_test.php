<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireAdmin();
requireCsrfOrFail();

$key = trim((string)($_POST['arena_api_key'] ?? ''));
if ($key === '') {
    $key = trim((string)getSetting('arena_api_key', ''));
}
if ($key === '') {
    jsonResponse(['success' => false, 'message' => 'ابتدا Arena API Key را وارد کنید.'], 400);
}

$ch = curl_init('https://api.preview.arena.ai/v1/models');
if ($ch === false) {
    jsonResponse(['success' => false, 'message' => 'امکان شروع تست Arena وجود ندارد.'], 500);
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => 'BAJI-WC-Manager/ArenaConnectionTest',
]);
$raw = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    jsonResponse(['success' => false, 'message' => 'ارتباط شبکه با Arena برقرار نشد.'], 502);
}
if ($status === 401 || $status === 403) {
    jsonResponse(['success' => false, 'message' => 'Arena API Key معتبر نیست یا دسترسی ندارد.'], 401);
}
if ($status < 200 || $status >= 300) {
    jsonResponse(['success' => false, 'message' => 'Arena پاسخ معتبر نداد (HTTP ' . $status . ').'], 502);
}

$decoded = json_decode((string)$raw, true);
if (!is_array($decoded)) {
    jsonResponse(['success' => false, 'message' => 'پاسخ Arena قابل پردازش نبود.'], 502);
}

logActivity('test_arena_connection', 'arena', 'Arena API authentication succeeded');
jsonResponse(['success' => true, 'message' => 'اتصال Arena با موفقیت برقرار است.']);
