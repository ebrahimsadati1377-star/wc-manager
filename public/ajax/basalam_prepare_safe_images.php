<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($data)) {
    $data = [];
}

$wcProductId = (int)($data['wc_product_id'] ?? $_POST['wc_product_id'] ?? 0);
$cropTopPercent = (int)($data['crop_top_percent'] ?? $_POST['crop_top_percent'] ?? 28);

if ($wcProductId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه محصول ووکامرس معتبر نیست.'], 422);
}

try {
    $service = new BasalamSafeImageService();
    $result = $service->prepareAndSubmit($wcProductId, $cropTopPercent);
    jsonResponse($result, !empty($result['success']) ? 200 : 422);
} catch (Throwable $e) {
    error_log('[wc-manager] basalam safe image remediation failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'اصلاح تصاویر باسلام با خطای داخلی متوقف شد.'], 500);
}
