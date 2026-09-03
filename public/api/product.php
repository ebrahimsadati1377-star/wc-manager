<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

$method = apiRequireMethods(['GET', 'PUT', 'PATCH']);
requireChatgptApiAuth();

$id = apiPositiveInt($_GET['id'] ?? null, 'id');
$wc = new WooCommerceClient();

if ($method === 'GET') {
    apiWooResponse($wc->getProduct($id));
}

$body = apiJsonBody();
$data = apiFilterArray($body, apiProductAllowedFields());
if (!$data) {
    jsonResponse([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'No supported product fields were provided.',
    ], 422);
}

$res = $wc->updateProduct($id, $data);
if (empty($res['error'])) {
    apiLogActivity('chatgpt_product_update', (string)$id, implode(',', array_keys($data)));
}
apiWooResponse($res);
