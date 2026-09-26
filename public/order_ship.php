<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireAdmin();
require_once __DIR__ . '/../includes/OrderShipmentService.php';

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
    setFlash('danger', 'شماره سفارش نامعتبر است.');
    redirect('orders.php');
}
try {
    $service = new OrderShipmentService(new WooCommerceClient(), new IPPanelClient());
    $result = $service->process(
        $orderId,
        (string)($_POST['action'] ?? ''),
        (string)($_POST['carrier'] ?? ''),
        (string)($_POST['other_carrier'] ?? ''),
        (string)($_POST['tracking_code'] ?? '')
    );
    setFlash((string)$result['type'], (string)$result['message']);
} catch (Throwable $e) {
    error_log('[wc-manager] order shipment exception order=' . $orderId . ' error=' . $e->getMessage());
    setFlash('danger', 'ثبت ارسال با خطا روبه‌رو شد. وضعیت سفارش را قبل از اقدام دوباره بررسی کنید.');
}
redirect('orders.php?view=' . $orderId);
