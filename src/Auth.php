<?php

namespace ManyContext;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('manycontext_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function status(Config $config): array
    {
        $user = self::user($config);
        return [
            'authenticated' => $user !== null,
            'user' => $user,
        ];
    }

    public static function login(Config $config, mixed $pin): array
    {
        if (!is_string($pin) || !preg_match('/^\d{6}$/', $pin)) {
            throw new HttpError(401, 'PIN inválido.');
        }

        foreach ($config->authUsers as $user) {
            if (hash_equals($user['pin'], $pin)) {
                session_regenerate_id(true);
                $_SESSION['auth_user_id'] = $user['id'];
                unset($_SESSION['auth_user']);
                return self::status($config);
            }
        }

        throw new HttpError(401, 'PIN inválido.');
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
        }
        session_destroy();
    }

    public static function requireSession(Config $config): void
    {
        if (self::user($config) === null) {
            throw new HttpError(401, 'Autenticação necessária.');
        }
    }

    public static function user(Config $config): ?array
    {
        $userId = is_string($_SESSION['auth_user_id'] ?? null) ? $_SESSION['auth_user_id'] : null;
        $legacyName = is_array($_SESSION['auth_user'] ?? null) && is_string($_SESSION['auth_user']['name'] ?? null)
            ? $_SESSION['auth_user']['name']
            : null;
        foreach ($config->authUsers as $user) {
            if (($userId !== null && hash_equals($user['id'], $userId)) || ($userId === null && $legacyName !== null && hash_equals($user['name'], $legacyName))) {
                $_SESSION['auth_user_id'] = $user['id'];
                unset($_SESSION['auth_user']);
                return ['id' => $user['id'], 'name' => $user['name'], 'isAdmin' => $user['isAdmin']];
            }
        }
        return null;
    }
}
