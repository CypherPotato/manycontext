<?php

namespace ManyContext;

final class Config
{
    public function __construct(
        public readonly string $basePath,
        public readonly string $databasePath,
        public readonly string $attachmentsPath,
        public readonly array $authUsers,
        public readonly string $aivaxApiKey,
        public readonly string $aivaxModel
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

        $database = (string)($data['app']['database'] ?? 'storage/manycontext.sqlite');
        $attachments = (string)($data['app']['attachments'] ?? 'storage/attachments');
        $token = (string)($data['mcp']['token'] ?? '');
        $aivax = is_array($data['aivax'] ?? null) ? $data['aivax'] : [];
        $configuredUsers = [];
        $envUsers = trim((string)(getenv('MANYCONTEXT_AUTH_USERS') ?: ''));
        if ($envUsers !== '') {
            $decodedUsers = json_decode($envUsers, true);
            if (!is_array($decodedUsers) || !array_is_list($decodedUsers)) {
                throw new HttpError(500, 'MANYCONTEXT_AUTH_USERS must be a JSON array.');
            }
            foreach ($decodedUsers as $index => $values) {
                if (is_array($values)) {
                    $configuredUsers[] = ['section' => 'env.' . $index, 'values' => $values];
                }
            }
        } else {
            foreach ($data as $section => $values) {
                if (is_string($section) && str_starts_with($section, 'auth.') && is_array($values)) {
                    $configuredUsers[] = ['section' => $section, 'values' => $values];
                }
            }
        }

        $authUsers = [];
        foreach ($configuredUsers as $configuredUser) {
            $values = $configuredUser['values'];
            $sectionId = substr($configuredUser['section'], strpos($configuredUser['section'], '.') + 1);
            $name = trim((string)($values['name'] ?? ''));
            $pin = trim((string)($values['pin'] ?? ''));
            $id = trim((string)($values['id'] ?? '')) ?: 'user-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $sectionId);
            $mcpKey = trim((string)($values['mcp_key'] ?? $values['McpKey'] ?? $values['mcpKey'] ?? ''));
            $isAdmin = filter_var($values['is_admin'] ?? $values['IsAdmin'] ?? $values['isAdmin'] ?? false, FILTER_VALIDATE_BOOL);
            if ($name === '' || !preg_match('/^\d{6}$/', $pin) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $id)) {
                continue;
            }
            $authUsers[] = ['id' => $id, 'name' => $name, 'pin' => $pin, 'mcpKey' => $mcpKey, 'isAdmin' => $isAdmin];
        }

        if (count($authUsers) > 0 && !array_filter($authUsers, fn (array $user) => $user['isAdmin'])) {
            $authUsers[0]['isAdmin'] = true;
        }
        if (count($authUsers) > 0 && !array_filter($authUsers, fn (array $user) => $user['mcpKey'] !== '') && $token !== '') {
            $authUsers[0]['mcpKey'] = $token;
        }

        return new self(
            $basePath,
            self::absolutePath($basePath, $database),
            self::absolutePath($basePath, $attachments),
            $authUsers,
            trim((string)(getenv('AIVAX_API_KEY') ?: ($aivax['api_key'] ?? ''))),
            trim((string)(getenv('AIVAX_MODEL') ?: ($aivax['model'] ?? ''))) ?: '@inception/mercury-2'
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
