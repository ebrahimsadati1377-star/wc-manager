<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireAdmin();

$basalam = new BasalamClient();
if (!$basalam->isConfigured()) {
    jsonResponse([
        'success' => false,
        'message' => 'ابتدا Vendor ID و اطلاعات احراز هویت باسلام را ذخیره کنید.',
    ], 422);
}

$result = $basalam->ping();
if ($result['error']) {
    jsonResponse([
        'success' => false,
        'message' => 'اتصال باسلام ناموفق بود: ' . $result['error'],
        'http_status' => $result['status'],
    ], 502);
}

$count = isset($result['body']['total_count']) ? (int)$result['body']['total_count'] : null;
logActivity('test_basalam_connection', 'basalam', 'اتصال باسلام با موفقیت تست شد.');

jsonResponse([
    'success' => true,
    'message' => $count !== null
        ? 'اتصال برقرار است. تعداد محصولات غرفه: ' . $count
        : 'اتصال باسلام برقرار است.',
]);
