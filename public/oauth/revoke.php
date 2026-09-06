<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/OAuthService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
if ($token !== '') {
    try {
        (new WcManagerOAuthService())->revoke($token);
        logActivity('oauth_token_revoked', 'client:chatgpt', '');
    } catch (Throwable $e) {
        error_log('[wc-manager] OAuth revoke error: ' . $e->getMessage());
    }
}
http_response_code(200);
echo '{}';
