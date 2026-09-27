<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/WooOrderConfirmationSms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
$raw=file_get_contents('php://input');
if (!is_string($raw) || $raw==='' || strlen($raw)>1048576) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_body']);
    exit;
}
$secret=trim((string)getSetting('woo_order_confirmation_webhook_secret',''));
$signature=trim((string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? ''));
$expected=$secret!=='' ? base64_encode(hash_hmac('sha256',$raw,$secret,true)) : '';
if ($secret==='' || $signature==='' || !hash_equals($expected,$signature)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'invalid_signature']);
    exit;
}
$topic=strtolower(trim((string)($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? '')));
if (!in_array($topic,['order.created','order.updated'],true)) {
    echo json_encode(['ok'=>true,'status'=>'ignored_topic']);
    exit;
}
$data=json_decode($raw,true);
$id=is_array($data) ? (int)($data['id'] ?? 0) : 0;
if ($id<1) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_order_id']);
    exit;
}
try {
    $service=new WooOrderConfirmationSms(Database::get(),new IPPanelClient(),new WooCommerceClient());
    $result=$service->handle($id);
    echo json_encode(['ok'=>true,'status'=>$result['status']],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[wc-manager] order confirmation webhook failure order='.$id.' '.$e->getMessage());
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'temporary_failure']);
}
