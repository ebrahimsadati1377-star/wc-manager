<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$productId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($productId <= 0) {
    fwrite(STDERR, "Usage: php tools/basalam-find-woo-product.php <woo_product_id>\n");
    exit(2);
}

$wc = new WooCommerceClient();
$basalam = new BasalamClient();
$productRes = $wc->getProduct($productId);
if ($productRes['error']) {
    echo json_encode(['woo_product_id' => $productId, 'error' => $productRes['error']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(3);
}

$product = is_array($productRes['body']) ? $productRes['body'] : [];
$title = trim((string)($product['name'] ?? ''));
$sku = trim((string)($product['sku'] ?? ''));
$params = ['page' => 1, 'per_page' => 100, 'title' => $title];
if ($sku !== '') {
    $params['skus'] = [$sku];
}

$res = $basalam->getVendorProducts($params);
$body = is_array($res['body'] ?? null) ? $res['body'] : [];
$items = $body['data'] ?? $body['products'] ?? [];
if (!is_array($items)) $items = [];

$out = [
    'woo_product_id' => $productId,
    'woo_name' => $title,
    'woo_sku' => $sku,
    'status' => (int)($res['status'] ?? 0),
    'error' => $res['error'] ?? null,
    'match_count' => count($items),
    'matches' => array_map(static function ($item) {
        return [
            'id' => (int)($item['id'] ?? 0),
            'name' => (string)($item['name'] ?? $item['title'] ?? ''),
            'sku' => (string)($item['sku'] ?? ''),
            'status' => $item['status'] ?? null,
            'primary_price' => $item['primary_price'] ?? null,
            'stock' => $item['stock'] ?? null,
        ];
    }, $items),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
