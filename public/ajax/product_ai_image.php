<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/OpenAIAgent.php';
require_once __DIR__ . '/../../includes/ProductImageGenerator.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();
set_time_limit(300);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) jsonResponse(['success'=>false,'message'=>'داده نامعتبر است.'], 422);

try {
    $generator = new ProductImageGenerator();
    $result = $generator->generateSingle([
        'product_name'=>(string)($data['product_name'] ?? ''),
        'product_reference_image'=>(string)($data['image_url'] ?? ''),
        'aspect_ratio'=>'9:16',
        'instructions'=>(string)($data['instructions'] ?? ''),
        'wordpress_upload'=>true,
    ], (int)($data['index'] ?? 1), 7);
    $media = (array)($result['job']['wordpress_media'] ?? []);
    jsonResponse(['success'=>true,'image'=>[
        'id'=>(int)($media['id'] ?? 0),
        'src'=>(string)($media['source_url'] ?? ''),
        'name'=>(string)($media['slug'] ?? ''),
    ],'provider'=>$result['provider'] ?? '']);
} catch (Throwable $e) {
    error_log('[wc-manager] product AI image: '.$e->getMessage());
    jsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
}
