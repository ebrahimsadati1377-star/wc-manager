<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/ChatGPTApi.php';
require_once __DIR__ . '/../../includes/ChatImageService.php';

apiRequireMethods(['POST']);
requireChatgptApiAuth();

try {
    $result = (new ChatImageService())->import(apiJsonBody());
    unset($result['local_path']);

    apiLogActivity(
        'chatgpt_media_upload',
        (string)($result['filename'] ?? ''),
        (string)($result['content_type'] ?? '') . ' ' . (int)($result['size'] ?? 0) . ' bytes'
    );

    jsonResponse($result, 201);
} catch (ChatImageException $e) {
    jsonResponse([
        'success' => false,
        'error' => $e->errorCode,
        'message' => $e->getMessage(),
    ], $e->status);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => 'upload_failed',
        'message' => 'Unexpected image upload failure.',
    ], 500);
}
