<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

$method = apiRequireMethods(['GET', 'POST', 'PUT', 'PATCH']);
requireChatgptApiAuth();

$productId = apiPositiveInt($_GET['product_id'] ?? null, 'product_id');
$wc = new WooCommerceClient();

if ($method === 'GET') {
    $params = [];
    if (isset($_GET['page'])) $params['page'] = max(1, (int)$_GET['page']);
    if (isset($_GET['per_page'])) $params['per_page'] = min(100, max(1, (int)$_GET['per_page']));
    apiWooResponse($wc->getVariations($productId, $params));
}

$body = apiJsonBody();
$data = apiFilterArray($body, apiVariationAllowedFields());
if (!$data) {
    jsonResponse([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'No supported variation fields were provided.',
    ], 422);
}

if ($method === 'POST') {
    $res = $wc->createVariation($productId, $data);
    if (empty($res['error'])) {
        apiLogActivity(
            'chatgpt_variation_create',
            $productId . ':' . (string)($res['body']['id'] ?? ''),
            implode(',', array_keys($data))
        );
    }
    apiWooResponse($res, 201);
}

$variationId = apiPositiveInt($_GET['variation_id'] ?? null, 'variation_id');
$res = $wc->updateVariation($productId, $variationId, $data);
if (empty($res['error'])) {
    apiLogActivity(
        'chatgpt_variation_update',
        $productId . ':' . $variationId,
        implode(',', array_keys($data))
    );
}
apiWooResponse($res);
