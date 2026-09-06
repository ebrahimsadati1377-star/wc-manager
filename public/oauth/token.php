<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/OAuthService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    oauthTokenError('invalid_request', 'POST is required.', 405);
}

$oauth = new WcManagerOAuthService();
$grantType = trim((string)($_POST['grant_type'] ?? ''));
try {
    if ($grantType === 'authorization_code') {
        $result = $oauth->redeemAuthorizationCode($_POST);
    } elseif ($grantType === 'refresh_token') {
        $result = $oauth->refreshAccessToken($_POST);
    } else {
        throw new WcManagerOAuthException('unsupported_grant_type', 'Supported grant types are authorization_code and refresh_token.');
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (WcManagerOAuthException $e) {
    oauthTokenError($e->errorCode, $e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[wc-manager] OAuth token error: ' . $e->getMessage());
    oauthTokenError('server_error', 'The authorization server could not complete the request.', 500);
}

function oauthTokenError(string $error, string $description, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $error, 'error_description' => $description], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
