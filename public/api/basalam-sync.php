<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['POST']);
requireChatgptApiAuth();

$body = apiJsonBody();
$wcProductId = apiPositiveInt($body['wc_product_id'] ?? null, 'wc_product_id');
$force = !empty($body['force']);

$sync = new BasalamSync();
$result = $sync->syncProduct($wcProductId, $force);

if (!$result['success']) {
    jsonResponse([
        'success' => false,
        'error' => 'basalam_sync_failed',
        'message' => $result['message'],
        'data' => $result,
    ], 422);
}

jsonResponse([
    'success' => true,
    'data' => $result,
]);
