<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

// دریافت نام دسته‌بندی
$categoryName = isset($_POST['category_name']) ? trim($_POST['category_name']) : '';

if (empty($categoryName)) {
    echo json_encode([
        'success' => false,
        'message' => 'نام دسته‌بندی نمی‌تواند خالی باشد.'
    ]);
    exit;
}

try {
    $wc = new WooCommerceClient();

    // بررسی اتصال به وردپرس
    if (!$wc->isWpConfigured()) {
        echo json_encode([
            'success' => false,
            'message' => 'تنظیمات اتصال به وردپرس تنظیم نشده است.'
        ]);
        exit;
    }

    // ساخت دسته‌بندی جدید
    $result = $wc->createPostCategory($categoryName);

    if (!empty($result['error'])) {
        echo json_encode([
            'success' => false,
            'message' => 'خطا در ساخت دسته‌بندی: ' . $result['error']
        ]);
        exit;
    }

    $newCategory = $result['body'] ?? [];

    echo json_encode([
        'success' => true,
        'message' => 'دسته‌بندی با موفقیت ایجاد شد.',
        'category' => [
            'id' => $newCategory['id'] ?? 0,
            'name' => $newCategory['name'] ?? $categoryName
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'خطای سیستمی: ' . $e->getMessage()
    ]);
}
exit;
