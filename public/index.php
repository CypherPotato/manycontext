<?php

use ManyContext\ApiController;
use ManyContext\AivaxClient;
use ManyContext\Auth;
use ManyContext\Config;
use ManyContext\Http;
use ManyContext\McpController;
use ManyContext\Repository;

require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/ApiController.php';
require_once __DIR__ . '/../src/McpController.php';
require_once __DIR__ . '/../src/AivaxClient.php';

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

try {
    $config = Config::load();
    $repository = new Repository($config);
    $aivax = new AivaxClient($repository->setting('aivax_api_key') ?? '', $repository->setting('aivax_model') ?? AivaxClient::DEFAULT_MODEL);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET' && preg_match('#^/public/documents/([^/]+)$#', $path, $match)) {
        (new ApiController($repository, ['id' => 'public', 'name' => 'Sistema', 'isAdmin' => false], $aivax))->handlePublicDocument(urldecode($match[1]));
        return;
    }

    if ($path === '/mcp') {
        (new McpController($repository, $aivax))->handle($method);
        return;
    }

    if ($path === '/run-cron-pendencies') {
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new ManyContext\HttpError(405, 'Method not allowed.');
        }
        $date = date('Y-m-d');
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($repository->users() as $user) {
            $existingSummary = $repository->dailySummary($user['id'], $date);
            if ($existingSummary !== null && $existingSummary['formatVersion'] >= Repository::DAILY_SUMMARY_FORMAT_VERSION) {
                $skipped++;
                continue;
            }
            try {
                $dashboard = $repository->homeDashboard($user);
                $aiDashboard = [
                    'date' => $dashboard['date'],
                    'stats' => $dashboard['stats'],
                    'assignedPriorities' => array_values(array_filter($dashboard['priorities'], fn (array $task) => $task['assignedToMe'])),
                    'unassignedPriorities' => array_slice(array_values(array_filter($dashboard['priorities'], fn (array $task) => !$task['assignedToMe'])), 0, 3),
                    'unread' => $dashboard['unread'],
                    'activity' => $dashboard['activity'],
                    'projects' => array_map(fn (array $project) => [
                        'name' => $project['name'],
                        'pendingTasksCount' => $project['pendingTasksCount'],
                        'completedTasksCount' => $project['completedTasksCount'],
                    ], $dashboard['projects']),
                ];
                $prompt = <<<PROMPT
Crie o briefing de trabalho de {$user['name']} para {$date}.

Regras obrigatórias:
- Use no máximo 220 palavras e priorize decisões, não inventário.
- Não use tabelas, emojis, título com nome ou data, nem repita dados da interface.
- Não despeje todas as tarefas. Escolha somente o que muda a ordem do dia.
- Trate apenas assignedPriorities como tarefas da pessoa.
- Se unassignedPriorities exigir atenção, cite no máximo duas em "Atenções do projeto" e deixe claro que estão sem responsável.
- Não invente prazos, bloqueios, responsáveis ou conclusões.

Formato:
Uma frase inicial dizendo qual deve ser o foco do dia.

## Foco agora
Até três itens numerados no formato **Projeto · #ID** — ação objetiva e motivo curto.

## Atenções
Até três bullets sobre vencimentos, conversas não lidas ou tarefas relevantes sem responsável.

## Avanços de hoje
Até três bullets do que a própria pessoa realizou. Omita esta seção se não houver atividade.

Se não houver tarefas atribuídas, diga isso em uma frase curta e use Atenções apenas para fatos realmente úteis.
PROMPT;
                $summary = $aivax->complete([
                    ['role' => 'system', 'content' => 'Você é um assistente de produtividade criterioso. Escreva em português do Brasil, com Markdown simples, direto e fiel aos dados.'],
                    ['role' => 'user', 'content' => $prompt . "\n\nDados:\n" . json_encode($aiDashboard, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ]);
                $repository->saveDailySummary($user['id'], $date, $summary);
                $generated++;
            } catch (Throwable) {
                $failed++;
            }
        }
        Http::json($failed > 0 ? 207 : 200, ['generated' => $generated, 'skipped' => $skipped, 'failed' => $failed, 'date' => $date]);
        return;
    }

    Auth::startSession();

    if ($path === '/auth/status' && $method === 'GET') {
        Http::json(200, [
            ...Auth::status($repository),
            'users' => array_map(fn (array $user) => ['id' => $user['id'], 'name' => $user['name'], 'isAdmin' => $user['isAdmin']], $repository->users()),
        ]);
        return;
    }

    if ($path === '/auth/login' && $method === 'POST') {
        Http::json(200, Auth::login($repository, Http::bodyJson()['pin'] ?? null));
        return;
    }

    if ($path === '/auth/setup' && $method === 'POST') {
        Http::json(201, Auth::setup($repository, Http::bodyJson()));
        return;
    }

    if ($path === '/auth/logout' && $method === 'POST') {
        Auth::logout();
        Http::json(200, ['authenticated' => false]);
        return;
    }

    if ($method === 'GET' && ($path === '/' || $path === '/index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . (Auth::user($repository) !== null ? '/ui.html' : '/login.html'));
        return;
    }

    if (str_starts_with($path, '/api/')) {
        $user = Auth::user($repository) ?? throw new ManyContext\HttpError(401, 'Autenticação necessária.');
        (new ApiController($repository, $user, $aivax))->handle($method, $path);
        return;
    }

    Http::json(404, ['error' => 'Not found']);
} catch (Throwable $error) {
    Http::fail($error);
}
