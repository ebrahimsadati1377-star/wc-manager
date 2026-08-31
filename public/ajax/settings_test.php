<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireAdmin();
requireCsrfOrFail();

$storeUrl = rtrim(trim($_POST['store_url'] ?? ''), '/');
$ck = trim($_POST['consumer_key'] ?? '');
$cs = trim($_POST['consumer_secret'] ?? '');

if ($storeUrl === '' || $ck === '' || $cs === '') {
    jsonResponse(['success' => false, 'message' => 'همه فیلدها را پر کنید.']);
}

$wc = new WooCommerceClient($storeUrl, $ck, $cs);
$res = $wc->ping();

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

jsonResponse(['success' => true, 'message' => 'اتصال با موفقیت برقرار شد.']);
