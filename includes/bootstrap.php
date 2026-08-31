<?php
require_once __DIR__ . '/../config/config.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// The management panel is a production-facing web app. PHP warnings and stack
// details must go to server logs, never into HTML/JSON responses.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// Apply native session defaults when config.php has not started the session yet.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
}

// Some deployments start the session inside the private config.php. In that
// case PHP no longer allows changing session ini directives. Re-issue the
// active session cookie with hardened attributes so the browser still receives
// Secure/HttpOnly/SameSite on HTTPS responses. Login also regenerates the ID.
if (
    PHP_SAPI !== 'cli'
    && session_status() === PHP_SESSION_ACTIVE
    && session_id() !== ''
    && ini_get('session.use_cookies')
    && !headers_sent()
) {
    $params = session_get_cookie_params();
    $expires = 0;
    if ((int)($params['lifetime'] ?? 0) > 0) {
        $expires = time() + (int)$params['lifetime'];
    }

    setcookie(session_name(), session_id(), [
        'expires' => $expires,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store, private');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/WooCommerceClient.php';

// Every endpoint inside public/ajax is protected centrally. Existing endpoint-
// level CSRF checks remain harmless defense in depth.
enforceAjaxRequestSecurity();
