<?php

class Auth
{
    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
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
        return false;
    }

    public static function logout(): void
    {
        logActivity('logout', 'auth', '');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
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
            die('دسترسی غیرمجاز. این بخش فقط برای مدیران سیستم است.');
        }
    }
}
