<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['action']) || !isset($input['post_ids'])) {
        throw new Exception('داده‌های ناقص ارسال شده است');
    }

    $action = $input['action'];
    $post_ids = $input['post_ids'];

    if (!is_array($post_ids) || empty($post_ids)) {
        throw new Exception('هیچ مقاله‌ای انتخاب نشده است');
    }

    $client = new WooCommerceClient();
    $successful = 0;
    $failed = 0;
    $errors = [];

    foreach ($post_ids as $post_id) {
        try {
            switch ($action) {
                case 'delete':
                    $client->delete('wp-json/wp/v2/posts/' . intval($post_id), ['force' => true]);
                    $successful++;
                    break;

                case 'publish':
                    $client->post('wp-json/wp/v2/posts/' . intval($post_id), [
                        'status' => 'publish'
                    ]);
                    $successful++;
                    break;

                case 'draft':
                    $client->post('wp-json/wp/v2/posts/' . intval($post_id), [
                        'status' => 'draft'
                    ]);
                    $successful++;
                    break;

                default:
                    throw new Exception('عملیات نامعتبر است');
            }
        } catch (Exception $e) {
            $failed++;
            $errors[] = "مقاله #{$post_id}: " . $e->getMessage();
        }
    }

    echo json_encode([
        'success' => true,
        'successful' => $successful,
        'failed' => $failed,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
