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

$params = apiFilterArray($_GET, [
    'page', 'per_page', 'title', 'search', 'category', 'statuses',
    'stock_gte', 'stock_lte', 'preparation_day_gte', 'preparation_day_lte',
    'price_gte', 'price_lte', 'ids', 'skus', 'variants_flatting',
    'is_wholesale', 'sort',
]);

// Friendly alias for ChatGPT callers; Basalam's vendor-products contract calls it "title".
if (empty($params['title']) && !empty($params['search'])) {
    $params['title'] = (string)$params['search'];
}
unset($params['search']);

$params['page'] = max(1, (int)($params['page'] ?? 1));
$params['per_page'] = min(100, max(1, (int)($params['per_page'] ?? 20)));

foreach (['stock_gte', 'stock_lte', 'preparation_day_gte', 'preparation_day_lte', 'price_gte', 'price_lte'] as $key) {
    if (isset($params[$key]) && $params[$key] !== '') {
        $params[$key] = (int)$params[$key];
    }
}

foreach (['category', 'statuses', 'ids'] as $key) {
    if (!isset($params[$key])) continue;
    $values = is_array($params[$key]) ? $params[$key] : explode(',', (string)$params[$key]);
    $params[$key] = array_values(array_filter(array_map(
        static fn($value) => (int)trim((string)$value),
        $values
    ), static fn(int $value) => $value > 0));
    if (!$params[$key]) unset($params[$key]);
}

if (isset($params['skus'])) {
    $values = is_array($params['skus']) ? $params['skus'] : explode(',', (string)$params['skus']);
    $params['skus'] = array_values(array_filter(array_map('trim', $values), 'strlen'));
    if (!$params['skus']) unset($params['skus']);
}

foreach (['variants_flatting', 'is_wholesale'] as $key) {
    if (!isset($params[$key])) continue;
    $bool = filter_var($params[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($bool === null) {
        unset($params[$key]);
    } else {
        $params[$key] = $bool;
    }
}

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
