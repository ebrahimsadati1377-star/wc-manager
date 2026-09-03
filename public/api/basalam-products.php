<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['GET']);
requireChatgptApiAuth();

$basalam = new BasalamClient();
if (!$basalam->isConfigured()) {
    jsonResponse([
        'success' => false,
        'error' => 'basalam_not_configured',
        'message' => 'Basalam connection is not configured.',
    ], 503);
}

$params = apiFilterArray($_GET, ['page', 'per_page', 'search', 'status', 'category_id']);
$params['page'] = max(1, (int)($params['page'] ?? 1));
$params['per_page'] = min(100, max(1, (int)($params['per_page'] ?? 20)));

$res = $basalam->getVendorProducts($params);
if (!empty($res['error'])) {
    $status = (int)($res['status'] ?? 0);
    if ($status < 400 || $status > 599) $status = 502;
    jsonResponse([
        'success' => false,
        'error' => 'basalam_error',
        'message' => (string)$res['error'],
        'upstream_status' => (int)($res['status'] ?? 0),
    ], $status);
}

jsonResponse([
    'success' => true,
    'data' => $res['body']['data'] ?? [],
    'meta' => [
        'total_count' => $res['body']['total_count'] ?? null,
        'result_count' => $res['body']['result_count'] ?? null,
        'total_page' => $res['body']['total_page'] ?? null,
        'page' => $res['body']['page'] ?? $params['page'],
        'per_page' => $res['body']['per_page'] ?? $params['per_page'],
    ],
]);
