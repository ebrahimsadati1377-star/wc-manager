<?php

class Auth
{
    private const LOGIN_WINDOW_SECONDS = 900;
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_LOCK_SECONDS = 900;

    public static function attempt(string $username, string $password): bool
    {
        if (self::isLoginRateLimited()) {
            return false;
        }

        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            self::clearLoginFailures();
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

        self::recordLoginFailure();
        return false;
    }

    public static function isLoginRateLimited(): bool
    {
        $state = self::loginAttemptState();
        return ($state['locked_until'] ?? 0) > time();
    }

    public static function loginRetryAfter(): int
    {
        $state = self::loginAttemptState();
        return max(0, (int)($state['locked_until'] ?? 0) - time());
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

    private static function recordLoginFailure(): void
    {
        $now = time();
        $state = self::loginAttemptState();
        $state['count'] = (int)($state['count'] ?? 0) + 1;
        if ($state['count'] >= self::LOGIN_MAX_ATTEMPTS) {
            $state['locked_until'] = $now + self::LOGIN_LOCK_SECONDS;
        }
        $_SESSION['login_attempts'] = $state;
        logActivity('login_failed', 'auth', 'تلاش ورود ناموفق');
    }

    private static function clearLoginFailures(): void
    {
        unset($_SESSION['login_attempts']);
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
                'domain' => $params['domain'],
                'secure' => (bool)$params['secure'],
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
