<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);

$rawQuery = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
$productId = 0;
if (isset($_GET['p']) && ctype_digit((string)$_GET['p'])) {
    $productId = (int)$_GET['p'];
} elseif ($rawQuery !== '' && ctype_digit($rawQuery)) {
    $productId = (int)$rawQuery;
}

if ($productId < 1) {
    http_response_code(404);
    exit('Not found');
}

$wc = new WooCommerceClient();
$res = $wc->getProduct($productId);
if (!empty($res['error']) || !is_array($res['body'] ?? null)) {
    http_response_code(404);
    exit('Not found');
}

$product = $res['body'];
if (strtolower((string)($product['status'] ?? '')) !== 'publish') {
    http_response_code(404);
    exit('Not found');
}
$target = trim((string)($product['permalink'] ?? ''));
if ($target === '') {
    http_response_code(404);
    exit('Not found');
}

$params = [
    'utm_source' => 'rubika',
    'utm_medium' => 'social',
    'utm_campaign' => 'product_channel',
    'utm_content' => 'product_' . $productId,
];
$target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($params);

logActivity('rubika_shortlink_click', 'product:' . $productId, 'utm_source=rubika');
header('Location: ' . $target, true, 302);
exit;