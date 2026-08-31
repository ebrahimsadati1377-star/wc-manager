<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.']);
}

$wc = new WooCommerceClient();
$res = $wc->deleteCategory($id);

if ($res['error']) {
    jsonResponse(['success' => false, 'message' => $res['error']]);
}

logActivity('delete_category', 'category', (string)$id);
jsonResponse(['success' => true]);
