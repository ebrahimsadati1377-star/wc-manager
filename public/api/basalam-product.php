<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['GET']);
requireChatgptApiAuth();

$id = apiPositiveInt($_GET['id'] ?? null, 'id');
$basalam = new BasalamClient();
if (!$basalam->isConfigured()) {
    jsonResponse([
        'success' => false,
        'error' => 'basalam_not_configured',
        'message' => 'Basalam connection is not configured.',
    ], 503);
}

$res = $basalam->getProduct($id, true);
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

jsonResponse(['success' => true, 'data' => $res['body']]);
