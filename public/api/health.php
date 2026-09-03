<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['GET']);
requireChatgptApiAuth();

$wc = new WooCommerceClient();
if (!$wc->isConfigured()) {
    jsonResponse([
        'success' => false,
        'error' => 'woocommerce_not_configured',
        'message' => 'WooCommerce connection is not configured.',
    ], 503);
}

$res = $wc->ping();
if (!empty($res['error'])) {
    apiWooResponse($res);
}

jsonResponse([
    'success' => true,
    'service' => 'wc-manager-chatgpt-api',
    'woocommerce' => 'connected',
]);
