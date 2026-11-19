<?php

namespace ManyContext;

final class McpController
{
    private ?array $user = null;

    public function __construct(
        private readonly Repository $repository,
        private readonly AivaxClient $aivax
    ) {
    }

    public function handle(string $method): void
    {
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version, X-MCP-Token');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            http_response_code(204);
            return;
        }

        if ($method !== 'POST') {
            $this->jsonRpc(null, null, ['code' => -32600, 'message' => 'MCP endpoint expects POST requests.'], 405);
            return;
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        if (!is_array($payload)) {
            $this->jsonRpc(null, null, ['code' => -32700, 'message' => 'Parse error.'], 400);
            return;
        }

        if (!$this->authorized()) {
            $id = $payload['id'] ?? null;
            $this->jsonRpc($id, null, ['code' => -32001, 'message' => 'Unauthorized.'], 401);
            return;
        }

        $isBatch = array_is_list($payload);
        $requests = $isBatch ? $payload : [$payload];
        $responses = [];
        foreach ($requests as $request) {
            $response = $this->responseFor(is_array($request) ? $request : []);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if (count($responses) === 0) {
            http_response_code(202);
            return;
        }

        Http::json(200, $isBatch ? $responses : $responses[0]);
    }

    private function responseFor(array $request): ?array
    {
        $id = $request['id'] ?? null;
        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null)) {
            return $this->message($id, null, ['code' => -32600, 'message' => 'Invalid Request.']);
        }

        if (!array_key_exists('id', $request)) {
            return null;
        }

        try {
            return match ($request['method']) {
                'server/discover' => $this->message($id, $this->discover()),
                'initialize' => $this->message($id, $this->initialize()),
                'tools/list' => $this->message($id, ['tools' => $this->tools()]),
                'tools/call' => $this->message($id, $this->callTool($request['params'] ?? [])),
                default => $this->message($id, null, ['code' => -32601, 'message' => 'Method not found.']),
            };
        } catch (HttpError $error) {
            return $this->message($id, null, ['code' => -32000, 'message' => $error->getMessage()]);
        } catch (\Throwable $error) {
            return $this->message($id, null, ['code' => -32603, 'message' => $error->getMessage()]);
        }
    }

    private function discover(): array
    {
        return [
            'supportedVersions' => ['2025-06-18'],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'manycontext-php', 'version' => '0.1.0'],
            'instructions' => 'Stateless MCP endpoint for managing manycontext projects, tasks, task conversations, attachments, and documents stored in SQLite.',
        ];
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'manycontext-php', 'version' => '0.1.0'],
            'instructions' => 'Use tools/list and tools/call. No session state is retained by the server.',
        ];
    }

    private function tools(): array
    {
        return [
            $this->tool('manycontext_list_projects', 'List all projects with task and attachment counts.', []),
            $this->tool('manycontext_get_project', 'Get one project with settings and tasks.', [
                'project' => ['type' => 'string'],
            ], ['project']),
            $this->tool('manycontext_create_project', 'Create a project. "project" is the permanent ID used in URLs and tools; displayName is the editable label (use "Group/Project" to group it). Omitted settings use defaults. The caller becomes a member.', [
                'project' => ['type' => 'string'],
                'displayName' => ['type' => 'string'],
                'settings' => $this->settingsSchema(),
            ], ['project']),
            $this->tool('manycontext_update_project', 'Update a project displayName and/or settings. Omitted settings keys keep their current values. A status that still has tasks cannot be removed; move those tasks first.', [
                'project' => ['type' => 'string'],
                'displayName' => ['type' => 'string'],
                'settings' => $this->settingsSchema(),
            ], ['project']),
            $this->tool('manycontext_delete_project', 'Permanently delete a project with all its tasks, attachments and documents. Admin only. To keep the data, ask an admin to archive it instead.', [
                'project' => ['type' => 'string'],
            ], ['project']),
            $this->tool('manycontext_list_tasks', 'List tasks from one project, optionally filtered. Free-text search uses semantic reranking. Archived tasks are only returned when archived is true.', [
                'project' => ['type' => 'string'],
                'search' => ['type' => 'string'],
                'archived' => ['type' => 'boolean'],
                'status' => ['type' => 'string'],
                'priority' => ['type' => 'string'],
                'group' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
                'dueBefore' => ['type' => 'string'],
                'dueAfter' => ['type' => 'string'],
            ], ['project']),
            $this->tool('manycontext_get_task', 'Get one complete task with metadata, tags, group, conversation, unread count, and attachment download URLs.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'markConversationRead' => ['type' => 'boolean'],
            ], ['project', 'id']),
            $this->tool('manycontext_create_task', 'Create a task in a project.', [
                'project' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'desc' => ['type' => 'string'],
                'due' => ['type' => ['string', 'null']],
                'priority' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'group' => ['type' => ['string', 'null']],
                'status' => ['type' => 'string'],
                'workingAgent' => ['type' => ['string', 'null']],
                'assignees' => ['type' => 'array', 'items' => ['type' => 'string']],
                'subTasks' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1],
                    'description' => ['type' => 'string'],
                    'order' => ['type' => 'integer', 'minimum' => 0],
                    'completed' => ['type' => 'boolean'],
                ], 'required' => ['title']]],
            ], ['project']),
            $this->tool('manycontext_update_task', 'Update fields on an existing task. Set archived to true to archive it or false to restore it.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => 'string'],
                'desc' => ['type' => 'string'],
                'due' => ['type' => ['string', 'null']],
                'priority' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'group' => ['type' => ['string', 'null']],
                'status' => ['type' => 'string'],
                'workingAgent' => ['type' => ['string', 'null']],
                'assignees' => ['type' => 'array', 'items' => ['type' => 'string']],
                'subTasks' => ['type' => 'array', 'maxItems' => 500, 'items' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1],
                    'description' => ['type' => 'string'],
                    'order' => ['type' => 'integer', 'minimum' => 0],
                    'completed' => ['type' => 'boolean'],
                ], 'required' => ['title']]],
                'archived' => ['type' => 'boolean'],
            ], ['project', 'id']),
            $this->tool('manycontext_write_task_comment', 'Add a task conversation comment, or update an existing comment when commentId is provided. Returns the changed comment and complete task.', [
                'project' => ['type' => 'string'],
                'taskId' => ['type' => 'integer', 'minimum' => 1],
                'commentId' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'content' => ['type' => 'string'],
                'markConversationRead' => ['type' => 'boolean'],
            ], ['project', 'taskId', 'content']),
            $this->tool('manycontext_delete_task', 'Permanently delete a task and its attachments. To archive instead, use manycontext_update_task with archived: true.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['project', 'id']),
            $this->tool('manycontext_list_documents', 'List document nodes in the project root or in a folder. Supports FTS search and optional recursive listing.', [
                'project' => ['type' => 'string'],
                'parentId' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'search' => ['type' => ['string', 'null']],
                'recursive' => ['type' => 'boolean'],
            ], ['project']),
            $this->tool('manycontext_read_document', 'Read one document node. Text and Markdown files include content; other file types include download links.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['project', 'id']),
            $this->tool('manycontext_write_document_file', 'Create a file when id is omitted, or update an existing file when id is provided.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'parentId' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'name' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ], ['project']),
            $this->tool('manycontext_delete_document_file', 'Delete one document file.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['project', 'id']),
            $this->tool('manycontext_write_document_folder', 'Create a folder when id is omitted, or update an existing folder when id is provided.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'parentId' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'name' => ['type' => 'string'],
            ], ['project']),
            $this->tool('manycontext_delete_document_folder', 'Delete one document folder and its descendants.', [
                'project' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['project', 'id']),
        ];
    }

    private function callTool(mixed $params): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null)) {
            throw new HttpError(400, 'Tool name is required.');
        }

        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $toolName = $params['name'];
        $user = $this->user ?? throw new HttpError(401, 'Unauthorized.');
        if (!in_array($toolName, [
            'manycontext_list_projects',
        ], true)) {
            $project = $this->requiredString($args, 'project');
            $createsProject = in_array($toolName, ['manycontext_create_task', 'manycontext_create_project'], true) && !$this->repository->projectExists($project);
            if (!$createsProject) {
                $this->repository->requireProjectAccess($project, $user['id'], $user['isAdmin']);
            }
        }
        $data = match ($toolName) {
            'manycontext_list_projects' => $this->repository->projectSummaries($user['id'], $user['isAdmin']),
            'manycontext_get_project' => $this->repository->getProject($this->requiredString($args, 'project'), false, $this->actor($args)),
            'manycontext_create_project' => $this->createProject($args),
            'manycontext_update_project' => $this->updateProject($args),
            'manycontext_delete_project' => $this->deleteProject($args),
            'manycontext_list_tasks' => $this->filteredTasks($args),
            'manycontext_get_task' => $this->completeTask($this->requiredString($args, 'project'), $this->requiredId($args, 'id'), $this->actor($args), $this->boolArg($args, 'markConversationRead')),
            'manycontext_create_task' => $this->createTask($args),
            'manycontext_update_task' => $this->updateTask($args),
            'manycontext_write_task_comment' => $this->writeTaskComment($args),
            'manycontext_delete_task' => $this->repository->deleteTask($this->requiredString($args, 'project'), $this->requiredId($args, 'id')),
            'manycontext_list_documents' => $this->listDocuments($args),
            'manycontext_read_document' => $this->readDocument($args),
            'manycontext_write_document_file' => $this->writeDocumentFile($args),
            'manycontext_delete_document_file' => $this->deleteDocument($args, 'file'),
            'manycontext_write_document_folder' => $this->writeDocumentFolder($args),
            'manycontext_delete_document_folder' => $this->deleteDocument($args, 'folder'),
            default => throw new HttpError(404, sprintf('Tool "%s" was not found.', $toolName)),
        };
        $structuredContent = match ($toolName) {
            'manycontext_list_projects' => ['count' => count($data), 'projects' => $data],
            'manycontext_list_tasks' => ['count' => count($data), 'tasks' => $data],
            default => $data,
        };

        return [
            'content' => [['type' => 'text', 'text' => json_encode($structuredContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $structuredContent,
        ];
    }

    private function createProject(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        if ($this->repository->projectExists($project)) {
            throw new HttpError(409, sprintf('Project "%s" already exists.', $project));
        }

        return $this->repository->saveProjectSettings($project, $this->settingsArg($args), $this->user['id'], $this->optionalString($args, 'displayName'));
    }

    private function updateProject(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $settings = $this->settingsArg($args);
        $displayName = $this->optionalString($args, 'displayName');
        if ($settings === null && $displayName === null) {
            throw new HttpError(400, 'Provide displayName and/or settings.');
        }

        $current = $this->repository->getProject($project);
        if ($settings !== null) {
            $settings = array_merge($current['settings'], $settings);
            if (!is_array($settings['statuses'])) {
                throw new HttpError(400, 'settings.statuses must be a list of strings.');
            }

            $statuses = array_map(fn (mixed $status) => is_string($status) ? trim($status) : $status, $settings['statuses']);
            $removed = array_diff($current['settings']['statuses'], $statuses);
            $orphaned = array_values(array_intersect($removed, array_unique(array_column($current['tasks'], 'status'))));
            if (count($orphaned) > 0) {
                throw new HttpError(409, sprintf('Cannot remove statuses that still have tasks: %s. Move those tasks to another status first.', implode(', ', $orphaned)));
            }
        }

        return $this->repository->saveProjectSettings($project, $settings, $this->user['id'], $displayName);
    }

    private function deleteProject(array $args): array
    {
        if (!$this->user['isAdmin']) {
            throw new HttpError(403, 'Only admins can delete projects.');
        }

        return $this->repository->deleteProject($this->requiredString($args, 'project'));
    }

    private function settingsArg(array $args): ?array
    {
        if (!array_key_exists('settings', $args) || $args['settings'] === null) {
            return null;
        }
        if (!is_array($args['settings']) || (count($args['settings']) > 0 && array_is_list($args['settings']))) {
            throw new HttpError(400, 'Argument "settings" must be an object.');
        }

        return $args['settings'];
    }

    private function settingsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'statuses' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'minLength' => 1], 'description' => 'Ordered Kanban columns.'],
            'priorities' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'minLength' => 1]],
            'defaultStatus' => ['type' => 'string'],
            'defaultPriority' => ['type' => 'string'],
            'doneStatus' => ['type' => 'string'],
        ]];
    }

    private function createTask(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $task = $this->repository->createTask($project, $args, $this->actor($args), $this->user['id']);
        return $this->completeTask($project, (int)$task['id'], $this->actor($args), false);
    }

    private function updateTask(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $task = $this->repository->updateTask($project, $this->requiredId($args, 'id'), $args, $this->actor($args));
        return $this->completeTask($project, (int)$task['id'], $this->actor($args), false);
    }

    private function writeTaskComment(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $taskId = $this->requiredId($args, 'taskId');
        $actor = $this->actor($args);
        $commentId = $this->optionalId($args, 'commentId');
        $comment = $commentId === null
            ? $this->repository->addTaskComment($project, $taskId, $actor, $args)
            : $this->repository->updateTaskComment($project, $taskId, $commentId, $actor, $args);

        return [
            'comment' => $comment,
            'task' => $this->completeTask($project, $taskId, $actor, $this->boolArg($args, 'markConversationRead')),
        ];
    }

    private function completeTask(string $project, int $taskId, string $actor, bool $markConversationRead): array
    {
        if ($markConversationRead) {
            $this->repository->markTaskConversationRead($project, $taskId, $actor);
        }

        $task = $this->repository->getTask($project, $taskId, $actor);
        $task['attachments'] = array_map(function (array $attachment) use ($project, $taskId): array {
            $url = $this->absoluteUrl('/api/projects/' . rawurlencode($project) . '/tasks/' . $taskId . '/attachments/' . $attachment['id']);
            unset($attachment['path'], $attachment['originalPath']);
            $attachment['url'] = $url;
            $attachment['downloadUrl'] = $url;
            $attachment['markdown'] = $attachment['type'] === 'image'
                ? '![' . $attachment['name'] . '](' . $url . ')'
                : '[' . $attachment['name'] . '](' . $url . ')';
            return $attachment;
        }, $task['attachments'] ?? []);
        $task['conversation'] = $this->repository->taskConversation($project, $taskId);
        return $task;
    }

    private function listDocuments(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $parentId = $this->optionalId($args, 'parentId');
        $search = $this->optionalString($args, 'search');
        $recursive = $this->boolArg($args, 'recursive');
        $documents = $this->repository->listDocuments($project, $search);

        if ($parentId !== null) {
            $parent = $this->repository->getDocument($project, $parentId);
            $this->assertDocumentType($parent, 'folder');
        }

        if ($search === null || trim($search) === '') {
            $documentsById = [];
            foreach ($documents as $document) {
                $documentsById[$document['id']] = $document;
            }
            $documents = array_values(array_filter($documents, function (array $document) use ($parentId, $recursive, $documentsById): bool {
                if (!$recursive) {
                    return $document['parentId'] === $parentId;
                }
                if ($parentId === null) {
                    return true;
                }
                $currentParentId = $document['parentId'];
                while ($currentParentId !== null) {
                    if ($currentParentId === $parentId) {
                        return true;
                    }
                    $currentParentId = $documentsById[$currentParentId]['parentId'] ?? null;
                }
                return false;
            }));
        }

        return [
            'project' => $project,
            'parentId' => $parentId,
            'recursive' => $recursive,
            'search' => $search,
            'count' => count($documents),
            'documents' => array_map(fn (array $document) => $this->documentPayload($document, false), $documents),
        ];
    }

    private function readDocument(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $document = $this->repository->getDocument($project, $this->requiredId($args, 'id'));
        if ($document['type'] === 'folder') {
            $children = array_values(array_filter(
                $this->repository->listDocuments($project),
                fn (array $child) => $child['parentId'] === $document['id']
            ));
            return [
                'document' => $this->documentPayload($document, false),
                'children' => array_map(fn (array $child) => $this->documentPayload($child, false), $children),
            ];
        }

        return ['document' => $this->documentPayload($document, true)];
    }

    private function writeDocumentFile(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $id = $this->optionalId($args, 'id');
        if ($id === null) {
            $document = $this->repository->createDocument($project, [
                'type' => 'file',
                'parentId' => $this->optionalId($args, 'parentId'),
                'name' => $this->requiredString($args, 'name'),
                'content' => $this->optionalString($args, 'content') ?? '',
            ]);
            return ['action' => 'created', 'document' => $this->documentPayload($document, true)];
        }

        $this->assertDocumentType($this->repository->getDocument($project, $id), 'file');
        $body = [];
        foreach (['name', 'content'] as $field) {
            if (array_key_exists($field, $args)) {
                $body[$field] = $field === 'name' ? $this->requiredString($args, $field) : ($this->optionalString($args, $field) ?? '');
            }
        }
        if (array_key_exists('parentId', $args)) {
            $body['parentId'] = $this->optionalId($args, 'parentId');
        }

        $document = $this->repository->updateDocument($project, $id, $body);
        return ['action' => 'updated', 'document' => $this->documentPayload($document, true)];
    }

    private function writeDocumentFolder(array $args): array
    {
        $project = $this->requiredString($args, 'project');
        $id = $this->optionalId($args, 'id');
        if ($id === null) {
            $document = $this->repository->createDocument($project, [
                'type' => 'folder',
                'parentId' => $this->optionalId($args, 'parentId'),
                'name' => $this->requiredString($args, 'name'),
            ]);
            return ['action' => 'created', 'document' => $this->documentPayload($document, false)];
        }

        $this->assertDocumentType($this->repository->getDocument($project, $id), 'folder');
        $body = [];
        if (array_key_exists('name', $args)) {
            $body['name'] = $this->requiredString($args, 'name');
        }
        if (array_key_exists('parentId', $args)) {
            $body['parentId'] = $this->optionalId($args, 'parentId');
        }

        $document = $this->repository->updateDocument($project, $id, $body);
        return ['action' => 'updated', 'document' => $this->documentPayload($document, false)];
    }

    private function deleteDocument(array $args, string $type): array
    {
        $project = $this->requiredString($args, 'project');
        $id = $this->requiredId($args, 'id');
        $this->assertDocumentType($this->repository->getDocument($project, $id), $type);
        return $this->repository->deleteDocument($project, $id);
    }

    private function documentPayload(array $document, bool $includeContent): array
    {
        $downloadUrl = $this->absoluteUrl('/api/projects/' . rawurlencode($document['project']) . '/documents/' . $document['id'] . '/download');
        $payload = $document;
        $payload['downloadUrl'] = $downloadUrl;
        if ($payload['type'] === 'file') {
            $payload['publicUrl'] = $this->absoluteUrl($payload['publicUrl']);
        }
        if (!$includeContent) {
            unset($payload['content']);
            return $payload;
        }

        $extension = strtolower(strrchr($payload['name'], '.') ?: '');
        $textExtensions = ['', '.c', '.conf', '.config', '.css', '.csv', '.env', '.htm', '.html', '.ini', '.js', '.json', '.log', '.md', '.php', '.ps1', '.py', '.sql', '.svg', '.ts', '.txt', '.xml', '.yaml', '.yml'];
        if (!in_array($extension, $textExtensions, true)) {
            unset($payload['content']);
            $payload['contentKind'] = 'binary';
            return $payload;
        }

        $payload['contentKind'] = 'text';
        return $payload;
    }

    private function assertDocumentType(array $document, string $type): void
    {
        if ($document['type'] !== $type) {
            throw new HttpError(400, sprintf('Document #%d is a %s, not a %s.', $document['id'], $document['type'], $type));
        }
    }

    private function filteredTasks(array $args): array
    {
        $tasks = $this->repository->listTasks($this->requiredString($args, 'project'), $this->actor($args));
        $filtered = array_values(array_filter($tasks, function (array $task) use ($args): bool {
            if (($task['archivedAt'] !== null) !== (($args['archived'] ?? false) === true)) {
                return false;
            }
            if (($args['status'] ?? null) && $task['status'] !== $args['status']) {
                return false;
            }
            if (($args['priority'] ?? null) && $task['priority'] !== $args['priority']) {
                return false;
            }
            if (($args['group'] ?? null) && $task['group'] !== $args['group']) {
                return false;
            }
            if (($args['tag'] ?? null) && !in_array($args['tag'], $task['tags'], true)) {
                return false;
            }
            if (($args['dueBefore'] ?? null) && (!$task['due'] || $task['due'] >= $args['dueBefore'])) {
                return false;
            }
            if (($args['dueAfter'] ?? null) && (!$task['due'] || $task['due'] <= $args['dueAfter'])) {
                return false;
            }
            return true;
        }));

        return $this->aivax->rerankTasks((string)($args['search'] ?? ''), $filtered);
    }

    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => count($properties) === 0 ? new \stdClass() : $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    private function authorized(): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $bearer = preg_match('/^Bearer\s+(.+)$/i', $header, $match) ? $match[1] : '';
        $queryToken = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
        $token = $bearer ?: ($_SERVER['HTTP_X_MCP_TOKEN'] ?? '') ?: $queryToken;
        $user = $token === '' ? null : $this->repository->findUser('mcpKey', (string)$token);
        if ($user === null) {
            return false;
        }
        $this->user = ['id' => $user['id'], 'name' => $user['name'], 'isAdmin' => $user['isAdmin']];
        return true;
    }

    private function requiredString(array $args, string $name): string
    {
        if (!is_string($args[$name] ?? null) || trim($args[$name]) === '') {
            throw new HttpError(400, sprintf('Argument "%s" is required.', $name));
        }

        return trim($args[$name]);
    }

    private function requiredId(array $args, string $name): int
    {
        $value = $args[$name] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new HttpError(400, sprintf('Argument "%s" must be a positive integer.', $name));
        }

        return $value;
    }

    private function optionalId(array $args, string $name): ?int
    {
        if (!array_key_exists($name, $args) || $args[$name] === null) {
            return null;
        }
        if (!is_int($args[$name]) || $args[$name] < 1) {
            throw new HttpError(400, sprintf('Argument "%s" must be a positive integer or null.', $name));
        }

        return $args[$name];
    }

    private function optionalString(array $args, string $name): ?string
    {
        if (!array_key_exists($name, $args) || $args[$name] === null) {
            return null;
        }
        if (!is_string($args[$name])) {
            throw new HttpError(400, sprintf('Argument "%s" must be a string or null.', $name));
        }

        return $args[$name];
    }

    private function boolArg(array $args, string $name): bool
    {
        if (!array_key_exists($name, $args)) {
            return false;
        }
        if (!is_bool($args[$name])) {
            throw new HttpError(400, sprintf('Argument "%s" must be a boolean.', $name));
        }

        return $args[$name];
    }

    private function actor(array $args): string
    {
        return $this->user['name'] ?? 'MCP Agent';
    }

    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    private function jsonRpc(mixed $id, mixed $result = null, ?array $error = null, int $status = 200): void
    {
        Http::json($status, $this->message($id, $result, $error));
    }

    private function message(mixed $id, mixed $result = null, ?array $error = null): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id];
        if ($error) {
            $message['error'] = $error;
        } else {
            $message['result'] = $result;
        }

        return $message;
    }

}
