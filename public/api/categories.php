<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

$method = apiRequireMethods(['GET', 'POST', 'PUT', 'PATCH']);
requireChatgptApiAuth();

$wc = new WooCommerceClient();

if ($method === 'GET') {
    $params = [];
    foreach (['page', 'per_page', 'search', 'parent', 'slug', 'hide_empty', 'order', 'orderby'] as $key) {
        if (isset($_GET[$key])) $params[$key] = $_GET[$key];
    }
    apiWooResponse($wc->getCategories($params));
}

$body = apiJsonBody();
$data = apiFilterArray($body, ['name', 'slug', 'parent', 'description', 'display', 'image']);
if (!$data) {
    jsonResponse([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'No supported category fields were provided.',
    ], 422);
}

if ($method === 'POST') {
    if (trim((string)($data['name'] ?? '')) === '') {
        jsonResponse([
            'success' => false,
            'error' => 'validation_error',
            'message' => 'Category name is required.',
        ], 422);
    }
    $res = $wc->createCategory($data);
    if (empty($res['error'])) {
        apiLogActivity('chatgpt_category_create', (string)($res['body']['id'] ?? ''), (string)$data['name']);
    }
    apiWooResponse($res, 201);
}

$id = apiPositiveInt($_GET['id'] ?? null, 'id');
$res = $wc->updateCategory($id, $data);
if (empty($res['error'])) {
    apiLogActivity('chatgpt_category_update', (string)$id, implode(',', array_keys($data)));
}
apiWooResponse($res);
