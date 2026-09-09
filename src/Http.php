<?php

namespace ManyContext;

use Throwable;

final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class Http
{
    public static function json(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function bodyJson(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function fail(Throwable $error): void
    {
        if ($error instanceof HttpError) {
            self::json($error->status, ['error' => $error->getMessage()]);
            return;
        }

        self::json(500, ['error' => $error->getMessage()]);
    }
}
