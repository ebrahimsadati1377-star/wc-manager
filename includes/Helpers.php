<?php

function e($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$sent);
}

function requireCsrfOrFail(): void
{
    if (!checkCsrf()) {
        jsonResponse(['success' => false, 'message' => 'نشست شما نامعتبر است، صفحه را رفرش کنید.'], 419);
    }
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// ---------------- Settings (key/value in DB) ----------------

function getSetting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = Database::get()->query('SELECT setting_key, setting_value FROM settings');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function setSetting(string $key, string $value): void
{
    $stmt = Database::get()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = :v2'
    );
    $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
}

// ---------------- Activity log ----------------

function logActivity(string $action, string $target = '', string $details = ''): void
{
    try {
        $stmt = Database::get()->prepare(
            'INSERT INTO activity_log (user_id, action, target, details) VALUES (:uid, :action, :target, :details)'
        );
        $stmt->execute([
            'uid'     => $_SESSION['user']['id'] ?? null,
            'action'  => $action,
            'target'  => $target,
            'details' => $details,
        ]);
    } catch (Throwable $e) {
        // silent fail - logging should never break the app
    }
}

// ---------------- Misc ----------------

function formatPrice($price): string
{
    if ($price === '' || $price === null) {
        return '-';
    }
    return number_format((float)$price) . ' تومان';
}

function currentUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

function uploadUrlBase(): string
{
    if (defined('UPLOAD_URL_BASE') && UPLOAD_URL_BASE) {
        return rtrim(UPLOAD_URL_BASE, '/');
    }

    $publicRoot = realpath(APP_BASE_PATH); // مسیر فیزیکی پوشه public
    $docRoot    = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;

    if ($publicRoot && $docRoot && strpos($publicRoot, $docRoot) === 0) {
        $relative = str_replace('\\', '/', substr($publicRoot, strlen($docRoot)));
        return rtrim(currentUrl() . $relative, '/');
    }

    // fallback: فرض بر این‌که پوشه public همان ریشه دامنه است
    return rtrim(currentUrl(), '/');
}
