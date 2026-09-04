<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$woo = new WooCommerceClient();
$basalam = new BasalamClient();
$sync = new BasalamSync($woo, $basalam);

$wooId = 2040;
$basalamId = 57650781;
$categoryId = 231;

$wooRes = $woo->getProduct($wooId);
$attrRes = $basalam->getCategoryAttributes($categoryId, $basalamId, false);
$productRes = $basalam->getProduct($basalamId, true);

$wooProduct = is_array($wooRes['body'] ?? null) ? $wooRes['body'] : [];
$basalamProduct = is_array($productRes['body'] ?? null) ? $productRes['body'] : [];

$out = [
    'woo' => [
        'status' => $wooRes['status'] ?? 0,
        'error' => $wooRes['error'] ?? null,
        'id' => $wooProduct['id'] ?? null,
        'name' => $wooProduct['name'] ?? null,
        'attributes' => $wooProduct['attributes'] ?? [],
        'dimensions' => $wooProduct['dimensions'] ?? [],
        'categories' => $wooProduct['categories'] ?? [],
        'tags' => $wooProduct['tags'] ?? [],
        'short_description' => strip_tags((string)($wooProduct['short_description'] ?? '')),
        'description' => strip_tags((string)($wooProduct['description'] ?? '')),
    ],
    'category_attributes' => [
        'status' => $attrRes['status'] ?? 0,
        'error' => $attrRes['error'] ?? null,
        'body' => $attrRes['body'] ?? [],
    ],
    'basalam_product' => [
        'status' => $productRes['status'] ?? 0,
        'error' => $productRes['error'] ?? null,
        'id' => $basalamProduct['id'] ?? null,
        'title' => $basalamProduct['title'] ?? ($basalamProduct['name'] ?? null),
        'attributes' => $basalamProduct['attributes'] ?? null,
        'product_attribute' => $basalamProduct['product_attribute'] ?? null,
        'revision_attributes' => $basalamProduct['revision']['data']['attributes'] ?? null,
    ],
];

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    $json = '{}';
}
echo 'ATTR_INSPECT_B64=' . base64_encode($json) . PHP_EOL;
