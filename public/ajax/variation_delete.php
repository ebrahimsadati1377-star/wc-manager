<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

$productId = (int)($_POST['product_id'] ?? 0);
$variationId = (int)($_POST['variation_id'] ?? 0);

if ($productId <= 0 || $variationId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.']);
}

$wc = new WooCommerceClient();
$res = $wc->deleteVariation($productId, $variationId);

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

logActivity('delete_variation', 'variation', (string)$variationId);
jsonResponse(['success' => true]);
