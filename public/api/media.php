<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';

apiRequireMethods(['POST']);
requireChatgptApiAuth();

$body = apiJsonBody();
$filename = trim((string)($body['filename'] ?? ''));
$contentType = strtolower(trim((string)($body['content_type'] ?? '')));
$base64 = (string)($body['base64'] ?? '');

$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if ($filename === '' || $base64 === '' || !isset($allowedTypes[$contentType])) {
    jsonResponse(['success'=>false,'error'=>'validation_error','message'=>'filename, supported content_type and base64 are required.'], 422);
}

if (preg_match('/^data:[^;]+;base64,(.*)$/s', $base64, $m)) {
    $base64 = $m[1];
}
$binary = base64_decode($base64, true);
if ($binary === false || $binary === '') {
    jsonResponse(['success'=>false,'error'=>'invalid_base64','message'=>'Invalid image data.'], 422);
}
if (strlen($binary) > 10 * 1024 * 1024) {
    jsonResponse(['success'=>false,'error'=>'file_too_large','message'=>'Maximum image size is 10 MB.'], 413);
}

$safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($filename, PATHINFO_FILENAME));
$safeBase = trim((string)$safeBase, '-_.');
if ($safeBase === '') $safeBase = 'image';
$safeName = $safeBase . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $allowedTypes[$contentType];

$uploadDir = dirname(__DIR__) . '/uploads/chatgpt';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    jsonResponse(['success'=>false,'error'=>'upload_directory_error','message'=>'Could not create upload directory.'], 500);
}

$path = $uploadDir . '/' . $safeName;
if (file_put_contents($path, $binary, LOCK_EX) === false) {
    jsonResponse(['success'=>false,'error'=>'upload_failed','message'=>'Could not save image.'], 500);
}
@chmod($path, 0644);

$baseUrl = rtrim((string)($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'manage.bajistyle.ir'), '/');
$url = $baseUrl . '/uploads/chatgpt/' . rawurlencode($safeName);

apiLogActivity('chatgpt_media_upload', $safeName, $contentType . ' ' . strlen($binary) . ' bytes');
jsonResponse(['success'=>true,'url'=>$url,'filename'=>$safeName,'content_type'=>$contentType,'size'=>strlen($binary)], 201);
