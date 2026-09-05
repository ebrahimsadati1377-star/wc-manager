<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

$method = apiRequireMethods(['GET', 'POST']);
requireChatgptApiAuth();

$wc = new WooCommerceClient();

if ($method === 'GET') {
    $allowed = [
        'context', 'page', 'per_page', 'search', 'after', 'before', 'exclude',
        'include', 'offset', 'order', 'orderby', 'parent', 'parent_exclude',
        'slug', 'status', 'type', 'sku', 'featured', 'category', 'tag',
        'shipping_class', 'attribute', 'attribute_term', 'tax_class',
        'on_sale', 'min_price', 'max_price', 'stock_status',
    ];
    $params = apiFilterArray($_GET, $allowed);
    $params['per_page'] = min(10, max(1, (int)($params['per_page'] ?? 20)));
    $params['page'] = max(1, (int)($params['page'] ?? 1));

    apiWooResponse($wc->getProducts($params));
}

$body = apiJsonBody();
$data = apiFilterArray($body, apiProductAllowedFields());
if (trim((string)($data['name'] ?? '')) === '') {
    jsonResponse([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'Product name is required.',
    ], 422);
}

$res = $wc->createProduct($data);
if (empty($res['error'])) {
    $id = (string)($res['body']['id'] ?? '');
    apiLogActivity('chatgpt_product_create', $id, (string)($data['name'] ?? ''));
}
apiWooResponse($res, 201);
