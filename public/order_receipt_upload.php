<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
require_once __DIR__ . '/../includes/OrderShipmentService.php';
require_once __DIR__ . '/../includes/OrderReceiptStorage.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}
if (!checkCsrf()) {
    http_response_code(419);
    exit('نشست نامعتبر است؛ صفحه را رفرش کنید.');
}
$orderId = max(0, (int)($_POST['order_id'] ?? 0));
if ($orderId < 1) {
    setFlash('danger','شماره سفارش نامعتبر است.');
    redirect('orders.php');
}
try {
    $result = OrderReceiptStorage::upload($orderId, (array)($_FILES['receipt'] ?? []), new WooCommerceClient());
    setFlash((string)$result['type'], (string)$result['message']);
} catch (Throwable $e) {
    error_log('[wc-manager] receipt upload exception order='.$orderId.' error='.$e->getMessage());
    setFlash('danger','بارگذاری رسید با خطا مواجه شد. از ذخیره رسید در سفارش اطمینان پیدا کنید.');
}
redirect('orders.php?view='.$orderId.'#shipment');
