<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();

$categoryName = trim((string)($_POST['category_name'] ?? ''));
if ($categoryName === '') {
    jsonResponse(['success' => false, 'message' => 'نام دسته‌بندی نمی‌تواند خالی باشد.'], 422);
}

if (mb_strlen($categoryName, 'UTF-8') > 200) {
    jsonResponse(['success' => false, 'message' => 'نام دسته‌بندی بیش از حد طولانی است.'], 422);
}

try {
    $wc = new WooCommerceClient();
    if (!$wc->isWpConfigured()) {
        jsonResponse(['success' => false, 'message' => 'تنظیمات اتصال به وردپرس کامل نیست.'], 422);
    }

    $result = $wc->createPostCategory($categoryName);
    if (!empty($result['error'])) {
        error_log('[wc-manager] create post category failed: ' . $result['error']);
        jsonResponse(['success' => false, 'message' => 'ساخت دسته‌بندی در وردپرس ناموفق بود.'], 502);
    }

    $newCategory = $result['body'] ?? [];
    logActivity('create_post_category', 'post_category', (string)($newCategory['id'] ?? ''));

    jsonResponse([
        'success' => true,
        'message' => 'دسته‌بندی با موفقیت ایجاد شد.',
        'category' => [
            'id' => (int)($newCategory['id'] ?? 0),
            'name' => (string)($newCategory['name'] ?? $categoryName),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[wc-manager] create post category exception: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'خطای داخلی هنگام ساخت دسته‌بندی رخ داد.'], 500);
}
