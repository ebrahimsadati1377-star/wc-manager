<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/BasalamAutoMatcher.php';
Auth::requireLogin();

if (!Auth::isAdmin()) {
    jsonResponse(['success' => false, 'message' => 'فقط مدیر می‌تواند تطبیق خودکار محصولات را اجرا کند.'], 403);
}

try {
    $matcher = new BasalamAutoMatcher();
    $result = $matcher->run();
    jsonResponse($result, !empty($result['success']) ? 200 : 422);
} catch (Throwable $e) {
    error_log('[wc-manager] Basalam auto-match failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'اجرای تطبیق خودکار ناموفق بود.'], 500);
}
