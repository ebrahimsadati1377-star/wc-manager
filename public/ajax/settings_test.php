<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireAdmin();
requireCsrfOrFail();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'متد درخواست نامعتبر است.'], 405);
}

$storeUrl = rtrim(trim($_POST['store_url'] ?? ''), '/');
$ck = trim($_POST['consumer_key'] ?? '');
$cs = trim($_POST['consumer_secret'] ?? '');

if ($storeUrl === '') {
    $storeUrl = rtrim((string)getSetting('store_url'), '/');
}
if ($ck === '') {
    $ck = (string)getSetting('consumer_key');
}
if ($cs === '') {
    $cs = (string)getSetting('consumer_secret');
}

if ($storeUrl === '' || $ck === '' || $cs === '') {
    jsonResponse(['success' => false, 'message' => 'اطلاعات اتصال ووکامرس کامل نیست.'], 400);
}

$wc = new WooCommerceClient($storeUrl, $ck, $cs);
$res = $wc->ping();

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => 'اتصال ووکامرس برقرار نشد.'], 502);
}

jsonResponse(['success' => true, 'message' => 'اتصال با موفقیت برقرار شد.']);
