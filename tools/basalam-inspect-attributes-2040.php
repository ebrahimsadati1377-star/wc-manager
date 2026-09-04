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

$syncResult = $sync->syncProduct($wooId, true);
$attrRes = $basalam->getCategoryAttributes($categoryId, $basalamId, false);
$productRes = $basalam->getProduct($basalamId, true);

$definitions = [];
$groups = $attrRes['body']['data'] ?? [];
if (is_array($groups)) {
    foreach ($groups as $group) {
        foreach (($group['attributes'] ?? []) as $attribute) {
            if (!is_array($attribute)) continue;
            $definitions[] = [
                'id' => $attribute['id'] ?? null,
                'title' => $attribute['title'] ?? null,
                'value' => $attribute['value'] ?? null,
                'required' => $attribute['required'] ?? null,
            ];
        }
    }
}

$body = is_array($productRes['body'] ?? null) ? $productRes['body'] : [];
$out = [
    'sync' => [
        'success' => $syncResult['success'] ?? false,
        'message' => $syncResult['message'] ?? '',
        'warnings' => $syncResult['warnings'] ?? [],
        'basalam_product_id' => $syncResult['basalam_product_id'] ?? null,
    ],
    'category_attribute_status' => $attrRes['status'] ?? 0,
    'category_attribute_error' => $attrRes['error'] ?? null,
    'attributes' => $definitions,
    'product' => [
        'status' => $productRes['status'] ?? 0,
        'error' => $productRes['error'] ?? null,
        'attributes' => $body['attributes'] ?? null,
    ],
];

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) $json = '{}';
echo 'ATTR_VERIFY_JSON=' . $json . PHP_EOL;

if (empty($syncResult['success'])) exit(2);
