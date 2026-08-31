<?php

class Auth
{
    private const LOGIN_WINDOW_SECONDS = 900;
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_LOCK_SECONDS = 900;

    public static function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        if (self::isLoginRateLimited($username)) {
            return false;
        }

        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            self::clearLoginFailures();
            self::recordPersistentThrottleReset($username);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'        => $user['id'],
                'full_name' => $user['full_name'],
                'username'  => $user['username'],
                'role'      => $user['role'],
            ];
            logActivity('login', 'auth', 'ورود کاربر ' . $user['username']);
            return true;
        }

        self::recordLoginFailure($username);
        return false;
    }

    public static function isLoginRateLimited(string $username = ''): bool
    {
        $state = self::loginAttemptState();
        if (($state['locked_until'] ?? 0) > time()) {
            return true;
        }

        return $username !== '' && self::persistentLoginRetryAfter($username) > 0;
    }

    public static function loginRetryAfter(string $username = ''): int
    {
        $state = self::loginAttemptState();
        $sessionRetry = max(0, (int)($state['locked_until'] ?? 0) - time());
        $persistentRetry = $username !== '' ? self::persistentLoginRetryAfter($username) : 0;
        return max($sessionRetry, $persistentRetry);
    }

    private static function loginAttemptState(): array
    {
        $now = time();
        $state = $_SESSION['login_attempts'] ?? ['count' => 0, 'window_started' => $now, 'locked_until' => 0];

        if (($state['locked_until'] ?? 0) <= $now && $now - (int)($state['window_started'] ?? $now) >= self::LOGIN_WINDOW_SECONDS) {
            $state = ['count' => 0, 'window_started' => $now, 'locked_until' => 0];
            $_SESSION['login_attempts'] = $state;
        }

        return $state;
    }

    private static function recordLoginFailure(string $username): void
    {
        $now = time();
        $state = self::loginAttemptState();
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        if ($state['count'] >= self::LOGIN_MAX_ATTEMPTS) {
            $state['locked_until'] = $now + self::LOGIN_LOCK_SECONDS;
        }
        $_SESSION['login_attempts'] = $state;

        // Store only a one-way key derived from client IP + username. This makes
        // throttling survive cookie/session resets without persisting the raw IP.
        logActivity('login_failed', self::loginThrottleKey($username), '');
    }

    private static function clearLoginFailures(): void
    {
        unset($_SESSION['login_attempts']);
    }

    private static function loginThrottleKey(string $username): string
    {
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $normalizedUsername = function_exists('mb_strtolower')
            ? mb_strtolower(trim($username), 'UTF-8')
            : strtolower(trim($username));

        return hash('sha256', $clientIp . "\0" . $normalizedUsername);
    }

    private static function persistentLoginRetryAfter(string $username): int
    {
        $key = self::loginThrottleKey($username);

        try {
            $db = Database::get();
            $cutoffTs = time() - self::LOGIN_WINDOW_SECONDS;

            $resetStmt = $db->prepare(
                "SELECT created_at FROM activity_log
                 WHERE action = 'login_rate_reset' AND target = :target
                 ORDER BY id DESC LIMIT 1"
            );
            $resetStmt->execute(['target' => $key]);
            $resetAt = $resetStmt->fetchColumn();
            if ($resetAt) {
                $resetTs = strtotime((string)$resetAt);
                if ($resetTs !== false) {
                    $cutoffTs = max($cutoffTs, $resetTs);
                }
            }

            $failureStmt = $db->prepare(
                "SELECT COUNT(*) AS failure_count, MAX(created_at) AS last_failure
                 FROM activity_log
                 WHERE action = 'login_failed'
                   AND target = :target
                   AND created_at >= :cutoff"
            );
            $failureStmt->execute([
                'target' => $key,
                'cutoff' => date('Y-m-d H:i:s', $cutoffTs),
            ]);
            $row = $failureStmt->fetch();

            if (!$row || (int)($row['failure_count'] ?? 0) < self::LOGIN_MAX_ATTEMPTS) {
                return 0;
            }

            $lastFailureTs = strtotime((string)($row['last_failure'] ?? ''));
            if ($lastFailureTs === false) {
                return self::LOGIN_LOCK_SECONDS;
            }

            return max(0, ($lastFailureTs + self::LOGIN_LOCK_SECONDS) - time());
        } catch (Throwable $e) {
            // Session throttling remains active if the audit table is temporarily unavailable.
            error_log('[wc-manager] persistent login throttle failed: ' . $e->getMessage());
            return 0;
        }
    }

    private static function recordPersistentThrottleReset(string $username): void
    {
        logActivity('login_rate_reset', self::loginThrottleKey($username), '');
    }

    public static function logout(): void
    {
        logActivity('logout', 'auth', '');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::check() && ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login.php');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            die('دسترسی غیرمجاز.');
        }
    }
}
