<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();
requireCsrfOrFail();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'متد درخواست نامعتبر است.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(['success' => false, 'message' => 'داده ارسالی نامعتبر است.'], 400);
}

$action = (string)($input['action'] ?? '');
$postIds = $input['post_ids'] ?? [];

if (!in_array($action, ['delete', 'publish', 'draft'], true)) {
    jsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
}

if (!is_array($postIds) || empty($postIds)) {
    jsonResponse(['success' => false, 'message' => 'هیچ مقاله‌ای انتخاب نشده است.'], 400);
}

$postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), fn($id) => $id > 0)));
if (empty($postIds)) {
    jsonResponse(['success' => false, 'message' => 'شناسه مقاله معتبر نیست.'], 400);
}

$client = new WooCommerceClient();
$successful = 0;
$failed = 0;
$errors = [];

foreach ($postIds as $postId) {
    if ($action === 'delete') {
        $result = $client->deletePost($postId);
    } else {
        $result = $client->post('wp-json/wp/v2/posts/' . $postId, ['status' => $action]);
    }

    $ok = empty($result['error']) && ($result['status'] >= 200 && $result['status'] < 300);
    if ($ok) {
        $successful++;
        logActivity(
            $action === 'delete' ? 'delete_post' : 'update_post_status',
            'magazine',
            'Post ID: ' . $postId . ($action === 'delete' ? '' : ', status: ' . $action)
        );
        continue;
    }

    $failed++;
    $message = $result['error'] ?: ('خطای HTTP ' . (int)$result['status']);
    $errors[] = "مقاله #{$postId}: {$message}";
}

jsonResponse([
    'success' => $failed === 0,
    'partial' => $successful > 0 && $failed > 0,
    'successful' => $successful,
    'failed' => $failed,
    'errors' => $errors,
    'message' => $failed === 0
        ? 'عملیات با موفقیت انجام شد.'
        : ($successful > 0 ? 'عملیات با خطای جزئی انجام شد.' : 'عملیات انجام نشد.'),
]);
