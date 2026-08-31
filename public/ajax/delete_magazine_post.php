<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    jsonResponse(['success' => false, 'message' => 'متد درخواست نامعتبر است.'], 405);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    jsonResponse(['success' => false, 'message' => 'شناسه مقاله نامعتبر است.'], 400);
}

$client = new WooCommerceClient();
$result = $client->deletePost($id);

if (!empty($result['error']) || ($result['status'] < 200 || $result['status'] >= 300)) {
    $status = ($result['status'] >= 400 && $result['status'] < 600) ? (int)$result['status'] : 502;
    jsonResponse([
        'success' => false,
        'message' => $result['error'] ?: 'حذف مقاله در وردپرس ناموفق بود.'
    ], $status);
}

logActivity('delete_post', 'magazine', 'Post ID: ' . $id);
jsonResponse(['success' => true]);
