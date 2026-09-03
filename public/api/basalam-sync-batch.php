<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['POST']);
requireChatgptApiAuth();

$body = apiJsonBody();
$rawIds = $body['wc_product_ids'] ?? [];
if (!is_array($rawIds)) {
    jsonResponse([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'wc_product_ids must be an array.',
    ], 422);
}

$ids = array_values(array_unique(array_filter(array_map(
    static fn($id) => (int)$id,
    $rawIds
), static fn(int $id) => $id > 0)));

if (!$ids) {
    jsonResponse([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'At least one valid WooCommerce product ID is required.',
    ], 422);
}

if (count($ids) > 20) {
    jsonResponse([
        'success' => false,
        'error' => 'batch_too_large',
        'message' => 'A maximum of 20 products can be synchronized per request.',
    ], 422);
}

$force = !empty($body['force']);
$sync = new BasalamSync();
$results = [];
$successCount = 0;
$failedCount = 0;
$skippedCount = 0;

foreach ($ids as $id) {
    $result = $sync->syncProduct($id, $force);
    $results[] = $result;
    if (!empty($result['success'])) {
        $successCount++;
        if (!empty($result['skipped'])) $skippedCount++;
    } else {
        $failedCount++;
    }
}

apiLogActivity(
    'chatgpt_basalam_batch_sync',
    'basalam',
    sprintf('requested=%d success=%d failed=%d skipped=%d', count($ids), $successCount, $failedCount, $skippedCount)
);

jsonResponse([
    'success' => $failedCount === 0,
    'data' => [
        'requested' => count($ids),
        'success_count' => $successCount,
        'failed_count' => $failedCount,
        'skipped_count' => $skippedCount,
        'results' => $results,
    ],
], $failedCount === 0 ? 200 : 207);
