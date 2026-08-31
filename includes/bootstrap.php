<?php
require_once __DIR__ . '/../config/config.php';

// Apply security defaults before any session-backed authentication work.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if ($isHttps) {
    ini_set('session.cookie_secure', '1');
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
