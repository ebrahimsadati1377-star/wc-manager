<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['id'])) {
    $client = new WooCommerceClient();
    $result = $client->deletePost((int)$_GET['id']);
    
    if ($result['status'] === 200 || $result['status'] === 204) {
        logActivity('delete_post', 'magazine', 'Post ID: ' . (int)$_GET['id']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'خطای نامشخص در حذف مقاله']);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر']);
}
