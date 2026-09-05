<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['POST']);
requireChatgptApiAuth();

$body = apiJsonBody();
$filename = trim((string)($body['filename'] ?? ''));
$base64 = (string)($body['base64'] ?? '');
$sourceUrl = trim((string)($body['url'] ?? $body['image_url'] ?? ''));

if ($filename === '' || ($base64 === '' && $sourceUrl === '')) {
    jsonResponse(['success'=>false,'error'=>'validation_error','message'=>'filename and either base64 or url are required.'], 422);
}

$binary = false;
if ($base64 !== '') {
    if (preg_match('/^data:[^;]+;base64,(.*)$/s', $base64, $m)) $base64 = $m[1];
    $binary = base64_decode($base64, true);
    if ($binary === false || $binary === '') {
        jsonResponse(['success'=>false,'error'=>'invalid_base64','message'=>'Invalid image data.'], 422);
    }
} else {
    $parts = parse_url($sourceUrl);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || empty($parts['host'])) {
        jsonResponse(['success'=>false,'error'=>'invalid_url','message'=>'A public http/https image URL is required.'], 422);
    }
    $host = strtolower((string)$parts['host']);
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || preg_match('/(^|\.)local$/', $host)) {
        jsonResponse(['success'=>false,'error'=>'invalid_url','message'=>'Local URLs are not allowed.'], 422);
    }
    $context = stream_context_create(['http'=>['timeout'=>15,'follow_location'=>1,'max_redirects'=>3,'user_agent'=>'BAJI-WC-Manager/1.0'],'https'=>['timeout'=>15]]);
    $binary = @file_get_contents($sourceUrl, false, $context, 0, 10 * 1024 * 1024 + 1);
    if ($binary === false || $binary === '') {
        jsonResponse(['success'=>false,'error'=>'download_failed','message'=>'Could not download image URL.'], 422);
    }
}

if (strlen($binary) > 10 * 1024 * 1024) {
    jsonResponse(['success'=>false,'error'=>'file_too_large','message'=>'Maximum image size is 10 MB.'], 413);
}

$imageInfo = @getimagesizefromstring($binary);
if ($imageInfo === false) {
    jsonResponse(['success'=>false,'error'=>'invalid_image','message'=>'Downloaded/uploaded content is not a valid image.'], 422);
}
$mime = strtolower((string)($imageInfo['mime'] ?? ''));
$allowedTypes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
if (!isset($allowedTypes[$mime])) {
    jsonResponse(['success'=>false,'error'=>'unsupported_image_type','message'=>'Only JPEG, PNG and WebP images are supported.'], 422);
}

$safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($filename, PATHINFO_FILENAME));
$safeBase = trim((string)$safeBase, '-_.');
if ($safeBase === '') $safeBase = 'image';
$safeName = $safeBase . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $allowedTypes[$mime];

$uploadDir = dirname(__DIR__) . '/uploads/chatgpt';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    jsonResponse(['success'=>false,'error'=>'upload_directory_error','message'=>'Could not create upload directory.'], 500);
}
$path = $uploadDir . '/' . $safeName;
if (file_put_contents($path, $binary, LOCK_EX) === false) {
    jsonResponse(['success'=>false,'error'=>'upload_failed','message'=>'Could not save image.'], 500);
}
@chmod($path, 0644);

$proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['REQUEST_SCHEME'] ?? 'https'));
if (!in_array($proto, ['http','https'], true)) $proto = 'https';
$host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'manage.bajistyle.ir');
$host = explode(',', $host)[0];
$baseUrl = rtrim($proto . '://' . trim($host), '/');
$url = $baseUrl . '/uploads/chatgpt/' . rawurlencode($safeName);

apiLogActivity('chatgpt_media_upload', $safeName, $mime . ' ' . strlen($binary) . ' bytes');
jsonResponse(['success'=>true,'url'=>$url,'filename'=>$safeName,'content_type'=>$mime,'size'=>strlen($binary),'width'=>(int)$imageInfo[0],'height'=>(int)$imageInfo[1]], 201);
