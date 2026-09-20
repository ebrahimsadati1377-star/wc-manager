<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/OpenAIAgent.php';
require_once __DIR__ . '/../../includes/ChatImageService.php';
require_once __DIR__ . '/../../includes/ProductImageGenerator.php';
Auth::requireLogin();
requirePostAndCsrfOrFail();
set_time_limit(300);

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) jsonResponse(['success'=>false,'message'=>'داده نامعتبر است.'], 422);

try {
    $args = [
        'product_name'=>(string)($data['product_name'] ?? ''),
        'product_reference_image'=>(string)($data['image_url'] ?? ''),
        'aspect_ratio'=>'9:16',
        'instructions'=>(string)($data['instructions'] ?? ''),
        'wordpress_upload'=>true,
    ];
    $faceReferenceUrl = trim((string)($data['face_reference_url'] ?? ''));
    if ($faceReferenceUrl !== '' && preg_match('#^https?://#i', $faceReferenceUrl)) {
        $args['face_reference_image'] = $faceReferenceUrl;
    }

    $generator = new ProductImageGenerator();
    $result = $generator->generateSingle($args, (int)($data['index'] ?? 1), 7);
    $media = (array)($result['job']['wordpress_media'] ?? []);
    $mediaId = (int)($media['id'] ?? 0);
    if ($mediaId < 1) throw new RuntimeException('تصویر در کتابخانه وردپرس ذخیره نشد.');

    $index = (int)($data['index'] ?? 1);
    $productName = trim((string)($data['product_name'] ?? 'محصول باجی'));
    $focusKeyword = trim((string)($data['focus_keyword'] ?? $productName));
    $wc = new WooCommerceClient();
    $mediaSeo = $wc->updateMedia($mediaId, [
        'alt_text' => $focusKeyword . ' - تصویر ' . $index,
        'title' => $productName . ' - ' . $index,
    ]);

    jsonResponse(['success'=>true,'image'=>[
        'id'=>$mediaId,
        'src'=>(string)($media['source_url'] ?? ''),
        'name'=>(string)($media['slug'] ?? ''),
    ],'provider'=>$result['provider'] ?? '', 'media_seo_updated'=>empty($mediaSeo['error'])]);
} catch (Throwable $e) {
    error_log('[wc-manager] product AI image: '.$e->getMessage());
    jsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
}
