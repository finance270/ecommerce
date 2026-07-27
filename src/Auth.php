<?php
declare(strict_types=1);

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['uid'] ?? null;
        if ($id === null) {
            return null;
        }
        static $cache = null;
        if ($cache === null) {
            $cache = Db::one('SELECT id, username, full_name, role FROM users WHERE id = ? AND is_active = 1', [$id]);
            if ($cache === null) {
                unset($_SESSION['uid']);
            }
        }
        return $cache;
    }

    public static function require(): array
    {
        $u = self::user();
        if ($u === null) {
            header('Location: login.php');
            exit;
        }
        return $u;
    }

    public static function attempt(string $username, string $password): bool
    {
        $row = Db::one('SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1', [$username]);
        if ($row === null || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];
        Db::q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function isAdmin(): bool
    {
        return (Auth::user()['role'] ?? '') === 'admin';
    }

    /** Token CSRF untuk form yang mengubah data. */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
