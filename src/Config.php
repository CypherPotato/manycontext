<?php

namespace ManyContext;

final class Config
{
    public function __construct(
        public readonly string $basePath,
        public readonly string $databasePath,
        public readonly string $attachmentsPath
    ) {
    }

    public static function load(?string $path = null): self
    {
        $basePath = dirname(__DIR__);
        $configPath = $path ?: ($_SERVER['MANYCONTEXT_CONFIG'] ?? getenv('MANYCONTEXT_CONFIG') ?: $basePath . DIRECTORY_SEPARATOR . 'config.ini');
        $data = is_file($configPath) ? parse_ini_file($configPath, true, INI_SCANNER_TYPED) : [];
        if ($data === false) {
            throw new HttpError(500, 'Could not read config.ini.');
        }

        return new self(
            $basePath,
            self::absolutePath($basePath, (string)($data['app']['database'] ?? 'storage/manycontext.sqlite')),
            self::absolutePath($basePath, (string)($data['app']['attachments'] ?? 'storage/attachments'))
        );
    }

    private static function absolutePath(string $basePath, string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path)) {
            return $path;
        }

        return $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
