<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

$productId = (int)($_POST['product_id'] ?? 0);
$variationId = (int)($_POST['variation_id'] ?? 0);

if ($productId <= 0 || $variationId <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.']);
}

$payload = [
    'sku'           => trim($_POST['sku'] ?? ''),
    'regular_price' => (string)($_POST['regular_price'] ?? ''),
    'sale_price'    => (string)($_POST['sale_price'] ?? ''),
    'status'        => ($_POST['enabled'] ?? '1') === '1' ? 'publish' : 'private',
];

$stockQty = trim($_POST['stock_quantity'] ?? '');
if ($stockQty !== '') {
    $payload['manage_stock'] = true;
    $payload['stock_quantity'] = (int)$stockQty;
} else {
    $payload['manage_stock'] = false;
}

$imageUrl = trim($_POST['image_url'] ?? '');
if ($imageUrl !== '') {
    $payload['image'] = ['src' => $imageUrl];
}

$wc = new WooCommerceClient();
$res = $wc->updateVariation($productId, $variationId, $payload);

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

logActivity('update_variation', 'variation', (string)$variationId);
jsonResponse(['success' => true, 'variation' => $res['body']]);
