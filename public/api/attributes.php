<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['GET']);
requireChatgptApiAuth();

$wc = new WooCommerceClient();

if (isset($_GET['attribute_id'])) {
    $attributeId = apiPositiveInt($_GET['attribute_id'], 'attribute_id');
    apiWooResponse($wc->getAttributeTerms($attributeId));
}

apiWooResponse($wc->getAttributes());
