<?php

namespace ManyContext;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class Repository
{
    public const DAILY_SUMMARY_FORMAT_VERSION = 2;

    private PDO $db;

    private const DEFAULT_SETTINGS = [
        'statuses' => ['todo', 'in-progress', 'done'],
        'priorities' => ['low', 'medium', 'high'],
        'defaultStatus' => 'todo',
        'defaultPriority' => 'medium',
        'doneStatus' => 'done',
    ];

    private const IMAGE_EXTENSIONS = ['.apng', '.avif', '.bmp', '.gif', '.heic', '.heif', '.jpeg', '.jpg', '.png', '.svg', '.webp'];
    private const DOCUMENT_TYPES = ['folder', 'file'];

    public function __construct(private readonly Config $config)
    {
        $dir = dirname($this->config->databasePath);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new HttpError(500, 'Could not create database directory.');
        }

        $this->db = new PDO('sqlite:' . $this->config->databasePath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $hadProjectMembers = (bool)$this->db->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'project_members'")->fetchColumn();
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS projects (
                name TEXT PRIMARY KEY,
                settings TEXT NOT NULL,
                next_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS tasks (
                project_name TEXT NOT NULL,
                id INTEGER NOT NULL,
                title TEXT NOT NULL,
                desc TEXT NOT NULL,
                due TEXT NULL,
                priority TEXT NOT NULL,
                tags TEXT NOT NULL,
                group_name TEXT NULL,
                status TEXT NOT NULL,
                working_agent TEXT NULL,
                assignees TEXT NOT NULL DEFAULT \'[]\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (project_name, id),
                FOREIGN KEY (project_name) REFERENCES projects(name) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS attachments (
                project_name TEXT NOT NULL,
                task_id INTEGER NOT NULL,
                id INTEGER NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                path TEXT NOT NULL,
                original_path TEXT NULL,
                size INTEGER NOT NULL,
                added_at TEXT NOT NULL,
                PRIMARY KEY (project_name, task_id, id),
                FOREIGN KEY (project_name, task_id) REFERENCES tasks(project_name, id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS task_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT NOT NULL,
                task_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                user_name TEXT NOT NULL,
                content TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                deleted_at TEXT NULL,
                FOREIGN KEY (project_name, task_id) REFERENCES tasks(project_name, id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS task_conversation_reads (
                project_name TEXT NOT NULL,
                task_id INTEGER NOT NULL,
                user_name TEXT NOT NULL,
                last_read_event_id INTEGER NOT NULL,
                read_at TEXT NOT NULL,
                PRIMARY KEY (project_name, task_id, user_name),
                FOREIGN KEY (project_name, task_id) REFERENCES tasks(project_name, id) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS project_members (
                project_name TEXT NOT NULL,
                user_id TEXT NOT NULL,
                added_at TEXT NOT NULL,
                PRIMARY KEY (project_name, user_id),
                FOREIGN KEY (project_name) REFERENCES projects(name) ON DELETE CASCADE
            );
            CREATE TABLE IF NOT EXISTS daily_ai_summaries (
                user_id TEXT NOT NULL,
                summary_date TEXT NOT NULL,
                content TEXT NOT NULL,
                generated_at TEXT NOT NULL,
                format_version INTEGER NOT NULL DEFAULT 2,
                PRIMARY KEY (user_id, summary_date)
            );
            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE COLLATE NOCASE,
                pin TEXT NOT NULL UNIQUE,
                mcp_key TEXT NOT NULL UNIQUE,
                is_admin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS app_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS document_nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_name TEXT NOT NULL,
                parent_id INTEGER NULL,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                content TEXT NOT NULL DEFAULT "",
                public_token TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (project_name) REFERENCES projects(name) ON DELETE CASCADE,
                FOREIGN KEY (parent_id) REFERENCES document_nodes(id) ON DELETE CASCADE
            );
            CREATE VIRTUAL TABLE IF NOT EXISTS document_fts USING fts5(
                title,
                content,
                project_name UNINDEXED,
                document_id UNINDEXED
            );
        ');
        $projectColumns = array_column($this->db->query('PRAGMA table_info(projects)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('display_name', $projectColumns, true)) {
            $this->db->exec('ALTER TABLE projects ADD COLUMN display_name TEXT NULL');
        }
        if (!in_array('archived_at', $projectColumns, true)) {
            $this->db->exec('ALTER TABLE projects ADD COLUMN archived_at TEXT NULL');
        }
        $taskColumns = array_map(fn (array $row) => $row['name'], $this->db->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_ASSOC));
        if (!in_array('assignees', $taskColumns, true)) {
            $this->db->exec("ALTER TABLE tasks ADD COLUMN assignees TEXT NOT NULL DEFAULT '[]'");
        }
        if (!in_array('sub_tasks', $taskColumns, true)) {
            $this->db->exec("ALTER TABLE tasks ADD COLUMN sub_tasks TEXT NOT NULL DEFAULT '[]'");
        }
        if (!in_array('archived_at', $taskColumns, true)) {
            $this->db->exec('ALTER TABLE tasks ADD COLUMN archived_at TEXT NULL');
        }
        $dailySummaryColumns = array_map(fn (array $row) => $row['name'], $this->db->query('PRAGMA table_info(daily_ai_summaries)')->fetchAll(PDO::FETCH_ASSOC));
        if (!in_array('format_version', $dailySummaryColumns, true)) {
            $this->db->exec('ALTER TABLE daily_ai_summaries ADD COLUMN format_version INTEGER NOT NULL DEFAULT 1');
        }
        if (!$hadProjectMembers) {
            $projects = $this->db->query('SELECT name FROM projects')->fetchAll(PDO::FETCH_COLUMN);
            $insertMember = $this->db->prepare('INSERT OR IGNORE INTO project_members (project_name, user_id, added_at) VALUES (:project_name, :user_id, :added_at)');
            foreach ($projects as $projectName) {
                foreach ($this->users() as $user) {
                    $insertMember->execute([':project_name' => $projectName, ':user_id' => $user['id'], ':added_at' => $this->now()]);
                }
            }
        }
    }

    public function hasUsers(): bool
    {
        return (bool)$this->db->query('SELECT 1 FROM users LIMIT 1')->fetchColumn();
    }

    public function users(): array
    {
        return array_map(
            fn (array $row) => $this->userFromRow($row),
            $this->db->query('SELECT * FROM users ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findUser(string $field, string $value): ?array
    {
        $column = match ($field) {
            'id' => 'id',
            'pin' => 'pin',
            'mcpKey' => 'mcp_key',
        };
        $stmt = $this->db->prepare("SELECT * FROM users WHERE $column = :value");
        $stmt->execute([':value' => $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->userFromRow($row) : null;
    }

    public function saveUser(?string $id, array $body): array
    {
        $current = $id === null ? null : ($this->findUser('id', $id) ?? throw new HttpError(404, 'Usuário não encontrado.'));

        $name = $current['name'] ?? '';
        if ($current === null || array_key_exists('name', $body)) {
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            if ($name === '' || mb_strlen($name, 'UTF-8') > 80) {
                throw new HttpError(400, 'Informe um nome com até 80 caracteres.');
            }
        }

        $pin = $current['pin'] ?? null;
        if (array_key_exists('pin', $body)) {
            if (!is_string($body['pin']) || !preg_match('/^\d{6}$/', $body['pin'])) {
                throw new HttpError(400, 'O PIN deve ter exatamente 6 dígitos.');
            }
            $pin = $body['pin'];
        }
        for ($attempt = 0; $pin === null && $attempt < 20; $attempt++) {
            $candidate = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pin = $this->findUser('pin', $candidate) === null ? $candidate : null;
        }
        if ($pin === null) {
            throw new HttpError(500, 'Não foi possível gerar um PIN livre.');
        }

        $isAdmin = $current['isAdmin'] ?? false;
        if (array_key_exists('isAdmin', $body)) {
            if (!is_bool($body['isAdmin'])) {
                throw new HttpError(400, 'isAdmin deve ser booleano.');
            }
            $isAdmin = $body['isAdmin'];
        }

        $mcpKey = $current === null || ($body['rotateMcpKey'] ?? false) === true
            ? 'mc_' . bin2hex(random_bytes(24))
            : $current['mcpKey'];

        $userId = $current['id'] ?? null;
        if ($userId === null) {
            $requestedId = is_string($body['id'] ?? null) ? trim($body['id']) : '';
            $userId = $requestedId !== '' ? $requestedId : trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            if ($requestedId === '' && ($userId === '' || $this->findUser('id', $userId) !== null)) {
                $userId = ($userId !== '' ? $userId . '-' : 'user-') . bin2hex(random_bytes(3));
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $userId)) {
                throw new HttpError(400, 'O ID deve conter apenas letras, números, "_" e "-" (até 64 caracteres).');
            }
        }

        $now = $this->now();
        $values = [':id' => $userId, ':name' => $name, ':pin' => $pin, ':mcp_key' => $mcpKey, ':is_admin' => $isAdmin ? 1 : 0, ':now' => $now];
        $this->db->beginTransaction();
        try {
            if ($current === null) {
                $this->db->prepare('INSERT INTO users (id, name, pin, mcp_key, is_admin, created_at, updated_at) VALUES (:id, :name, :pin, :mcp_key, :is_admin, :now, :now)')->execute($values);
            } else {
                $this->db->prepare('UPDATE users SET name = :name, pin = :pin, mcp_key = :mcp_key, is_admin = :is_admin, updated_at = :now WHERE id = :id')->execute($values);
            }

            if ($current !== null && $current['name'] !== $name) {
                $rename = [':old' => $current['name'], ':new' => $name];
                $this->db->prepare('UPDATE task_events SET user_name = :new WHERE user_name = :old')->execute($rename);
                $this->db->prepare('UPDATE OR REPLACE task_conversation_reads SET user_name = :new WHERE user_name = :old')->execute($rename);
                $tasks = $this->db->prepare('SELECT project_name, id, assignees FROM tasks WHERE instr(assignees, :old_json) > 0');
                $tasks->execute([':old_json' => $this->json($current['name'])]);
                $updateAssignees = $this->db->prepare('UPDATE tasks SET assignees = :assignees WHERE project_name = :project_name AND id = :id');
                foreach ($tasks->fetchAll(PDO::FETCH_ASSOC) as $task) {
                    $assignees = array_map(fn (mixed $assignee) => $assignee === $current['name'] ? $name : $assignee, json_decode($task['assignees'], true) ?: []);
                    $updateAssignees->execute([':assignees' => $this->json(array_values(array_unique($assignees))), ':project_name' => $task['project_name'], ':id' => $task['id']]);
                }
            }
            $this->db->commit();
        } catch (\PDOException $error) {
            $this->db->rollBack();
            if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                throw new HttpError(409, match (true) {
                    str_contains($error->getMessage(), 'users.pin') => 'Este PIN já está em uso por outro usuário.',
                    str_contains($error->getMessage(), 'users.name') => 'Já existe um usuário com este nome.',
                    default => 'Já existe um usuário com este ID.',
                });
            }
            throw $error;
        }

        return $this->findUser('id', $userId);
    }

    public function deleteUser(string $id): void
    {
        if ($this->findUser('id', $id) === null) {
            throw new HttpError(404, 'Usuário não encontrado.');
        }

        $this->db->beginTransaction();
        try {
            foreach (['project_members', 'daily_ai_summaries', 'users'] as $table) {
                $column = $table === 'users' ? 'id' : 'user_id';
                $this->db->prepare("DELETE FROM $table WHERE $column = :id")->execute([':id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function setting(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM app_settings WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function saveSetting(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->db->prepare('DELETE FROM app_settings WHERE key = :key')->execute([':key' => $key]);
            return;
        }

        $this->db->prepare('INSERT INTO app_settings (key, value, updated_at) VALUES (:key, :value, :now) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute([':key' => $key, ':value' => $value, ':now' => $this->now()]);
    }

    public function projectSummaries(?string $userId = null, bool $isAdmin = false, bool $includeArchived = false): array
    {
        $archivedFilter = $includeArchived ? '' : ' WHERE p.archived_at IS NULL';
        if ($userId !== null && !$isAdmin) {
            $stmt = $this->db->prepare('SELECT p.* FROM projects p JOIN project_members m ON m.project_name = p.name AND m.user_id = :user_id' . $archivedFilter . ' ORDER BY p.name COLLATE NOCASE');
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $this->db->query('SELECT p.* FROM projects p' . $archivedFilter . ' ORDER BY p.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
        }
        return array_map(fn (array $row) => $this->projectSummary($row), $rows);
    }

    public function setProjectArchived(string $name, bool $archived): array
    {
        $name = $this->projectName($name);
        $row = $this->projectRow($name);
        if (!$row) {
            throw new HttpError(404, sprintf('Project "%s" was not found.', $name));
        }
        if (($row['archived_at'] !== null) !== $archived) {
            $this->db->prepare('UPDATE projects SET archived_at = :archived_at WHERE name = :name')
                ->execute([':archived_at' => $archived ? $this->now() : null, ':name' => $name]);
        }
        return $this->projectSummary($this->projectRow($name));
    }

    public function projectExists(string $name): bool
    {
        return $this->projectRow($this->projectName($name)) !== null;
    }

    public function requireProjectAccess(string $name, string $userId, bool $isAdmin): void
    {
        $name = $this->projectName($name);
        $row = $this->projectRow($name);
        if (!$row) {
            throw new HttpError(404, sprintf('Project "%s" was not found.', $name));
        }
        if ($row['archived_at'] !== null) {
            throw new HttpError(403, 'Este projeto está arquivado.');
        }
        if ($isAdmin) {
            return;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM project_members WHERE project_name = :project_name AND user_id = :user_id');
        $stmt->execute([':project_name' => $name, ':user_id' => $userId]);
        if (!$stmt->fetchColumn()) {
            throw new HttpError(403, 'Você não tem acesso a este projeto.');
        }
    }

    public function projectMembers(string $name): array
    {
        $name = $this->projectName($name);
        if (!$this->projectRow($name)) {
            throw new HttpError(404, sprintf('Project "%s" was not found.', $name));
        }
        $stmt = $this->db->prepare('SELECT user_id FROM project_members WHERE project_name = :project_name');
        $stmt->execute([':project_name' => $name]);
        $memberIds = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        return array_map(fn (array $user) => [
            'id' => $user['id'],
            'name' => $user['name'],
            'isAdmin' => $user['isAdmin'],
            'isMember' => $user['isAdmin'] || isset($memberIds[$user['id']]),
        ], $this->users());
    }

    public function setProjectMembers(string $name, array $userIds): array
    {
        $name = $this->projectName($name);
        $knownUsers = [];
        foreach ($this->users() as $user) {
            $knownUsers[$user['id']] = $user;
        }
        $selected = [];
        foreach ($userIds as $userId) {
            if (is_string($userId) && isset($knownUsers[$userId])) {
                $selected[$userId] = true;
            }
        }
        foreach ($knownUsers as $userId => $user) {
            if ($user['isAdmin']) {
                $selected[$userId] = true;
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM project_members WHERE project_name = :project_name')->execute([':project_name' => $name]);
            $insert = $this->db->prepare('INSERT INTO project_members (project_name, user_id, added_at) VALUES (:project_name, :user_id, :added_at)');
            foreach (array_keys($selected) as $userId) {
                $insert->execute([':project_name' => $name, ':user_id' => $userId, ':added_at' => $this->now()]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return $this->projectMembers($name);
    }

    public function homeDashboard(array $user, ?string $activityDate = null): array
    {
        $today = date('Y-m-d');
        $activityDate ??= $today;
        $parsedActivityDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $activityDate);
        if (!$parsedActivityDate || $parsedActivityDate->format('Y-m-d') !== $activityDate) {
            throw new HttpError(400, 'activityDate must be a valid date.');
        }
        $utc = new \DateTimeZone('UTC');
        $activityStart = $parsedActivityDate->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
        $activityEnd = $parsedActivityDate->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
        $projects = $this->projectSummaries($user['id'], $user['isAdmin']);
        $priorities = [];
        $unread = [];
        $stats = ['assignedPending' => 0, 'overdue' => 0, 'unreadConversations' => 0, 'completedToday' => 0];
        foreach ($projects as $project) {
            $row = $this->projectRow($project['name']);
            $doneStatus = $row ? $this->settingsFromRow($row)['doneStatus'] : 'done';
            foreach ($this->listTasks($project['name'], $user['name']) as $task) {
                if ($task['archivedAt'] !== null) {
                    continue;
                }
                $assignees = array_map(fn (string $name) => mb_strtolower($name, 'UTF-8'), $task['assignees'] ?? []);
                $assigned = in_array(mb_strtolower($user['name'], 'UTF-8'), $assignees, true);
                $done = $task['status'] === $doneStatus;
                $unreadCount = (int)($task['unreadCommentCount'] ?? 0);
                if ($assigned && !$done) {
                    $stats['assignedPending']++;
                }
                if (!$done && $task['due'] !== null && $task['due'] < $today && ($assigned || count($assignees) === 0)) {
                    $stats['overdue']++;
                }
                if ($done && str_starts_with($task['updatedAt'], $today)) {
                    $stats['completedToday']++;
                }
                if ($unreadCount > 0) {
                    $stats['unreadConversations'] += $unreadCount;
                    $unread[] = [
                        'project' => $project['name'],
                        'id' => $task['id'],
                        'title' => $task['title'],
                        'count' => $unreadCount,
                    ];
                }
                if ($done || (!$assigned && count($assignees) > 0)) {
                    continue;
                }
                $score = ($assigned ? 100 : 0)
                    + ($task['due'] !== null && $task['due'] < $today ? 80 : 0)
                    + ($task['due'] === $today ? 35 : 0)
                    + match ($task['priority']) { 'high' => 30, 'medium' => 15, default => 0 }
                    + min(20, $unreadCount * 5);
                $priorities[] = [
                    'project' => $project['name'],
                    'id' => $task['id'],
                    'title' => $task['title'],
                    'priority' => $task['priority'],
                    'due' => $task['due'],
                    'status' => $task['status'],
                    'unreadCommentCount' => $unreadCount,
                    'assignedToMe' => $assigned,
                    '_score' => $score,
                ];
            }
        }
        usort($priorities, fn (array $a, array $b) => $b['_score'] <=> $a['_score'] ?: strcmp((string)($a['due'] ?? '9999-12-31'), (string)($b['due'] ?? '9999-12-31')));
        $priorities = array_map(function (array $task): array {
            unset($task['_score']);
            return $task;
        }, array_slice($priorities, 0, 8));
        usort($unread, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        $activity = [];
        if (count($projects) > 0) {
            $params = [
                ':user_name' => $user['name'],
                ':activity_start' => $activityStart,
                ':activity_end' => $activityEnd,
            ];
            $placeholders = [];
            foreach (array_values($projects) as $index => $project) {
                $placeholder = ':project_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $project['name'];
            }
            $stmt = $this->db->prepare('
                SELECT e.project_name, e.task_id, e.type, e.content, e.data, e.created_at, t.title
                FROM task_events e
                JOIN tasks t ON t.project_name = e.project_name AND t.id = e.task_id
                WHERE e.project_name IN (' . implode(', ', $placeholders) . ')
                  AND e.user_name = :user_name
                  AND e.deleted_at IS NULL
                  AND e.created_at >= :activity_start
                  AND e.created_at < :activity_end
                ORDER BY e.created_at DESC, e.id DESC
            ');
            $stmt->execute($params);
            $activity = array_map(fn (array $row) => [
                'project' => $row['project_name'],
                'taskId' => (int)$row['task_id'],
                'taskTitle' => $row['title'],
                'type' => $row['type'],
                'content' => $row['content'],
                'data' => json_decode($row['data'], true) ?: [],
                'createdAt' => $row['created_at'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        return [
            'date' => $today,
            'activityDate' => $activityDate,
            'stats' => $stats,
            'priorities' => $priorities,
            'unread' => array_slice($unread, 0, 8),
            'activity' => $activity,
            'projects' => $projects,
            'aiSummary' => $this->dailySummary($user['id'], $today),
        ];
    }

    public function adminAnalytics(int $days = 30, ?string $project = null, ?string $userName = null): array
    {
        $days = max(7, min(90, $days));
        $project = $project === null || trim($project) === '' ? null : $this->projectName($project);
        $userName = $userName === null || trim($userName) === '' ? null : trim($userName);
        if ($project !== null && !$this->projectExists($project)) {
            throw new HttpError(404, sprintf('Project "%s" was not found.', $project));
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        $end = new DateTimeImmutable('today', $timezone);
        $start = $end->modify('-' . ($days - 1) . ' days');
        $rangeParams = [
            ':start' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z'),
            ':end' => $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z'),
        ];
        $taskProjectCondition = '';
        $eventProjectCondition = '';
        if ($project !== null) {
            $rangeParams[':project_name'] = $project;
            $taskProjectCondition = ' AND t.project_name = :project_name';
            $eventProjectCondition = ' AND e.project_name = :project_name';
        }

        $createdStatement = $this->db->prepare('
            SELECT t.project_name, t.created_at, COALESCE(created_event.user_name, "Sistema") AS user_name
            FROM tasks t
            LEFT JOIN task_events created_event ON created_event.id = (
                SELECT e.id
                FROM task_events e
                WHERE e.project_name = t.project_name
                  AND e.task_id = t.id
                  AND e.type = "created"
                  AND e.deleted_at IS NULL
                ORDER BY e.id
                LIMIT 1
            )
            WHERE t.created_at >= :start AND t.created_at < :end' . $taskProjectCondition);
        $createdStatement->execute($rangeParams);
        $createdRows = $createdStatement->fetchAll(PDO::FETCH_ASSOC);

        $updatedStatement = $this->db->prepare('
            SELECT e.project_name, e.user_name, e.created_at, e.data, p.settings
            FROM task_events e
            JOIN projects p ON p.name = e.project_name
            WHERE e.type = "updated"
              AND e.deleted_at IS NULL
              AND e.created_at >= :start
              AND e.created_at < :end' . $eventProjectCondition);
        $updatedStatement->execute($rangeParams);
        $completedRows = [];
        foreach ($updatedStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $changes = json_decode($row['data'], true)['changes'] ?? [];
            $doneStatus = $this->normalizeSettings(json_decode($row['settings'], true))['doneStatus'];
            if (array_filter($changes, fn (array $change) => ($change['field'] ?? null) === 'status' && ($change['to'] ?? null) === $doneStatus)) {
                $completedRows[] = $row;
            }
        }

        $commentStatement = $this->db->prepare('
            SELECT e.project_name, e.user_name, e.created_at
            FROM task_events e
            WHERE e.type = "comment"
              AND e.deleted_at IS NULL
              AND e.created_at >= :start
              AND e.created_at < :end' . $eventProjectCondition);
        $commentStatement->execute($rangeParams);
        $commentRows = $commentStatement->fetchAll(PDO::FETCH_ASSOC);

        $series = [];
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $series[$key] = ['date' => $key, 'created' => 0, 'completed' => 0, 'comments' => 0];
        }
        $totals = ['created' => 0, 'completed' => 0, 'comments' => 0];
        $byProject = [];
        $byUser = [];
        foreach (['created' => $createdRows, 'completed' => $completedRows, 'comments' => $commentRows] as $metric => $rows) {
            foreach ($rows as $row) {
                if ($userName !== null && mb_strtolower($row['user_name'], 'UTF-8') !== mb_strtolower($userName, 'UTF-8')) {
                    continue;
                }
                $date = (new DateTimeImmutable($row['created_at']))->setTimezone($timezone)->format('Y-m-d');
                if (!isset($series[$date])) {
                    continue;
                }
                $series[$date][$metric]++;
                $totals[$metric]++;
                $projectName = $row['project_name'];
                $actorName = $row['user_name'];
                $byProject[$projectName] ??= ['name' => $projectName, 'created' => 0, 'completed' => 0, 'comments' => 0];
                $byUser[$actorName] ??= ['name' => $actorName, 'created' => 0, 'completed' => 0, 'comments' => 0];
                $byProject[$projectName][$metric]++;
                $byUser[$actorName][$metric]++;
            }
        }

        $projectBreakdown = array_values($byProject);
        $userBreakdown = array_values($byUser);
        $breakdowns = [&$projectBreakdown, &$userBreakdown];
        foreach ($breakdowns as &$breakdown) {
            foreach ($breakdown as &$row) {
                $row['total'] = $row['created'] + $row['completed'] + $row['comments'];
            }
            unset($row);
            usort($breakdown, fn (array $a, array $b) => $b['total'] <=> $a['total'] ?: strcasecmp($a['name'], $b['name']));
        }
        unset($breakdown, $breakdowns);

        $projects = $this->db->query('SELECT name FROM projects ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN);
        $users = [];
        foreach ($this->users() as $user) {
            $users[mb_strtolower($user['name'], 'UTF-8')] = $user['name'];
        }
        foreach ($this->db->query('SELECT DISTINCT user_name FROM task_events ORDER BY user_name COLLATE NOCASE')->fetchAll(PDO::FETCH_COLUMN) as $actorName) {
            $users[mb_strtolower($actorName, 'UTF-8')] = $actorName;
        }
        $users[mb_strtolower('Sistema', 'UTF-8')] = 'Sistema';
        $users = array_values($users);
        usort($users, fn (string $a, string $b) => strcasecmp($a, $b));

        return [
            'range' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'days' => $days],
            'scope' => ['project' => $project, 'user' => $userName],
            'totals' => $totals,
            'series' => array_values($series),
            'byProject' => $projectBreakdown,
            'byUser' => $userBreakdown,
            'filters' => ['projects' => $projects, 'users' => $users],
        ];
    }

    public function dailySummary(string $userId, ?string $date = null): ?array
    {
        $stmt = $this->db->prepare('SELECT content, generated_at, format_version FROM daily_ai_summaries WHERE user_id = :user_id AND summary_date = :summary_date');
        $stmt->execute([':user_id' => $userId, ':summary_date' => $date ?: date('Y-m-d')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['content' => $row['content'], 'generatedAt' => $row['generated_at'], 'formatVersion' => (int)$row['format_version']] : null;
    }

    public function saveDailySummary(string $userId, string $date, string $content): array
    {
        $generatedAt = $this->now();
        $this->db->prepare('
            INSERT INTO daily_ai_summaries (user_id, summary_date, content, generated_at, format_version)
            VALUES (:user_id, :summary_date, :content, :generated_at, :format_version)
            ON CONFLICT(user_id, summary_date) DO UPDATE SET content = excluded.content, generated_at = excluded.generated_at, format_version = excluded.format_version
        ')->execute([':user_id' => $userId, ':summary_date' => $date, ':content' => $content, ':generated_at' => $generatedAt, ':format_version' => self::DAILY_SUMMARY_FORMAT_VERSION]);
        return ['content' => $content, 'generatedAt' => $generatedAt, 'formatVersion' => self::DAILY_SUMMARY_FORMAT_VERSION];
    }

    public function getProject(string $name, bool $createIfMissing = false, ?string $userName = null, ?string $creatorUserId = null): array
    {
        $name = $this->projectName($name);
        $row = $this->projectRow($name);
        if (!$row && $createIfMissing) {
            $this->createProject($name, $creatorUserId);
            $row = $this->projectRow($name);
        }
        if (!$row) {
            throw new HttpError(404, sprintf('Project "%s" was not found.', $name));
        }

        return [
            'name' => $name,
            'displayName' => $row['display_name'] ?? $name,
            'settings' => $this->settingsFromRow($row),
            'nextId' => (int)$row['next_id'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'tasks' => $this->listTasks($name, $userName),
        ];
    }

    public function saveProjectSettings(string $name, ?array $settings, ?string $creatorUserId = null, ?string $displayName = null): array
    {
        $name = $this->projectName($name);
        $row = $this->projectRow($name);
        if (!$row) {
            $this->createProject($name, $creatorUserId);
        }

        $normalized = $this->normalizeSettings($settings ?? ($row ? $this->settingsFromRow($row) : self::DEFAULT_SETTINGS));
        $displayName = $displayName === null ? ($row['display_name'] ?? $name) : trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 200) {
            throw new HttpError(400, 'Display name must contain between 1 and 200 characters.');
        }
        $stmt = $this->db->prepare('UPDATE projects SET settings = :settings, display_name = :display_name, updated_at = :updated_at WHERE name = :name');
        $stmt->execute([
            ':name' => $name,
            ':settings' => $this->json($normalized),
            ':display_name' => $displayName,
            ':updated_at' => $this->now(),
        ]);

        return ['name' => $name, 'displayName' => $displayName, 'settings' => $normalized];
    }

    public function deleteProject(string $name): array
    {
        $name = $this->projectName($name);
        if (!$this->projectRow($name)) {
            throw new HttpError(404, sprintf('Project "%s" was not found.', $name));
        }

        $this->deleteProjectDocumentFts($name);
        $this->db->prepare('DELETE FROM projects WHERE name = :name')->execute([':name' => $name]);
        $attachmentDir = $this->config->attachmentsPath . DIRECTORY_SEPARATOR . $this->safeFileName($name);
        if (is_dir($attachmentDir)) {
            $this->removeDirectory($attachmentDir);
        }

        return ['deleted' => true, 'name' => $name];
    }

    public function listTasks(string $projectName, ?string $userName = null): array
    {
        $projectName = $this->projectName($projectName);
        $stmt = $this->db->prepare('SELECT * FROM tasks WHERE project_name = :project_name ORDER BY id');
        $stmt->execute([':project_name' => $projectName]);
        return array_map(fn (array $row) => $this->taskFromRow($row, $userName), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getTask(string $projectName, int $taskId, ?string $userName = null): array
    {
        $row = $this->taskRow($this->projectName($projectName), $taskId);
        if (!$row) {
            throw new HttpError(404, sprintf('Task #%d was not found in project "%s".', $taskId, $projectName));
        }

        return $this->taskFromRow($row, $userName);
    }

    public function createTask(string $projectName, array $body, string $userName = 'Sistema', ?string $userId = null): array
    {
        $projectName = $this->projectName($projectName);
        $this->db->beginTransaction();
        try {
            $project = $this->getProject($projectName, true, null, $userId);
            $now = $this->now();
            $task = [
                'id' => $project['nextId'],
                'title' => (string)($body['title'] ?? 'Untitled') ?: 'Untitled',
                'desc' => (string)($body['desc'] ?? ''),
                'due' => $body['due'] ?? null,
                'priority' => (string)($body['priority'] ?? $project['settings']['defaultPriority']),
                'tags' => $this->stringList($body['tags'] ?? []),
                'group' => $body['group'] ?? null,
                'status' => (string)($body['status'] ?? $project['settings']['defaultStatus']),
                'workingAgent' => $body['workingAgent'] ?? null,
                'assignees' => $this->taskAssignees($body['assignees'] ?? []),
                'subTasks' => $this->subTasks($body['subTasks'] ?? []),
                'attachments' => [],
                'createdAt' => $now,
                'updatedAt' => $now,
            ];

            $stmt = $this->db->prepare('
                INSERT INTO tasks (project_name, id, title, desc, due, priority, tags, group_name, status, working_agent, assignees, sub_tasks, created_at, updated_at)
                VALUES (:project_name, :id, :title, :desc, :due, :priority, :tags, :group_name, :status, :working_agent, :assignees, :sub_tasks, :created_at, :updated_at)
            ');
            $stmt->execute([
                ':project_name' => $projectName,
                ':id' => $task['id'],
                ':title' => $task['title'],
                ':desc' => $task['desc'],
                ':due' => $task['due'],
                ':priority' => $task['priority'],
                ':tags' => $this->json($task['tags']),
                ':group_name' => $task['group'],
                ':status' => $task['status'],
                ':working_agent' => $task['workingAgent'],
                ':assignees' => $this->json($task['assignees']),
                ':sub_tasks' => $this->json($task['subTasks']),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $this->db->prepare('UPDATE projects SET next_id = next_id + 1, updated_at = :updated_at WHERE name = :name')
                ->execute([':updated_at' => $now, ':name' => $projectName]);
            $this->addTaskEvent($projectName, $task['id'], 'created', $userName, 'Tarefa criada.', ['title' => $task['title']]);
            $this->db->commit();
            return $this->getTask($projectName, $task['id'], $userName);
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function updateTask(string $projectName, int $taskId, array $body, string $userName = 'Sistema'): array
    {
        $projectName = $this->projectName($projectName);
        $task = $this->getTask($projectName, $taskId);
        $before = $task;
        foreach (['title', 'desc', 'due', 'priority', 'tags', 'group', 'status', 'workingAgent', 'assignees', 'subTasks'] as $key) {
            if (array_key_exists($key, $body)) {
                $task[$key] = match ($key) {
                    'tags' => $this->stringList($body[$key]),
                    'assignees' => $this->taskAssignees($body[$key]),
                    'subTasks' => $this->subTasks($body[$key]),
                    default => $body[$key],
                };
            }
        }
        $task['updatedAt'] = $this->now();
        if (array_key_exists('archived', $body)) {
            if (!is_bool($body['archived'])) {
                throw new HttpError(400, 'archived must be a boolean.');
            }
            $task['archivedAt'] = $body['archived'] ? ($before['archivedAt'] ?? $task['updatedAt']) : null;
        }

        $this->db->prepare('
            UPDATE tasks
            SET title = :title, desc = :desc, due = :due, priority = :priority, tags = :tags, group_name = :group_name,
                status = :status, working_agent = :working_agent, assignees = :assignees, sub_tasks = :sub_tasks, archived_at = :archived_at, updated_at = :updated_at
            WHERE project_name = :project_name AND id = :id
        ')->execute([
            ':project_name' => $projectName,
            ':id' => $taskId,
            ':title' => (string)$task['title'],
            ':desc' => (string)$task['desc'],
            ':due' => $task['due'],
            ':priority' => (string)$task['priority'],
            ':tags' => $this->json(is_array($task['tags']) ? $task['tags'] : []),
            ':group_name' => $task['group'],
            ':status' => (string)$task['status'],
            ':working_agent' => $task['workingAgent'],
            ':assignees' => $this->json(is_array($task['assignees']) ? $task['assignees'] : []),
            ':sub_tasks' => $this->json($task['subTasks']),
            ':archived_at' => $task['archivedAt'],
            ':updated_at' => $task['updatedAt'],
        ]);

        $changes = $this->taskChanges($before, $task);
        if (count($changes) > 0) {
            $this->addTaskEvent($projectName, $taskId, 'updated', $userName, 'Tarefa atualizada.', ['changes' => $changes]);
        }
        if (($before['archivedAt'] === null) !== ($task['archivedAt'] === null)) {
            $archived = $task['archivedAt'] !== null;
            $this->addTaskEvent($projectName, $taskId, $archived ? 'archived' : 'unarchived', $userName, $archived ? 'Tarefa arquivada.' : 'Tarefa desarquivada.', []);
        }

        return $this->getTask($projectName, $taskId, $userName);
    }

    public function deleteTask(string $projectName, int $taskId): array
    {
        $projectName = $this->projectName($projectName);
        $this->getTask($projectName, $taskId);
        $dir = $this->attachmentDir($projectName, $taskId);
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE project_name = :project_name AND id = :id');
        $stmt->execute([':project_name' => $projectName, ':id' => $taskId]);
        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }

        return ['deleted' => true, 'id' => $taskId];
    }

    public function addUploadedAttachments(string $projectName, int $taskId, array $files, string $userName = 'Sistema'): array
    {
        $projectName = $this->projectName($projectName);
        $task = $this->getTask($projectName, $taskId);
        $uploaded = $this->uploadedFiles($files);
        if (count($uploaded) === 0) {
            throw new HttpError(400, 'At least one file is required.');
        }

        $added = [];
        foreach ($uploaded as $file) {
            $attachmentId = $this->nextAttachmentId($projectName, $taskId);
            $storedName = $attachmentId . '-' . $this->safeFileName($file['name']);
            $destination = $this->attachmentDir($projectName, $taskId) . DIRECTORY_SEPARATOR . $storedName;
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true) && !is_dir(dirname($destination))) {
                throw new HttpError(500, 'Could not create attachment directory.');
            }
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new HttpError(500, 'Could not store uploaded attachment.');
            }

            $attachment = [
                'id' => $attachmentId,
                'name' => $file['name'],
                'type' => $this->attachmentType($file['name']),
                'path' => $destination,
                'originalPath' => null,
                'size' => (int)$file['size'],
                'addedAt' => $this->now(),
            ];
            $this->db->prepare('
                INSERT INTO attachments (project_name, task_id, id, name, type, path, original_path, size, added_at)
                VALUES (:project_name, :task_id, :id, :name, :type, :path, :original_path, :size, :added_at)
            ')->execute([
                ':project_name' => $projectName,
                ':task_id' => $taskId,
                ':id' => $attachment['id'],
                ':name' => $attachment['name'],
                ':type' => $attachment['type'],
                ':path' => $attachment['path'],
                ':original_path' => $attachment['originalPath'],
                ':size' => $attachment['size'],
                ':added_at' => $attachment['addedAt'],
            ]);
            $added[] = $attachment;
        }

        $this->db->prepare('UPDATE tasks SET updated_at = :updated_at WHERE project_name = :project_name AND id = :id')
            ->execute([':updated_at' => $this->now(), ':project_name' => $projectName, ':id' => $taskId]);
        $this->addTaskEvent($projectName, $taskId, 'attachment_added', $userName, count($added) === 1 ? 'Anexo adicionado.' : 'Anexos adicionados.', [
            'attachments' => array_map(fn (array $attachment) => ['id' => $attachment['id'], 'name' => $attachment['name'], 'type' => $attachment['type']], $added),
        ]);

        return ['task' => $this->getTask($projectName, $taskId, $userName), 'attachments' => $added];
    }

    public function getAttachment(string $projectName, int $taskId, int $attachmentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM attachments WHERE project_name = :project_name AND task_id = :task_id AND id = :id');
        $stmt->execute([':project_name' => $this->projectName($projectName), ':task_id' => $taskId, ':id' => $attachmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpError(404, sprintf('Attachment #%d was not found in task #%d.', $attachmentId, $taskId));
        }

        return $this->attachmentFromRow($row);
    }

    public function removeAttachment(string $projectName, int $taskId, int $attachmentId, string $userName = 'Sistema'): array
    {
        $projectName = $this->projectName($projectName);
        $attachment = $this->getAttachment($projectName, $taskId, $attachmentId);
        $this->db->prepare('DELETE FROM attachments WHERE project_name = :project_name AND task_id = :task_id AND id = :id')
            ->execute([':project_name' => $projectName, ':task_id' => $taskId, ':id' => $attachmentId]);
        if (is_file($attachment['path'])) {
            unlink($attachment['path']);
        }

        $this->db->prepare('UPDATE tasks SET updated_at = :updated_at WHERE project_name = :project_name AND id = :id')
            ->execute([':updated_at' => $this->now(), ':project_name' => $projectName, ':id' => $taskId]);
        $this->addTaskEvent($projectName, $taskId, 'attachment_removed', $userName, 'Anexo removido.', [
            'attachment' => ['id' => $attachment['id'], 'name' => $attachment['name'], 'type' => $attachment['type']],
        ]);

        return ['task' => $this->getTask($projectName, $taskId, $userName), 'attachment' => $attachment];
    }

    public function taskConversation(string $projectName, int $taskId): array
    {
        $projectName = $this->projectName($projectName);
        $this->getTask($projectName, $taskId);
        $stmt = $this->db->prepare('
            SELECT * FROM task_events
            WHERE project_name = :project_name AND task_id = :task_id AND deleted_at IS NULL
            ORDER BY created_at, id
        ');
        $stmt->execute([':project_name' => $projectName, ':task_id' => $taskId]);
        return array_map(fn (array $row) => $this->taskEventFromRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markTaskConversationRead(string $projectName, int $taskId, string $userName): array
    {
        $projectName = $this->projectName($projectName);
        $this->getTask($projectName, $taskId);
        $stmt = $this->db->prepare('
            SELECT COALESCE(MAX(id), 0)
            FROM task_events
            WHERE project_name = :project_name AND task_id = :task_id AND type = "comment" AND deleted_at IS NULL
        ');
        $stmt->execute([':project_name' => $projectName, ':task_id' => $taskId]);
        $lastReadEventId = (int)$stmt->fetchColumn();
        $now = $this->now();
        $this->db->prepare('
            INSERT INTO task_conversation_reads (project_name, task_id, user_name, last_read_event_id, read_at)
            VALUES (:project_name, :task_id, :user_name, :last_read_event_id, :read_at)
            ON CONFLICT(project_name, task_id, user_name) DO UPDATE SET
                last_read_event_id = CASE
                    WHEN excluded.last_read_event_id > task_conversation_reads.last_read_event_id THEN excluded.last_read_event_id
                    ELSE task_conversation_reads.last_read_event_id
                END,
                read_at = excluded.read_at
        ')->execute([
            ':project_name' => $projectName,
            ':task_id' => $taskId,
            ':user_name' => trim($userName) !== '' ? trim($userName) : 'Sistema',
            ':last_read_event_id' => $lastReadEventId,
            ':read_at' => $now,
        ]);

        return [
            'read' => true,
            'lastReadEventId' => $lastReadEventId,
            'unreadCommentCount' => $this->unreadCommentCount($projectName, $taskId, $userName),
        ];
    }

    public function addTaskComment(string $projectName, int $taskId, string $userName, array $body): array
    {
        $content = trim((string)($body['content'] ?? ''));
        if ($content === '') {
            throw new HttpError(400, 'Comentário é obrigatório.');
        }
        $this->getTask($this->projectName($projectName), $taskId);
        return $this->addTaskEvent($projectName, $taskId, 'comment', $userName, $content, []);
    }

    public function updateTaskComment(string $projectName, int $taskId, int $eventId, string $userName, array $body): array
    {
        $event = $this->editableTaskComment($projectName, $taskId, $eventId, $userName);
        $content = trim((string)($body['content'] ?? ''));
        if ($content === '') {
            throw new HttpError(400, 'Comentário é obrigatório.');
        }
        $updatedAt = $this->now();
        $this->db->prepare('
            UPDATE task_events
            SET content = :content, updated_at = :updated_at
            WHERE id = :id
        ')->execute([':id' => $event['id'], ':content' => $content, ':updated_at' => $updatedAt]);
        return $this->taskEvent((int)$event['id']);
    }

    public function deleteTaskComment(string $projectName, int $taskId, int $eventId, string $userName): array
    {
        $event = $this->editableTaskComment($projectName, $taskId, $eventId, $userName);
        $this->db->prepare('UPDATE task_events SET deleted_at = :deleted_at WHERE id = :id')
            ->execute([':id' => $event['id'], ':deleted_at' => $this->now()]);
        return ['deleted' => true, 'id' => (int)$event['id']];
    }

    public function listDocuments(string $projectName, ?string $search = null): array
    {
        $projectName = $this->projectName($projectName);
        $this->getProject($projectName, true);

        if (is_string($search) && trim($search) !== '') {
            $query = $this->ftsQuery($search);
            if ($query !== '') {
                $stmt = $this->db->prepare('
                    SELECT n.*
                    FROM document_fts f
                    JOIN document_nodes n ON n.id = f.document_id
                    WHERE f.project_name = :project_name AND document_fts MATCH :query
                    ORDER BY rank, n.type = "file", n.name COLLATE NOCASE
                ');
                $stmt->execute([':project_name' => $projectName, ':query' => $query]);
                return array_map(fn (array $row) => $this->documentFromRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        $stmt = $this->db->prepare('
            SELECT *
            FROM document_nodes
            WHERE project_name = :project_name
            ORDER BY COALESCE(parent_id, 0), type = "file", name COLLATE NOCASE
        ');
        $stmt->execute([':project_name' => $projectName]);
        return array_map(fn (array $row) => $this->documentFromRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getDocument(string $projectName, int $id): array
    {
        $row = $this->documentRow($this->projectName($projectName), $id);
        if (!$row) {
            throw new HttpError(404, sprintf('Document #%d was not found in project "%s".', $id, $projectName));
        }

        return $this->documentFromRow($row);
    }

    public function getPublicDocument(string $token): array
    {
        $stmt = $this->db->prepare('SELECT * FROM document_nodes WHERE public_token = :token AND type = "file"');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpError(404, 'Public document was not found.');
        }

        return $this->documentFromRow($row);
    }

    public function createDocument(string $projectName, array $body): array
    {
        $projectName = $this->projectName($projectName);
        $this->getProject($projectName, true);
        $type = $this->documentType($body['type'] ?? 'file');
        $name = $this->documentName($body['name'] ?? ($type === 'folder' ? 'Nova pasta' : 'novo-documento.md'));
        $parentId = $this->documentParentId($projectName, $body['parentId'] ?? null);
        $now = $this->now();

        $stmt = $this->db->prepare('
            INSERT INTO document_nodes (project_name, parent_id, type, name, content, public_token, created_at, updated_at)
            VALUES (:project_name, :parent_id, :type, :name, :content, :public_token, :created_at, :updated_at)
        ');
        $stmt->execute([
            ':project_name' => $projectName,
            ':parent_id' => $parentId,
            ':type' => $type,
            ':name' => $name,
            ':content' => $type === 'file' ? (string)($body['content'] ?? '') : '',
            ':public_token' => bin2hex(random_bytes(16)),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $document = $this->getDocument($projectName, (int)$this->db->lastInsertId());
        $this->syncDocumentFts($document);
        return $document;
    }

    public function updateDocument(string $projectName, int $id, array $body): array
    {
        $projectName = $this->projectName($projectName);
        $document = $this->getDocument($projectName, $id);
        $name = array_key_exists('name', $body) ? $this->documentName($body['name']) : $document['name'];
        $parentId = array_key_exists('parentId', $body) ? $this->documentParentId($projectName, $body['parentId'], $id) : $document['parentId'];
        $content = $document['type'] === 'file' && array_key_exists('content', $body) ? (string)$body['content'] : $document['content'];

        $this->db->prepare('
            UPDATE document_nodes
            SET parent_id = :parent_id, name = :name, content = :content, updated_at = :updated_at
            WHERE project_name = :project_name AND id = :id
        ')->execute([
            ':project_name' => $projectName,
            ':id' => $id,
            ':parent_id' => $parentId,
            ':name' => $name,
            ':content' => $content,
            ':updated_at' => $this->now(),
        ]);

        $updated = $this->getDocument($projectName, $id);
        $this->syncDocumentFts($updated);
        return $updated;
    }

    public function deleteDocument(string $projectName, int $id): array
    {
        $projectName = $this->projectName($projectName);
        $document = $this->getDocument($projectName, $id);
        foreach ($this->descendantDocumentIds($id) as $descendantId) {
            $this->deleteDocumentFts($descendantId);
        }
        $this->deleteDocumentFts($id);
        $this->db->prepare('DELETE FROM document_nodes WHERE project_name = :project_name AND id = :id')
            ->execute([':project_name' => $projectName, ':id' => $id]);

        return ['deleted' => true, 'id' => $id, 'type' => $document['type']];
    }

    public function uploadDocuments(string $projectName, mixed $parentId, array $files, mixed $paths): array
    {
        $projectName = $this->projectName($projectName);
        $this->getProject($projectName, true);
        $parentId = $this->documentParentId($projectName, $parentId);
        $uploaded = $this->uploadedFiles($files);
        $paths = is_array($paths) ? array_values($paths) : [];
        $created = [];

        foreach ($uploaded as $index => $file) {
            $relativePath = is_string($paths[$index] ?? null) && trim($paths[$index]) !== '' ? $paths[$index] : $file['name'];
            $segments = array_values(array_filter(array_map(fn (string $part) => trim($part), preg_split('#[\\\\/]#', $relativePath) ?: []), fn (string $part) => $part !== '' && $part !== '.' && $part !== '..'));
            if (count($segments) === 0) {
                $segments = [$file['name']];
            }

            $targetParentId = $parentId;
            foreach (array_slice($segments, 0, -1) as $folderName) {
                $targetParentId = $this->findOrCreateDocumentFolder($projectName, $targetParentId, $this->documentName($folderName));
            }

            $content = file_get_contents($file['tmp_name']);
            if ($content === false) {
                throw new HttpError(400, sprintf('Could not read uploaded file "%s".', $file['name']));
            }
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            }

            $created[] = $this->createDocument($projectName, [
                'type' => 'file',
                'parentId' => $targetParentId,
                'name' => $this->documentName($segments[count($segments) - 1]),
                'content' => $content,
            ]);
        }

        return ['uploaded' => count($created), 'documents' => $created];
    }

    public function documentExport(string $projectName, int $id): array
    {
        $projectName = $this->projectName($projectName);
        $document = $this->getDocument($projectName, $id);
        if ($document['type'] === 'file') {
            return ['type' => 'file', 'name' => $document['name'], 'content' => $document['content']];
        }

        $rows = $this->documentSubtreeRows($projectName, $id);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $this->documentFromRow($row);
        }

        $files = [];
        foreach ($byId as $node) {
            if ($node['type'] !== 'file') {
                continue;
            }
            $files[] = [
                'path' => $this->documentExportPath($node, $byId, $id),
                'content' => $node['content'],
            ];
        }

        return ['type' => 'folder', 'name' => $document['name'] . '.zip', 'files' => $files];
    }

    public function documentsRootExport(string $projectName): array
    {
        $projectName = $this->projectName($projectName);
        $this->getProject($projectName, true);

        $stmt = $this->db->prepare('SELECT * FROM document_nodes WHERE project_name = :project_name');
        $stmt->execute([':project_name' => $projectName]);

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['id']] = $this->documentFromRow($row);
        }

        $files = [];
        foreach ($byId as $node) {
            if ($node['type'] !== 'file') {
                continue;
            }
            $files[] = [
                'path' => $this->documentExportPath($node, $byId, 0),
                'content' => $node['content'],
            ];
        }

        return ['type' => 'folder', 'name' => $projectName . '-documents.zip', 'files' => $files];
    }

    public function documentsSelectionExport(string $projectName, array $ids): array
    {
        $projectName = $this->projectName($projectName);
        $this->getProject($projectName, true);
        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
        if (count($ids) === 0) {
            throw new HttpError(400, 'At least one document ID is required.');
        }

        $stmt = $this->db->prepare('SELECT * FROM document_nodes WHERE project_name = :project_name');
        $stmt->execute([':project_name' => $projectName]);

        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['id']] = $this->documentFromRow($row);
        }

        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                throw new HttpError(404, sprintf('Document #%d was not found in project "%s".', $id, $projectName));
            }
        }

        $selected = array_fill_keys($ids, true);
        $topLevelIds = array_values(array_filter($ids, function (int $id) use ($byId, $selected): bool {
            $parentId = $byId[$id]['parentId'];
            while ($parentId !== null) {
                if (isset($selected[$parentId])) {
                    return false;
                }
                $parentId = $byId[$parentId]['parentId'] ?? null;
            }
            return true;
        }));

        $files = [];
        foreach ($topLevelIds as $id) {
            $node = $byId[$id];
            if ($node['type'] === 'file') {
                $files[] = [
                    'path' => $this->documentExportPath($node, $byId, 0),
                    'content' => $node['content'],
                ];
                continue;
            }

            foreach ($byId as $child) {
                if ($child['type'] !== 'file') {
                    continue;
                }
                $parentId = $child['parentId'];
                while ($parentId !== null) {
                    if ($parentId === $id) {
                        $files[] = [
                            'path' => $this->documentExportPath($child, $byId, 0),
                            'content' => $child['content'],
                        ];
                        break;
                    }
                    $parentId = $byId[$parentId]['parentId'] ?? null;
                }
            }
        }

        return ['type' => 'folder', 'name' => $projectName . '-selection.zip', 'files' => $files];
    }

    private function projectSummary(array $row): array
    {
        $name = $row['name'];
        $settings = $this->settingsFromRow($row);
        $tasks = array_values(array_filter($this->listTasks($name), fn (array $task) => $task['archivedAt'] === null));
        $statusCounts = array_fill_keys($settings['statuses'], 0);
        $priorityCounts = array_fill_keys($settings['priorities'], 0);
        $attachmentsCount = 0;
        foreach ($tasks as $task) {
            $statusCounts[$task['status']] = ($statusCounts[$task['status']] ?? 0) + 1;
            $priorityCounts[$task['priority']] = ($priorityCounts[$task['priority']] ?? 0) + 1;
            $attachmentsCount += count($task['attachments']);
        }

        $completed = $statusCounts[$settings['doneStatus']] ?? 0;
        return [
            'name' => $name,
            'displayName' => $row['display_name'] ?? $name,
            'localPath' => $this->config->databasePath,
            'tasksCount' => count($tasks),
            'doneStatus' => $settings['doneStatus'],
            'pendingTasksCount' => count($tasks) - $completed,
            'completedTasksCount' => $completed,
            'statusCounts' => $statusCounts,
            'priorityCounts' => $priorityCounts,
            'attachmentsCount' => $attachmentsCount,
            'archivedAt' => $row['archived_at'],
        ];
    }

    private function createProject(string $name, ?string $creatorUserId = null): void
    {
        $now = $this->now();
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO projects (name, settings, next_id, created_at, updated_at) VALUES (:name, :settings, 1, :created_at, :updated_at)');
        $stmt->execute([
            ':name' => $name,
            ':settings' => $this->json(self::DEFAULT_SETTINGS),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $memberIds = $creatorUserId === null ? [] : [$creatorUserId => true];
        foreach ($this->users() as $user) {
            if ($user['isAdmin']) {
                $memberIds[$user['id']] = true;
            }
        }
        $insertMember = $this->db->prepare('INSERT OR IGNORE INTO project_members (project_name, user_id, added_at) VALUES (:project_name, :user_id, :added_at)');
        foreach (array_keys($memberIds) as $userId) {
            $insertMember->execute([':project_name' => $name, ':user_id' => $userId, ':added_at' => $now]);
        }
    }

    private function projectRow(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function taskRow(string $projectName, int $taskId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tasks WHERE project_name = :project_name AND id = :id');
        $stmt->execute([':project_name' => $projectName, ':id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function taskFromRow(array $row, ?string $userName = null): array
    {
        $creatorStmt = $this->db->prepare('
            SELECT user_name
            FROM task_events
            WHERE project_name = :project_name AND task_id = :task_id AND type = "created"
            ORDER BY created_at, id
            LIMIT 1
        ');
        $creatorStmt->execute([':project_name' => $row['project_name'], ':task_id' => (int)$row['id']]);
        $createdBy = $creatorStmt->fetchColumn();

        return [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'desc' => $row['desc'],
            'due' => $row['due'],
            'priority' => $row['priority'],
            'tags' => json_decode($row['tags'], true) ?: [],
            'group' => $row['group_name'],
            'status' => $row['status'],
            'workingAgent' => $row['working_agent'],
            'assignees' => json_decode($row['assignees'] ?? '[]', true) ?: [],
            'subTasks' => json_decode($row['sub_tasks'], true) ?: [],
            'attachments' => $this->taskAttachments($row['project_name'], (int)$row['id']),
            'unreadCommentCount' => $userName === null ? 0 : $this->unreadCommentCount($row['project_name'], (int)$row['id'], $userName),
            'createdBy' => $createdBy !== false ? $createdBy : null,
            'archivedAt' => $row['archived_at'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function addTaskEvent(string $projectName, int $taskId, string $type, string $userName, string $content, array $data): array
    {
        $now = $this->now();
        $this->db->prepare('
            INSERT INTO task_events (project_name, task_id, type, user_name, content, data, created_at, updated_at)
            VALUES (:project_name, :task_id, :type, :user_name, :content, :data, :created_at, :updated_at)
        ')->execute([
            ':project_name' => $this->projectName($projectName),
            ':task_id' => $taskId,
            ':type' => $type,
            ':user_name' => trim($userName) !== '' ? trim($userName) : 'Sistema',
            ':content' => $content,
            ':data' => $this->json($data),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $this->taskEvent((int)$this->db->lastInsertId());
    }

    private function taskEvent(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM task_events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new HttpError(404, 'Evento da conversa não encontrado.');
        }
        return $this->taskEventFromRow($row);
    }

    private function taskEventFromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'taskId' => (int)$row['task_id'],
            'type' => $row['type'],
            'userName' => $row['user_name'],
            'content' => $row['content'],
            'data' => json_decode($row['data'], true) ?: [],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'deletedAt' => $row['deleted_at'],
        ];
    }

    private function unreadCommentCount(string $projectName, int $taskId, string $userName): int
    {
        $userName = trim($userName) !== '' ? trim($userName) : 'Sistema';
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM task_events e
            LEFT JOIN task_conversation_reads r
                ON r.project_name = e.project_name
               AND r.task_id = e.task_id
               AND r.user_name = :user_name
            WHERE e.project_name = :project_name
              AND e.task_id = :task_id
              AND e.type = "comment"
              AND e.deleted_at IS NULL
              AND e.user_name <> :user_name
              AND e.id > COALESCE(r.last_read_event_id, 0)
        ');
        $stmt->execute([':project_name' => $projectName, ':task_id' => $taskId, ':user_name' => $userName]);
        return (int)$stmt->fetchColumn();
    }

    private function editableTaskComment(string $projectName, int $taskId, int $eventId, string $userName): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM task_events
            WHERE id = :id AND project_name = :project_name AND task_id = :task_id AND deleted_at IS NULL
        ');
        $stmt->execute([':id' => $eventId, ':project_name' => $this->projectName($projectName), ':task_id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['type'] !== 'comment') {
            throw new HttpError(404, 'Comentário não encontrado.');
        }
        if (!hash_equals($row['user_name'], $userName)) {
            throw new HttpError(403, 'Você só pode alterar comentários próprios.');
        }
        return $this->taskEventFromRow($row);
    }

    private function taskChanges(array $before, array $after): array
    {
        $labels = [
            'title' => 'Título',
            'desc' => 'Descrição',
            'due' => 'Prazo',
            'priority' => 'Prioridade',
            'tags' => 'Tags',
            'group' => 'Grupo',
            'status' => 'Status',
            'workingAgent' => 'Agente',
            'assignees' => 'Responsáveis',
        ];
        $changes = [];
        foreach ($labels as $field => $label) {
            $old = in_array($field, ['tags', 'assignees'], true) ? implode(', ', $before[$field] ?? []) : ($before[$field] ?? null);
            $new = in_array($field, ['tags', 'assignees'], true) ? implode(', ', $after[$field] ?? []) : ($after[$field] ?? null);
            if ($old !== $new) {
                $changes[] = ['field' => $field, 'label' => $label, 'from' => $old, 'to' => $new];
            }
        }
        if (($before['subTasks'] ?? []) !== ($after['subTasks'] ?? [])) {
            $changes[] = ['field' => 'subTasks', 'label' => 'Sub-tasks', 'from' => $this->json($before['subTasks'] ?? []), 'to' => $this->json($after['subTasks'] ?? [])];
        }
        return $changes;
    }

    private function taskAttachments(string $projectName, int $taskId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM attachments WHERE project_name = :project_name AND task_id = :task_id ORDER BY id');
        $stmt->execute([':project_name' => $projectName, ':task_id' => $taskId]);
        return array_map(fn (array $row) => $this->attachmentFromRow($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function attachmentFromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'path' => $row['path'],
            'originalPath' => $row['original_path'],
            'size' => (int)$row['size'],
            'addedAt' => $row['added_at'],
        ];
    }

    private function documentFromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'project' => $row['project_name'],
            'parentId' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
            'type' => $row['type'],
            'name' => $row['name'],
            'content' => $row['content'],
            'publicToken' => $row['public_token'],
            'publicUrl' => '/public/documents/' . rawurlencode($row['public_token']),
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function documentRow(string $projectName, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM document_nodes WHERE project_name = :project_name AND id = :id');
        $stmt->execute([':project_name' => $projectName, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function documentSubtreeRows(string $projectName, int $id): array
    {
        $stmt = $this->db->prepare('
            WITH RECURSIVE nodes AS (
                SELECT * FROM document_nodes WHERE project_name = :project_name AND id = :id
                UNION ALL
                SELECT child.* FROM document_nodes child JOIN nodes parent ON child.parent_id = parent.id
            )
            SELECT * FROM nodes
        ');
        $stmt->execute([':project_name' => $projectName, ':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findOrCreateDocumentFolder(string $projectName, ?int $parentId, string $name): int
    {
        $stmt = $this->db->prepare('
            SELECT id FROM document_nodes
            WHERE project_name = :project_name AND type = "folder" AND name = :name
              AND ((parent_id IS NULL AND :parent_id IS NULL) OR parent_id = :parent_id)
            LIMIT 1
        ');
        $stmt->execute([':project_name' => $projectName, ':parent_id' => $parentId, ':name' => $name]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            return (int)$existingId;
        }

        return $this->createDocument($projectName, ['type' => 'folder', 'parentId' => $parentId, 'name' => $name])['id'];
    }

    private function documentExportPath(array $node, array $byId, int $rootId): string
    {
        $parts = [$this->safeFileName($node['name'])];
        $parentId = $node['parentId'];
        while ($parentId !== null && isset($byId[$parentId])) {
            array_unshift($parts, $this->safeFileName($byId[$parentId]['name']));
            if ($parentId === $rootId) {
                break;
            }
            $parentId = $byId[$parentId]['parentId'];
        }

        return implode('/', $parts);
    }

    private function syncDocumentFts(array $document): void
    {
        $this->deleteDocumentFts($document['id']);
        if ($document['type'] !== 'file') {
            return;
        }

        $this->db->prepare('INSERT INTO document_fts (title, content, project_name, document_id) VALUES (:title, :content, :project_name, :document_id)')
            ->execute([
                ':title' => $document['name'],
                ':content' => $document['content'],
                ':project_name' => $document['project'],
                ':document_id' => $document['id'],
            ]);
    }

    private function deleteDocumentFts(int $id): void
    {
        $this->db->prepare('DELETE FROM document_fts WHERE document_id = :document_id')->execute([':document_id' => $id]);
    }

    private function deleteProjectDocumentFts(string $projectName): void
    {
        $this->db->prepare('DELETE FROM document_fts WHERE project_name = :project_name')->execute([':project_name' => $projectName]);
    }

    private function descendantDocumentIds(int $id): array
    {
        $stmt = $this->db->prepare('
            WITH RECURSIVE children(id) AS (
                SELECT id FROM document_nodes WHERE parent_id = :id
                UNION ALL
                SELECT n.id FROM document_nodes n JOIN children c ON n.parent_id = c.id
            )
            SELECT id FROM children
        ');
        $stmt->execute([':id' => $id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function settingsFromRow(array $row): array
    {
        return $this->normalizeSettings(json_decode($row['settings'], true));
    }

    private function normalizeSettings(mixed $settings): array
    {
        if (!is_array($settings)) {
            return self::DEFAULT_SETTINGS;
        }

        $statuses = $this->normalizeOptionList($settings['statuses'] ?? null, self::DEFAULT_SETTINGS['statuses']);
        $priorities = $this->normalizeOptionList($settings['priorities'] ?? null, self::DEFAULT_SETTINGS['priorities']);
        $defaultStatus = in_array($settings['defaultStatus'] ?? null, $statuses, true) ? $settings['defaultStatus'] : $statuses[0];
        $defaultPriority = in_array($settings['defaultPriority'] ?? null, $priorities, true) ? $settings['defaultPriority'] : $priorities[0];
        $doneStatus = in_array($settings['doneStatus'] ?? null, $statuses, true)
            ? $settings['doneStatus']
            : (in_array(self::DEFAULT_SETTINGS['doneStatus'], $statuses, true) ? self::DEFAULT_SETTINGS['doneStatus'] : $statuses[count($statuses) - 1]);

        return compact('statuses', 'priorities', 'defaultStatus', 'defaultPriority', 'doneStatus');
    }

    private function normalizeOptionList(mixed $values, array $fallback): array
    {
        if (!is_array($values)) {
            return $fallback;
        }

        $items = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '' && !in_array($value, $items, true)) {
                    $items[] = $value;
                }
            }
        }

        return count($items) > 0 ? $items : $fallback;
    }

    private function ftsQuery(string $search): string
    {
        preg_match_all('/[\p{L}\p{N}_-]+/u', mb_strtolower($search), $matches);
        return implode(' ', array_map(fn (string $term) => '"' . str_replace('"', '""', $term) . '"*', $matches[0]));
    }

    private function subTasks(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > 500) {
            throw new HttpError(400, 'subTasks must be a list of up to 500 items.');
        }
        $result = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || !is_string($item['title'] ?? null) || trim($item['title']) === ''
                || !is_string($item['description'] ?? '') || !is_int($item['order'] ?? $index)
                || ($item['order'] ?? $index) < 0 || !is_bool($item['completed'] ?? false)) {
                throw new HttpError(400, 'Invalid sub-task: title, description, non-negative integer order and boolean completed expected.');
            }
            $result[] = ['title' => trim($item['title']), 'description' => $item['description'] ?? '', 'order' => $item['order'] ?? $index, 'completed' => $item['completed'] ?? false];
        }
        usort($result, fn (array $a, array $b) => $a['order'] <=> $b['order']);
        return $result;
    }

    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }

    private function taskAssignees(mixed $values): array
    {
        $knownUsers = [];
        foreach ($this->users() as $user) {
            $knownUsers[mb_strtolower($user['name'], 'UTF-8')] = $user['name'];
        }

        $assignees = [];
        foreach ($this->stringList($values) as $value) {
            $key = mb_strtolower(trim($value), 'UTF-8');
            if ($key !== '' && isset($knownUsers[$key]) && !in_array($knownUsers[$key], $assignees, true)) {
                $assignees[] = $knownUsers[$key];
            }
        }
        return $assignees;
    }

    private function projectName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new HttpError(400, 'Project name must be a simple name without path separators.');
        }

        return $name;
    }

    private function documentType(mixed $type): string
    {
        if (!is_string($type) || !in_array($type, self::DOCUMENT_TYPES, true)) {
            throw new HttpError(400, 'Document type must be "folder" or "file".');
        }

        return $type;
    }

    private function documentName(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '') {
            throw new HttpError(400, 'Document name is required.');
        }

        $name = trim($name);
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new HttpError(400, 'Document name must not contain path separators.');
        }

        return $name;
    }

    private function documentParentId(string $projectName, mixed $parentId, ?int $currentId = null): ?int
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        if (is_string($parentId) && preg_match('/^[1-9]\d*$/', $parentId)) {
            $parentId = (int)$parentId;
        }

        if (!is_int($parentId) || $parentId < 1) {
            throw new HttpError(400, 'Parent folder ID must be a positive integer.');
        }

        if ($currentId !== null && $parentId === $currentId) {
            throw new HttpError(400, 'A document cannot be its own parent.');
        }

        $parent = $this->getDocument($projectName, $parentId);
        if ($parent['type'] !== 'folder') {
            throw new HttpError(400, 'Parent document must be a folder.');
        }

        if ($currentId !== null && in_array($parentId, $this->descendantDocumentIds($currentId), true)) {
            throw new HttpError(400, 'A folder cannot be moved inside one of its children.');
        }

        return $parentId;
    }

    private function uploadedFiles(array $files): array
    {
        $items = [];
        foreach ($files as $file) {
            if (is_array($file['name'] ?? null)) {
                foreach ($file['name'] as $index => $name) {
                    if (($file['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $items[] = [
                            'name' => basename((string)$name),
                            'tmp_name' => $file['tmp_name'][$index],
                            'size' => $file['size'][$index],
                        ];
                    }
                }
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $items[] = [
                    'name' => basename((string)$file['name']),
                    'tmp_name' => $file['tmp_name'],
                    'size' => $file['size'],
                ];
            }
        }

        return $items;
    }

    private function nextAttachmentId(string $projectName, int $taskId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(id), 0) + 1 FROM attachments WHERE project_name = :project_name AND task_id = :task_id');
        $stmt->execute([':project_name' => $projectName, ':task_id' => $taskId]);
        return (int)$stmt->fetchColumn();
    }

    private function attachmentDir(string $projectName, int $taskId): string
    {
        return $this->config->attachmentsPath . DIRECTORY_SEPARATOR . $this->safeFileName($projectName) . DIRECTORY_SEPARATOR . $taskId;
    }

    private function attachmentType(string $fileName): string
    {
        return in_array(strtolower(strrchr($fileName, '.') ?: ''), self::IMAGE_EXTENSIONS, true) ? 'image' : 'file';
    }

    private function safeFileName(string $fileName): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'file';
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function userFromRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'isAdmin' => (bool)$row['is_admin'],
            'pin' => $row['pin'],
            'mcpKey' => $row['mcp_key'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
