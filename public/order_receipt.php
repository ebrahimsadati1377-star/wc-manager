<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
require_once __DIR__ . '/../includes/OrderShipmentService.php';
require_once __DIR__ . '/../includes/OrderReceiptStorage.php';

$orderId = max(0, (int)($_GET['view'] ?? 0));
if ($orderId < 1) { http_response_code(404); exit('رسید یافت نشد.'); }
$wc = new WooCommerceClient();
$res = $wc->getOrder($orderId);
if (!empty($res['error']) || (int)($res['body']['id'] ?? 0) !== $orderId) {
    http_response_code(404);
    exit('رسید یافت نشد.');
}
$stored = OrderShipmentService::meta((array)$res['body'], '_baji_ship_receipt_file');
$path = OrderReceiptStorage::filePath($stored);
if (!$path || !is_file($path) || is_link($path)) {
    http_response_code(404);
    exit('رسید یافت نشد.');
}
$mime = OrderReceiptStorage::mime($stored);
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');
header('Content-Security-Policy: sandbox');
header('Content-Disposition: '.($mime==='application/pdf'?'attachment':'inline').'; filename="baji-receipt-'.$orderId.'.'.pathinfo($stored, PATHINFO_EXTENSION).'"');
readfile($path);
