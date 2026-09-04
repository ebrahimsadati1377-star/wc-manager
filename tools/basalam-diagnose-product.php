<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$productId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($productId <= 0) {
    fwrite(STDERR, "Usage: php tools/basalam-diagnose-product.php <woo_product_id>\n");
    exit(2);
}

$wc = new WooCommerceClient();
$basalam = new BasalamClient();
$sync = new BasalamSync($wc, $basalam);

$out = [
    'woo_product_id' => $productId,
    'woo_configured' => $wc->isConfigured(),
    'basalam_configured' => $basalam->isConfigured(),
    'basalam_vendor_id' => $basalam->getVendorId(),
];

$productMap = $sync->getProductMap($productId);
$out['sync_map'] = $productMap ? [
    'basalam_product_id' => isset($productMap['basalam_product_id']) ? (int)$productMap['basalam_product_id'] : null,
    'sync_status' => (string)($productMap['sync_status'] ?? ''),
    'sync_error' => (string)($productMap['sync_error'] ?? ''),
    'last_synced_at' => (string)($productMap['last_synced_at'] ?? ''),
] : null;

$productRes = $wc->getProduct($productId);
if ($productRes['error']) {
    $out['woo_error'] = $productRes['error'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(3);
}

$product = is_array($productRes['body']) ? $productRes['body'] : [];
$out['product'] = [
    'id' => (int)($product['id'] ?? 0),
    'name' => (string)($product['name'] ?? ''),
    'type' => (string)($product['type'] ?? ''),
    'status' => (string)($product['status'] ?? ''),
    'sku' => (string)($product['sku'] ?? ''),
    'price' => (string)($product['price'] ?? ''),
    'regular_price' => (string)($product['regular_price'] ?? ''),
    'sale_price' => (string)($product['sale_price'] ?? ''),
    'manage_stock' => (bool)($product['manage_stock'] ?? false),
    'stock_quantity' => $product['stock_quantity'] ?? null,
    'stock_status' => (string)($product['stock_status'] ?? ''),
    'weight' => (string)($product['weight'] ?? ''),
    'virtual' => (bool)($product['virtual'] ?? false),
    'categories' => array_map(static fn($c) => [
        'id' => (int)($c['id'] ?? 0),
        'name' => (string)($c['name'] ?? ''),
    ], is_array($product['categories'] ?? null) ? $product['categories'] : []),
    'attributes' => array_map(static fn($a) => [
        'id' => (int)($a['id'] ?? 0),
        'name' => (string)($a['name'] ?? ''),
        'variation' => (bool)($a['variation'] ?? false),
        'options' => array_values(is_array($a['options'] ?? null) ? $a['options'] : []),
    ], is_array($product['attributes'] ?? null) ? $product['attributes'] : []),
    'image_count' => count(is_array($product['images'] ?? null) ? $product['images'] : []),
];

$maps = $sync->getCategoryMaps();
$matchedMap = null;
foreach ($out['product']['categories'] as $category) {
    $wcCategoryId = (int)$category['id'];
    if ($wcCategoryId > 0 && isset($maps[$wcCategoryId])) {
        $matchedMap = $maps[$wcCategoryId];
        break;
    }
}

$out['category_map'] = $matchedMap ? [
    'wc_category_id' => (int)$matchedMap['wc_category_id'],
    'basalam_category_id' => (int)$matchedMap['basalam_category_id'],
    'basalam_category_name' => (string)($matchedMap['basalam_category_name'] ?? ''),
] : null;

if ($matchedMap) {
    $basalamCategoryId = (int)$matchedMap['basalam_category_id'];

    $catRes = $basalam->getCategory($basalamCategoryId);
    $out['basalam_category'] = [
        'status' => $catRes['status'] ?? 0,
        'error' => $catRes['error'] ?? null,
        'body' => $catRes['body'] ?? [],
    ];

    $attrsRes = $basalam->getCategoryAttributes($basalamCategoryId);
    $out['basalam_category_attributes'] = [
        'status' => $attrsRes['status'] ?? 0,
        'error' => $attrsRes['error'] ?? null,
        'body' => $attrsRes['body'] ?? [],
    ];
}

if (($product['type'] ?? '') === 'variable') {
    $varRes = $wc->getVariations($productId, ['per_page' => 100]);
    $out['variations'] = [
        'status' => $varRes['status'] ?? 0,
        'error' => $varRes['error'] ?? null,
        'count' => is_array($varRes['body'] ?? null) ? count($varRes['body']) : 0,
        'items' => array_map(static fn($v) => [
            'id' => (int)($v['id'] ?? 0),
            'sku' => (string)($v['sku'] ?? ''),
            'price' => (string)($v['price'] ?? ''),
            'regular_price' => (string)($v['regular_price'] ?? ''),
            'sale_price' => (string)($v['sale_price'] ?? ''),
            'manage_stock' => (bool)($v['manage_stock'] ?? false),
            'stock_quantity' => $v['stock_quantity'] ?? null,
            'stock_status' => (string)($v['stock_status'] ?? ''),
            'attributes' => $v['attributes'] ?? [],
        ], is_array($varRes['body'] ?? null) ? $varRes['body'] : []),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
