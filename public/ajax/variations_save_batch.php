<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

// دادن زمان کافی به سرور برای آپدیت دیتابیس ووکامرس
set_time_limit(120);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    jsonResponse(['success' => false, 'message' => 'داده ارسالی نامعتبر است.']);
}

// چک کردن توکن امنیت CSRF
$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sentToken)) {
    jsonResponse(['success' => false, 'message' => 'نشست شما نامعتبر است.'], 419);
}

$productId = (int)($data['product_id'] ?? 0);
$updateData = (array)($data['update'] ?? []);

if ($productId <= 0 || empty($updateData)) {
    jsonResponse(['success' => false, 'message' => 'اطلاعات ارسالی برای به‌روزرسانی ناقص است.']);
}

$wc = new WooCommerceClient();

// 🚀 استفاده از قدرت متد بچ کلاینت برای آپدیت همزمان هر تعداد متغیر فقط با ۱ ریکوئست
$res = $wc->batchVariations($productId, [], $updateData, []);

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

logActivity('batch_update_variations', 'product', 'ویرایش گروهی تنوع‌های محصول ' . $productId);

jsonResponse(['success' => true, 'message' => 'تنوع‌ها با موفقیت به‌روزرسانی شدند.']);