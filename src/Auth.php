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

    public static function status(Repository $repository): array
    {
        $user = self::user($repository);
        return [
            'authenticated' => $user !== null,
            'setupRequired' => !$repository->hasUsers(),
            'user' => $user,
        ];
    }

    public static function login(Repository $repository, mixed $pin): array
    {
        $user = is_string($pin) && preg_match('/^\d{6}$/', $pin) ? $repository->findUser('pin', $pin) : null;
        if ($user === null) {
            throw new HttpError(401, 'PIN inválido.');
        }

        self::signIn($user['id']);
        return self::status($repository);
    }

    public static function setup(Repository $repository, array $body): array
    {
        if ($repository->hasUsers()) {
            throw new HttpError(409, 'A configuração inicial já foi concluída.');
        }

        $user = $repository->saveUser(null, [
            'name' => $body['name'] ?? null,
            'pin' => $body['pin'] ?? null,
            'isAdmin' => true,
        ]);
        self::signIn($user['id']);
        return self::status($repository);
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

    public static function user(Repository $repository): ?array
    {
        $userId = is_string($_SESSION['auth_user_id'] ?? null) ? $_SESSION['auth_user_id'] : null;
        $user = $userId === null ? null : $repository->findUser('id', $userId);

        return $user === null ? null : ['id' => $user['id'], 'name' => $user['name'], 'isAdmin' => $user['isAdmin']];
    }

    private static function signIn(string $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['auth_user_id'] = $userId;
    }
}
