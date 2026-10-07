<?php

$root = dirname(__DIR__);
$tempBase = getenv('TEMP') ?: getenv('TMP') ?: sys_get_temp_dir();
$temp = $tempBase . DIRECTORY_SEPARATOR . '.avi' . DIRECTORY_SEPARATOR . 'visualizations' . DIRECTORY_SEPARATOR . date('Y-m-d-H-i-T') . DIRECTORY_SEPARATOR . 'manycontext-tests-' . bin2hex(random_bytes(4));
$configPath = $temp . DIRECTORY_SEPARATOR . 'config.ini';
$dbPath = $temp . DIRECTORY_SEPARATOR . 'manycontext.sqlite';
$attachmentsPath = $temp . DIRECTORY_SEPARATOR . 'attachments';
$sessionCookie = '';

mkdir($temp, 0777, true);
$legacyDb = new PDO('sqlite:' . $dbPath);
$legacyDb->exec('CREATE TABLE daily_ai_summaries (user_id TEXT NOT NULL, summary_date TEXT NOT NULL, content TEXT NOT NULL, generated_at TEXT NOT NULL, PRIMARY KEY (user_id, summary_date))');
unset($legacyDb);
file_put_contents($configPath, "[app]\ndatabase = \"$dbPath\"\nattachments = \"$attachmentsPath\"\n");

$port = free_port();
$nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['file', $nullDevice, 'w'],
    2 => ['file', $nullDevice, 'w'],
];
$env = array_filter(array_merge($_ENV, $_SERVER, ['MANYCONTEXT_CONFIG' => $configPath]), 'is_string');
unset($env['AIVAX_API_KEY']);
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', 'public', 'public/index.php'], $descriptor, $pipes, $root, $env, ['bypass_shell' => true]);
if (!is_resource($process)) {
    fail('Could not start PHP test server.');
}

try {
    wait_for_server($port);
    $base = 'http://127.0.0.1:' . $port;

    $home = http_request('GET', $base . '/');
    assert_true($home['status'] === 200 && str_contains($home['body'], 'PIN de 6 dígitos'), 'serves login screen before authentication');

    $unauthenticatedApi = json_request('GET', $base . '/api/projects');
    assert_same(401, $unauthenticatedApi['status'], 'rejects API without PIN session');

    $setupStatus = json_request('GET', $base . '/auth/status');
    assert_true($setupStatus['json']['setupRequired'] === true && $setupStatus['json']['users'] === [], 'requires initial setup when there are no users');
    assert_same(401, json_request('POST', $base . '/auth/login', ['pin' => '000000'])['status'], 'rejects PIN before setup');
    assert_same(400, json_request('POST', $base . '/auth/setup', ['name' => 'Tester', 'pin' => '12'])['status'], 'rejects invalid setup PIN');
    assert_same(400, json_request('POST', $base . '/auth/setup', ['name' => ' ', 'pin' => '128582'])['status'], 'rejects empty setup name');

    $setup = json_request('POST', $base . '/auth/setup', ['name' => 'Tester', 'pin' => '128582']);
    assert_same(201, $setup['status'], 'creates initial admin through setup');
    assert_true($setup['json']['authenticated'] === true && $setup['json']['setupRequired'] === false, 'signs in initial admin');
    assert_true($setup['json']['user']['id'] === 'tester' && $setup['json']['user']['isAdmin'] === true, 'initial setup user is admin');
    assert_same(409, json_request('POST', $base . '/auth/setup', ['name' => 'Intruder', 'pin' => '999999'])['status'], 'rejects setup after the first user exists');

    $badLogin = json_request('POST', $base . '/auth/login', ['pin' => '000000']);
    assert_same(401, $badLogin['status'], 'rejects wrong PIN');

    $login = json_request('POST', $base . '/auth/login', ['pin' => '128582']);
    assert_same(200, $login['status'], 'accepts stored PIN');
    assert_true($login['json']['authenticated'] === true && $login['json']['user']['name'] === 'Tester', 'returns authenticated user');
    assert_true($login['json']['user']['id'] === 'tester' && $login['json']['user']['isAdmin'] === true, 'returns stored user identity and admin flag');
    $sessionCookie = session_cookie_from_headers($login['headers']);

    $adminUsers = json_request('GET', $base . '/api/admin/users');
    assert_same(200, $adminUsers['status'], 'lists users for admin');
    $token = $adminUsers['json']['users'][0]['mcpKey'];
    assert_true(str_starts_with($token, 'mc_') && strlen($token) === 51, 'generates MCP key for initial admin');
    assert_same('128582', $adminUsers['json']['users'][0]['pin'], 'exposes PIN to admins');

    $reviewer = json_request('POST', $base . '/api/admin/users', ['id' => 'reviewer', 'name' => 'Reviewer', 'pin' => '654321', 'isAdmin' => false]);
    assert_same(201, $reviewer['status'], 'admin creates user');
    $outsider = json_request('POST', $base . '/api/admin/users', ['name' => 'Outsider', 'isAdmin' => false]);
    assert_same(201, $outsider['status'], 'admin creates user with generated PIN');
    assert_same('outsider', $outsider['json']['id'], 'derives user ID from name');
    assert_true((bool)preg_match('/^\d{6}$/', $outsider['json']['pin']), 'generates a six-digit PIN');
    $outsiderToken = $outsider['json']['mcpKey'];
    assert_same(409, json_request('POST', $base . '/api/admin/users', ['name' => 'Duplicado', 'pin' => '654321'])['status'], 'rejects duplicated PIN');
    assert_same(409, json_request('POST', $base . '/api/admin/users', ['name' => 'reviewer', 'pin' => '222222'])['status'], 'rejects duplicated name');
    assert_same(400, json_request('PUT', $base . '/api/admin/users/outsider', ['pin' => 'abc'])['status'], 'rejects invalid PIN update');
    $outsiderPin = json_request('PUT', $base . '/api/admin/users/outsider', ['pin' => '111111']);
    assert_same('111111', $outsiderPin['json']['pin'], 'admin changes user PIN');
    assert_same($outsiderToken, $outsiderPin['json']['mcpKey'], 'keeps MCP key when not rotating');
    assert_same(400, json_request('PUT', $base . '/api/admin/users/tester', ['isAdmin' => false])['status'], 'prevents admin from demoting itself');
    assert_same(400, json_request('DELETE', $base . '/api/admin/users/tester')['status'], 'prevents admin from deleting itself');

    $temporaryUser = json_request('POST', $base . '/api/admin/users', ['name' => 'Temporário']);
    $temporaryToken = $temporaryUser['json']['mcpKey'];
    assert_same(200, mcp_request($base, $temporaryToken, 'server/discover')['status'], 'authenticates MCP with generated key');
    $rotated = json_request('PUT', $base . '/api/admin/users/' . $temporaryUser['json']['id'], ['rotateMcpKey' => true]);
    assert_true($rotated['json']['mcpKey'] !== $temporaryToken, 'rotates MCP key');
    assert_same(401, mcp_request($base, $temporaryToken, 'server/discover')['status'], 'revokes old MCP key after rotation');
    assert_same(200, mcp_request($base, $rotated['json']['mcpKey'], 'server/discover')['status'], 'accepts rotated MCP key');
    assert_true(json_request('DELETE', $base . '/api/admin/users/' . $temporaryUser['json']['id'])['json']['deleted'], 'admin deletes user');
    assert_same(401, mcp_request($base, $rotated['json']['mcpKey'], 'server/discover')['status'], 'revokes MCP key of deleted user');
    assert_same(401, json_request('POST', $base . '/auth/login', ['pin' => $temporaryUser['json']['pin']])['status'], 'revokes PIN of deleted user');

    $emptySettings = json_request('GET', $base . '/api/admin/settings');
    assert_true($emptySettings['json']['aivax']['configured'] === false && $emptySettings['json']['aivax']['maskedKey'] === null, 'reports missing AIVAX key');
    $savedSettings = json_request('PUT', $base . '/api/admin/settings', ['aivaxApiKey' => 'secret-aivax-key-1234', 'aivaxModel' => '@test/model']);
    assert_true($savedSettings['json']['aivax']['configured'] === true && str_ends_with($savedSettings['json']['aivax']['maskedKey'], '1234'), 'stores AIVAX key and returns masked value');
    assert_true(!str_contains($savedSettings['body'], 'secret-aivax-key'), 'never returns the full AIVAX key');
    assert_same('@test/model', $savedSettings['json']['aivax']['model'], 'stores AIVAX model');
    $clearedSettings = json_request('PUT', $base . '/api/admin/settings', ['aivaxApiKey' => '', 'aivaxModel' => '']);
    assert_true($clearedSettings['json']['aivax']['configured'] === false, 'removes AIVAX key');
    assert_same($clearedSettings['json']['aivax']['defaultModel'], $clearedSettings['json']['aivax']['model'], 'falls back to default AIVAX model');

    $authenticatedHome = http_request('GET', $base . '/');
    assert_true($authenticatedHome['status'] === 200 && str_contains($authenticatedHome['body'], 'project-list'), 'serves frontend after authentication');

    $settings = [
        'statuses' => ['todo', 'doing', 'done'],
        'priorities' => ['low', 'normal', 'urgent'],
        'defaultStatus' => 'todo',
        'defaultPriority' => 'normal',
        'doneStatus' => 'done',
    ];
    $projectSettings = json_request('PUT', $base . '/api/projects/Alpha', ['settings' => $settings]);
    assert_same(200, $projectSettings['status'], 'creates project through settings endpoint');
    assert_same('normal', $projectSettings['json']['settings']['defaultPriority'], 'stores project defaults');

    $displayNameUpdate = json_request('PUT', $base . '/api/projects/Alpha', ['displayName' => 'Alpha Renomeado']);
    assert_same(200, $displayNameUpdate['status'], 'updates project displayName without settings');
    assert_same('Alpha', $displayNameUpdate['json']['name'], 'keeps project name as ID after displayName update');
    assert_same('Alpha Renomeado', $displayNameUpdate['json']['displayName'], 'stores project displayName');
    assert_same('normal', $displayNameUpdate['json']['settings']['defaultPriority'], 'preserves settings when only displayName is sent');

    $displayNameWithSettings = json_request('PUT', $base . '/api/projects/Alpha', ['displayName' => 'Alpha Final', 'settings' => $settings]);
    assert_same(200, $displayNameWithSettings['status'], 'accepts displayName together with settings');
    assert_same('Alpha', $displayNameWithSettings['json']['name'], 'keeps project name as ID when settings are sent');
    assert_same('Alpha Final', $displayNameWithSettings['json']['displayName'], 'updates project displayName with settings');

    $projectAfterRename = json_request('GET', $base . '/api/projects/Alpha');
    assert_same('Alpha Final', $projectAfterRename['json']['displayName'], 'exposes displayName when reading one project');
    $summaryAfterRename = json_request('GET', $base . '/api/projects');
    assert_same('Alpha Final', $summaryAfterRename['json'][0]['displayName'], 'exposes displayName when listing projects');

    $members = json_request('GET', $base . '/api/projects/Alpha/members');
    assert_same(200, $members['status'], 'lists project members for admin');
    assert_true($members['json']['members'][0]['isMember'] === true && $members['json']['members'][1]['isMember'] === false, 'new project starts with creator and admins only');
    $updatedMembers = json_request('PUT', $base . '/api/projects/Alpha/members', ['userIds' => ['reviewer']]);
    assert_same(200, $updatedMembers['status'], 'admin adds project members');

    $created = json_request('POST', $base . '/api/projects/Alpha/tasks', [
        'title' => 'Write PHP port',
        'desc' => 'Move API and MCP to SQLite',
        'due' => '2026-07-10',
        'priority' => 'urgent',
        'tags' => ['php', 'sqlite'],
        'group' => 'migration',
        'status' => 'doing',
        'workingAgent' => 'Codex',
    ]);
    assert_same(201, $created['status'], 'creates task');
    assert_same(1, $created['json']['id'], 'uses per-project task IDs');

    $updated = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['status' => 'done', 'tags' => ['tested']]);
    assert_same(200, $updated['status'], 'updates task');
    assert_same('done', $updated['json']['status'], 'persists task update');

    $subTaskUpdate = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['subTasks' => [
        ['title' => 'Terceiro passo', 'description' => 'Ultimo', 'order' => 2, 'completed' => false],
        ['title' => 'Primeiro passo', 'description' => 'Comeco', 'order' => 0, 'completed' => true],
        ['title' => 'Segundo passo', 'description' => 'Meio', 'order' => 1, 'completed' => false],
    ]]);
    assert_same(200, $subTaskUpdate['status'], 'updates task subTasks');
    assert_same([0, 1, 2], array_column($subTaskUpdate['json']['subTasks'], 'order'), 'returns subTasks ordered by order');
    assert_same(['Primeiro passo', 'Segundo passo', 'Terceiro passo'], array_column($subTaskUpdate['json']['subTasks'], 'title'), 'keeps subTask content aligned with order');
    assert_same(true, $subTaskUpdate['json']['subTasks'][0]['completed'], 'persists subTask completed flag');
    assert_same('Comeco', $subTaskUpdate['json']['subTasks'][0]['description'], 'persists subTask description');

    $singleTask = json_request('GET', $base . '/api/projects/Alpha/tasks/1');
    assert_same(200, $singleTask['status'], 'reads single task');
    assert_same([0, 1, 2], array_column($singleTask['json']['subTasks'], 'order'), 'returns subTasks when reading one task');

    $taskList = json_request('GET', $base . '/api/projects/Alpha/tasks');
    assert_same(200, $taskList['status'], 'lists tasks');
    assert_same([0, 1, 2], array_column($taskList['json'][0]['subTasks'], 'order'), 'returns subTasks when listing tasks');

    $preservedSubTasks = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['desc' => 'Move API and MCP to SQLite (revisado)']);
    assert_same(200, $preservedSubTasks['status'], 'updates task without subTasks');
    assert_same([0, 1, 2], array_column($preservedSubTasks['json']['subTasks'], 'order'), 'preserves subTasks when omitted from update');
    assert_same('Primeiro passo', $preservedSubTasks['json']['subTasks'][0]['title'], 'keeps subTask titles when omitted from update');

    $invalidSubTaskCreate = json_request('POST', $base . '/api/projects/Alpha/tasks', [
        'title' => 'Task com subtarefa invalida',
        'subTasks' => [['title' => '', 'description' => 'vazia', 'order' => 0, 'completed' => false]],
    ]);
    assert_same(400, $invalidSubTaskCreate['status'], 'rejects subTask with empty title on create');

    $invalidSubTaskOrder = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', [
        'subTasks' => [['title' => 'Ordem invalida', 'description' => '', 'order' => -1, 'completed' => false]],
    ]);
    assert_same(400, $invalidSubTaskOrder['status'], 'rejects subTask with negative order');

    $invalidSubTaskFlag = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', [
        'subTasks' => [['title' => 'Flag invalida', 'description' => '', 'order' => 0, 'completed' => 'yes']],
    ]);
    assert_same(400, $invalidSubTaskFlag['status'], 'rejects subTask with non-boolean completed');

    $conversationAfterUpdate = json_request('GET', $base . '/api/projects/Alpha/tasks/1/conversation');
    assert_same(200, $conversationAfterUpdate['status'], 'lists task conversation');
    assert_true(count($conversationAfterUpdate['json']) >= 2, 'records task creation and update events');
    assert_same('Tester', $conversationAfterUpdate['json'][0]['userName'], 'records task event user');
    assert_same('created', $conversationAfterUpdate['json'][0]['type'], 'records task creation event');
    assert_same('updated', $conversationAfterUpdate['json'][1]['type'], 'records task update event');

    $project = json_request('GET', $base . '/api/projects/Alpha');
    assert_same(200, $project['status'], 'reads project');
    assert_same('tested', $project['json']['tasks'][0]['tags'][0], 'returns normalized task shape');

    $summary = json_request('GET', $base . '/api/projects');
    assert_same(200, $summary['status'], 'lists projects');
    assert_same(1, $summary['json'][0]['completedTasksCount'], 'computes project summary counts');

    $notePath = $temp . DIRECTORY_SEPARATOR . 'note.txt';
    $imagePath = $temp . DIRECTORY_SEPARATOR . 'photo.png';
    file_put_contents($notePath, 'api note');
    file_put_contents($imagePath, 'fake image');
    $upload = upload_request($base . '/api/projects/Alpha/tasks/1/attachments', [$notePath, $imagePath]);
    assert_same(201, $upload['status'], 'uploads attachments');
    assert_same(2, count($upload['json']['attachments']), 'uploads multiple attachments');
    assert_same('image', $upload['json']['attachments'][1]['type'], 'detects image attachment type');
    $conversationAfterAttachment = json_request('GET', $base . '/api/projects/Alpha/tasks/1/conversation');
    assert_true(in_array('attachment_added', array_column($conversationAfterAttachment['json'], 'type'), true), 'records attachment event');

    $comment = json_request('POST', $base . '/api/projects/Alpha/tasks/1/conversation', ['content' => "Comentário com imagem\n\n![foto](/api/projects/Alpha/tasks/1/attachments/2)"]);
    assert_same(201, $comment['status'], 'creates task comment');
    assert_same('comment', $comment['json']['type'], 'stores comment event');
    assert_same('Tester', $comment['json']['userName'], 'stores comment author');
    $projectAfterOwnComment = json_request('GET', $base . '/api/projects/Alpha');
    assert_same(0, $projectAfterOwnComment['json']['tasks'][0]['unreadCommentCount'], 'does not count own comments as unread');

    $editedComment = json_request('PUT', $base . '/api/projects/Alpha/tasks/1/conversation/' . $comment['json']['id'], ['content' => 'Comentário editado']);
    assert_same(200, $editedComment['status'], 'edits own comment');
    assert_same('Comentário editado', $editedComment['json']['content'], 'persists edited comment');

    $deletedComment = json_request('DELETE', $base . '/api/projects/Alpha/tasks/1/conversation/' . $comment['json']['id']);
    assert_true($deletedComment['json']['deleted'], 'deletes own comment');

    $reviewerLogin = json_request('POST', $base . '/auth/login', ['pin' => '654321']);
    assert_same(200, $reviewerLogin['status'], 'logs in second configured user');
    $sessionCookie = session_cookie_from_headers($reviewerLogin['headers']);
    $reviewerComment = json_request('POST', $base . '/api/projects/Alpha/tasks/1/conversation', ['content' => 'Comentário do revisor']);
    assert_same('Reviewer', $reviewerComment['json']['userName'], 'stores second user as comment author');
    $reviewerProject = json_request('GET', $base . '/api/projects/Alpha');
    assert_same(0, $reviewerProject['json']['tasks'][0]['unreadCommentCount'], 'does not count reviewer own comment as unread');
    $archivedTask = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['archived' => true]);
    assert_true($archivedTask['status'] === 200 && $archivedTask['json']['archivedAt'] !== null, 'project member archives task');
    assert_same(0, json_request('GET', $base . '/api/projects')['json'][0]['tasksCount'], 'excludes archived tasks from project summary');
    assert_same(400, json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['archived' => 'yes'])['status'], 'rejects non-boolean archived flag');
    $unarchivedTask = json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['archived' => false]);
    assert_true($unarchivedTask['json']['archivedAt'] === null, 'project member unarchives task');
    $archiveEvents = array_column(json_request('GET', $base . '/api/projects/Alpha/tasks/1/conversation')['json'], 'type');
    assert_true(in_array('archived', $archiveEvents, true) && in_array('unarchived', $archiveEvents, true), 'records archive events');
    assert_same(403, json_request('PUT', $base . '/api/admin/projects/Alpha', ['archived' => true])['status'], 'restricts project archiving to admins');
    $reviewerMemberUpdate = json_request('PUT', $base . '/api/projects/Alpha/members', ['userIds' => []]);
    assert_same(403, $reviewerMemberUpdate['status'], 'rejects member management by non-admin');
    $reviewerAnalytics = json_request('GET', $base . '/api/admin/analytics');
    assert_same(403, $reviewerAnalytics['status'], 'restricts analytics dashboard to admins');
    assert_same(403, json_request('GET', $base . '/api/admin/users')['status'], 'restricts user management to admins');
    assert_same(403, json_request('PUT', $base . '/api/admin/settings', ['aivaxApiKey' => 'x'])['status'], 'restricts API key management to admins');
    $reviewerStatus = json_request('GET', $base . '/auth/status');
    assert_true(!str_contains($reviewerStatus['body'], 'mcpKey') && !str_contains($reviewerStatus['body'], '"pin"'), 'does not expose secrets in auth status');

    $outsiderLogin = json_request('POST', $base . '/auth/login', ['pin' => '111111']);
    assert_same(200, $outsiderLogin['status'], 'logs in user outside project');
    $sessionCookie = session_cookie_from_headers($outsiderLogin['headers']);
    assert_same(0, count(json_request('GET', $base . '/api/projects')['json']), 'hides projects from non-members');
    assert_same(403, json_request('GET', $base . '/api/projects/Alpha')['status'], 'blocks direct project access for non-members');

    $testerRelogin = json_request('POST', $base . '/auth/login', ['pin' => '128582']);
    assert_same(200, $testerRelogin['status'], 'logs first user back in');
    $sessionCookie = session_cookie_from_headers($testerRelogin['headers']);
    $archivedProject = json_request('PUT', $base . '/api/admin/projects/Alpha', ['archived' => true]);
    assert_true($archivedProject['status'] === 200 && $archivedProject['json']['archivedAt'] !== null, 'admin archives project');
    assert_true(!in_array('Alpha', array_column(json_request('GET', $base . '/api/projects')['json'], 'name'), true), 'hides archived project from project list');
    assert_same(403, json_request('GET', $base . '/api/projects/Alpha')['status'], 'blocks access to archived project');
    $adminProjects = json_request('GET', $base . '/api/admin/projects');
    assert_true(in_array('Alpha', array_column($adminProjects['json']['projects'], 'name'), true), 'lists archived projects for admins');
    $restoredProject = json_request('PUT', $base . '/api/admin/projects/Alpha', ['archived' => false]);
    assert_true($restoredProject['json']['archivedAt'] === null, 'admin unarchives project');
    assert_same(200, json_request('GET', $base . '/api/projects/Alpha')['status'], 'restores access to unarchived project');
    $adminAnalytics = json_request('GET', $base . '/api/admin/analytics?days=7');
    assert_same(200, $adminAnalytics['status'], 'loads admin analytics dashboard');
    assert_same(1, $adminAnalytics['json']['totals']['created'], 'counts tasks created in the selected period');
    assert_same(1, $adminAnalytics['json']['totals']['completed'], 'counts transitions to the project done status');
    assert_same(1, $adminAnalytics['json']['totals']['comments'], 'excludes deleted comments from analytics');
    assert_same(7, count($adminAnalytics['json']['series']), 'returns one analytics point per day');
    $reviewerAnalytics = json_request('GET', $base . '/api/admin/analytics?days=7&project=Alpha&user=Reviewer');
    assert_same(1, $reviewerAnalytics['json']['totals']['comments'], 'filters analytics by project and user');
    assert_same(0, $reviewerAnalytics['json']['totals']['created'], 'keeps unrelated user activity out of filtered analytics');
    json_request('PUT', $base . '/api/projects/Alpha/tasks/1', ['assignees' => ['Reviewer']]);
    $renamedReviewer = json_request('PUT', $base . '/api/admin/users/reviewer', ['name' => 'Revisora']);
    assert_same('Revisora', $renamedReviewer['json']['name'], 'admin renames user');
    assert_same(['Revisora'], json_request('GET', $base . '/api/projects/Alpha/tasks/1')['json']['assignees'], 'propagates user rename to task assignees');
    assert_same(1, json_request('GET', $base . '/api/admin/analytics?days=7&project=Alpha&user=Revisora')['json']['totals']['comments'], 'propagates user rename to activity history');
    json_request('PUT', $base . '/api/admin/users/reviewer', ['name' => 'Reviewer']);
    $projectWithUnread = json_request('GET', $base . '/api/projects/Alpha');
    assert_same(1, $projectWithUnread['json']['tasks'][0]['unreadCommentCount'], 'counts unread comments from other users');
    $conversationPreview = json_request('GET', $base . '/api/projects/Alpha/tasks/1/conversation');
    assert_same(200, $conversationPreview['status'], 'opens conversation without marking it read');
    $stillUnread = json_request('GET', $base . '/api/projects/Alpha');
    assert_same(1, $stillUnread['json']['tasks'][0]['unreadCommentCount'], 'keeps unread count until read endpoint is called');
    $readConversation = json_request('POST', $base . '/api/projects/Alpha/tasks/1/conversation/read');
    assert_same(0, $readConversation['json']['unreadCommentCount'], 'marks conversation comments as read');
    $afterReadProject = json_request('GET', $base . '/api/projects/Alpha');
    assert_same(0, $afterReadProject['json']['tasks'][0]['unreadCommentCount'], 'clears unread count after opening conversation tab');
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $dashboard = json_request('GET', $base . '/api/home');
    assert_same(200, $dashboard['status'], 'loads personalized home dashboard');
    assert_true(array_key_exists('priorities', $dashboard['json']) && array_key_exists('activity', $dashboard['json']), 'returns home priorities and activity');
    assert_same($today, $dashboard['json']['activityDate'], 'defaults home activity to today');
    assert_true(array_key_exists('data', $dashboard['json']['activity'][0]), 'returns activity details for rich rendering');

    $selectedActivity = json_request('GET', $base . '/api/home?activityDate=' . $today);
    assert_same(200, $selectedActivity['status'], 'loads activity for a selected weekday');
    assert_same($today, $selectedActivity['json']['activityDate'], 'returns selected activity date');
    assert_same(400, json_request('GET', $base . '/api/home?activityDate=2026-02-31')['status'], 'rejects invalid activity date');
    assert_same(400, json_request('POST', $base . '/api/home/activity-summary', [])['status'], 'requires a date for home activity AI summary');
    assert_same(503, json_request('POST', $base . '/api/home/activity-summary', ['activityDate' => $today])['status'], 'requires AIVAX_API_KEY for home activity AI summary');
    $dailySummaryColumns = (new PDO('sqlite:' . $dbPath))->query('PRAGMA table_info(daily_ai_summaries)')->fetchAll(PDO::FETCH_ASSOC);
    assert_true(in_array('format_version', array_column($dailySummaryColumns, 'name'), true), 'migrates existing daily summaries to versioned format');
    $missingAivax = json_request('POST', $base . '/api/projects/Alpha/tasks/1/ai', ['action' => 'summary']);
    assert_same(503, $missingAivax['status'], 'requires AIVAX_API_KEY for task AI actions');

    $download = http_request('GET', $base . '/api/projects/Alpha/tasks/1/attachments/1');
    assert_true($download['status'] === 200 && $download['body'] === 'api note', 'downloads attachment bytes');

    $removed = json_request('DELETE', $base . '/api/projects/Alpha/tasks/1/attachments/1');
    assert_same(200, $removed['status'], 'removes attachment');
    assert_same(1, count($removed['json']['task']['attachments']), 'returns task after attachment removal');

    $folder = json_request('POST', $base . '/api/projects/Alpha/documents', [
        'type' => 'folder',
        'name' => 'Cliente',
    ]);
    assert_same(201, $folder['status'], 'creates document folder');
    assert_same('folder', $folder['json']['type'], 'returns folder node');

    $file = json_request('POST', $base . '/api/projects/Alpha/documents', [
        'type' => 'file',
        'parentId' => $folder['json']['id'],
        'name' => 'briefing.md',
        'content' => "# Briefing\n\nCliente usa FTS5 para documentos.",
    ]);
    assert_same(201, $file['status'], 'creates markdown document');
    assert_same($folder['json']['id'], $file['json']['parentId'], 'keeps document parent folder');
    assert_true(str_starts_with($file['json']['publicUrl'], '/public/documents/'), 'returns public document URL');

    $documents = json_request('GET', $base . '/api/projects/Alpha/documents');
    assert_same(200, $documents['status'], 'lists document tree');
    assert_same(2, count($documents['json']), 'lists folders and files');

    $searchByTitle = json_request('GET', $base . '/api/projects/Alpha/documents?search=briefing');
    assert_same(200, $searchByTitle['status'], 'searches documents by title with FTS5');
    assert_same('briefing.md', $searchByTitle['json'][0]['name'], 'finds document by title');

    $searchByContent = json_request('GET', $base . '/api/projects/Alpha/documents?search=FTS5');
    assert_same(1, count($searchByContent['json']), 'searches documents by content with FTS5');

    $updatedDoc = json_request('PUT', $base . '/api/projects/Alpha/documents/' . $file['json']['id'], [
        'name' => 'briefing-final.md',
        'content' => "# Briefing final\n\nAuto-save em 250ms.",
    ]);
    assert_same(200, $updatedDoc['status'], 'updates document content');
    assert_same('briefing-final.md', $updatedDoc['json']['name'], 'renames document');

    $publicDoc = http_request('GET', $base . $updatedDoc['json']['publicUrl']);
    assert_true($publicDoc['status'] === 200 && str_contains($publicDoc['body'], 'Auto-save em 250ms.'), 'opens public document URL');

    $uploadDocPath = $temp . DIRECTORY_SEPARATOR . 'uploaded-note.md';
    $nestedDocPath = $temp . DIRECTORY_SEPARATOR . 'nested.md';
    file_put_contents($uploadDocPath, "Uploaded note\n");
    file_put_contents($nestedDocPath, "Nested uploaded content\n");
    $uploadedDocs = document_upload_request($base . '/api/projects/Alpha/documents/upload', $folder['json']['id'], [
        ['path' => $uploadDocPath, 'relative' => 'uploaded-note.md'],
        ['path' => $nestedDocPath, 'relative' => 'ImportedFolder/nested.md'],
    ]);
    assert_same(201, $uploadedDocs['status'], 'uploads document files');
    assert_same(2, $uploadedDocs['json']['uploaded'], 'imports multiple uploaded documents');

    $downloadedFile = http_request('GET', $base . '/api/projects/Alpha/documents/' . $updatedDoc['json']['id'] . '/download');
    assert_true($downloadedFile['status'] === 200 && str_contains($downloadedFile['body'], 'Auto-save em 250ms.'), 'downloads one document file');

    $downloadedFolder = http_request('GET', $base . '/api/projects/Alpha/documents/' . $folder['json']['id'] . '/download');
    assert_same(200, $downloadedFolder['status'], 'downloads folder as zip');
    $zipPath = $temp . DIRECTORY_SEPARATOR . 'documents.zip';
    file_put_contents($zipPath, $downloadedFolder['body']);
    $zip = new ZipArchive();
    assert_true($zip->open($zipPath) === true, 'opens downloaded folder zip');
    assert_true(str_contains($zip->getFromName('Cliente/briefing-final.md') ?: '', 'Auto-save em 250ms.'), 'zip includes edited document');
    assert_true(str_contains($zip->getFromName('Cliente/ImportedFolder/nested.md') ?: '', 'Nested uploaded content'), 'zip includes uploaded folder contents');
    $zip->close();

    $downloadedRoot = http_request('GET', $base . '/api/projects/Alpha/documents/download');
    assert_same(200, $downloadedRoot['status'], 'downloads document root as zip');
    $rootZipPath = $temp . DIRECTORY_SEPARATOR . 'documents-root.zip';
    file_put_contents($rootZipPath, $downloadedRoot['body']);
    $rootZip = new ZipArchive();
    assert_true($rootZip->open($rootZipPath) === true, 'opens downloaded root zip');
    assert_true(str_contains($rootZip->getFromName('Cliente/briefing-final.md') ?: '', 'Auto-save em 250ms.'), 'root zip includes folder contents');
    $rootZip->close();

    $downloadedSelection = http_request('GET', $base . '/api/projects/Alpha/documents/download?ids=' . $folder['json']['id'] . ',' . $updatedDoc['json']['id']);
    assert_same(200, $downloadedSelection['status'], 'downloads selected documents as zip');
    $selectionZipPath = $temp . DIRECTORY_SEPARATOR . 'documents-selection.zip';
    file_put_contents($selectionZipPath, $downloadedSelection['body']);
    $selectionZip = new ZipArchive();
    assert_true($selectionZip->open($selectionZipPath) === true, 'opens downloaded selection zip');
    assert_true(str_contains($selectionZip->getFromName('Cliente/briefing-final.md') ?: '', 'Auto-save em 250ms.'), 'selection zip includes selected folder contents');
    $selectionZip->close();

    $deletedFolder = json_request('DELETE', $base . '/api/projects/Alpha/documents/' . $folder['json']['id']);
    assert_true($deletedFolder['json']['deleted'], 'deletes document folder');
    $afterDeleteDocs = json_request('GET', $base . '/api/projects/Alpha/documents');
    assert_same(0, count($afterDeleteDocs['json']), 'deletes child documents with folder');

    $projectToDelete = json_request('POST', $base . '/api/projects/ProjectToDelete/tasks', ['title' => 'Temporary project task']);
    assert_same(201, $projectToDelete['status'], 'creates project to delete');
    $deletedProject = json_request('DELETE', $base . '/api/projects/ProjectToDelete');
    assert_true($deletedProject['json']['deleted'], 'deletes project through API');
    $missingProject = json_request('GET', $base . '/api/projects/ProjectToDelete');
    assert_same(404, $missingProject['status'], 'deleted project is not readable');

    $unauthorized = mcp_request($base, $token, 'tools/list', [], false);
    assert_same(401, $unauthorized['status'], 'rejects unauthenticated MCP');

    $discover = mcp_request($base, $token, 'server/discover');
    assert_same(200, $discover['status'], 'serves MCP discovery');
    assert_same('manycontext-php', $discover['json']['result']['serverInfo']['name'], 'returns MCP server info');

    $queryAuthenticated = mcp_request($base, $token, 'server/discover', [], true, true);
    assert_same(200, $queryAuthenticated['status'], 'accepts MCP token from query string');

    $tools = mcp_request($base, $token, 'tools/list');
    assert_same(200, $tools['status'], 'lists MCP tools');
    $toolNames = array_column($tools['json']['result']['tools'], 'name');
    foreach ([
        'manycontext_get_task',
        'manycontext_write_task_comment',
        'manycontext_list_documents',
        'manycontext_read_document',
        'manycontext_write_document_file',
        'manycontext_delete_document_file',
        'manycontext_write_document_folder',
        'manycontext_delete_document_folder',
    ] as $toolName) {
        assert_true(in_array($toolName, $toolNames, true), 'exposes MCP tool ' . $toolName);
    }
    foreach (['manycontext_agent_log_write', 'manycontext_agent_log_search'] as $removedTool) {
        assert_true(!in_array($removedTool, $toolNames, true), 'removes MCP tool ' . $removedTool);
    }

    $removedLogWrite = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_agent_log_write',
        'arguments' => ['agent' => 'ChatGPT', 'project' => 'Alpha', 'title' => 'Removed', 'details' => 'Should not be stored.'],
    ]);
    assert_true(isset($removedLogWrite['json']['error']), 'rejects removed Agent Log write tool');
    assert_true(str_contains($removedLogWrite['json']['error']['message'], 'not found'), 'reports removed Agent Log write tool as not found');

    $removedLogSearch = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_agent_log_search',
        'arguments' => ['project' => 'Alpha'],
    ]);
    assert_true(isset($removedLogSearch['json']['error']), 'rejects removed Agent Log search tool');
    assert_true(str_contains($removedLogSearch['json']['error']['message'], 'not found'), 'reports removed Agent Log search tool as not found');

    assert_same(404, json_request('GET', $base . '/api/agent-logs')['status'], 'removes Agent Logs listing endpoint');
    assert_same(404, json_request('GET', $base . '/api/agent-logs/1')['status'], 'removes single Agent Log endpoint');

    $mcpProjects = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_list_projects',
        'arguments' => [],
    ]);
    assert_same(1, $mcpProjects['json']['result']['structuredContent']['count'], 'lists projects through MCP');
    assert_same('Alpha', $mcpProjects['json']['result']['structuredContent']['projects'][0]['name'], 'wraps MCP projects in an object');
    $outsiderMcpProjects = mcp_request($base, $outsiderToken, 'tools/call', [
        'name' => 'manycontext_list_projects',
        'arguments' => [],
    ]);
    assert_same(0, $outsiderMcpProjects['json']['result']['structuredContent']['count'], 'scopes MCP projects to the key owner');

    $mcpCompleteTask = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_get_task',
        'arguments' => ['project' => 'Alpha', 'id' => 1, 'actor' => 'Tester', 'markConversationRead' => true],
    ]);
    assert_same(200, $mcpCompleteTask['status'], 'gets complete task through MCP');
    assert_true(count($mcpCompleteTask['json']['result']['structuredContent']['conversation']) >= 4, 'returns task conversation through MCP');
    assert_true(str_starts_with($mcpCompleteTask['json']['result']['structuredContent']['attachments'][0]['downloadUrl'], $base), 'returns attachment download URL through MCP');
    assert_true(!array_key_exists('path', $mcpCompleteTask['json']['result']['structuredContent']['attachments'][0]), 'omits local attachment path from MCP task');
    assert_true(is_array($mcpCompleteTask['json']['result']['structuredContent']['subTasks']), 'returns task subTasks through MCP');
    assert_same([0, 1, 2], array_column($mcpCompleteTask['json']['result']['structuredContent']['subTasks'], 'order'), 'keeps subTask order through MCP');

    $mcpComment = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_write_task_comment',
        'arguments' => ['project' => 'Alpha', 'taskId' => 1, 'actor' => 'MCP Bot', 'content' => 'Comentário criado pelo MCP'],
    ]);
    assert_same('Tester', $mcpComment['json']['result']['structuredContent']['comment']['userName'], 'binds MCP comments to the key owner');
    $mcpEditedComment = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_write_task_comment',
        'arguments' => [
            'project' => 'Alpha',
            'taskId' => 1,
            'commentId' => $mcpComment['json']['result']['structuredContent']['comment']['id'],
            'actor' => 'MCP Bot',
            'content' => 'Comentário editado pelo MCP',
        ],
    ]);
    assert_same('Comentário editado pelo MCP', $mcpEditedComment['json']['result']['structuredContent']['comment']['content'], 'updates own task comment through MCP');

    $mcpFolder = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_write_document_folder',
        'arguments' => ['project' => 'Alpha', 'name' => 'MCP Docs'],
    ]);
    assert_same('folder', $mcpFolder['json']['result']['structuredContent']['document']['type'], 'creates document folder through MCP');
    $mcpFolderId = $mcpFolder['json']['result']['structuredContent']['document']['id'];

    $mcpFile = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_write_document_file',
        'arguments' => ['project' => 'Alpha', 'parentId' => $mcpFolderId, 'name' => 'agent-notes.md', 'content' => "# Agent notes\n\nMCP document content."],
    ]);
    assert_same('file', $mcpFile['json']['result']['structuredContent']['document']['type'], 'creates document file through MCP');
    $mcpFileId = $mcpFile['json']['result']['structuredContent']['document']['id'];

    $mcpDocList = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_list_documents',
        'arguments' => ['project' => 'Alpha', 'parentId' => $mcpFolderId],
    ]);
    assert_same(1, $mcpDocList['json']['result']['structuredContent']['count'], 'lists folder contents through MCP');

    $mcpReadFile = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_read_document',
        'arguments' => ['project' => 'Alpha', 'id' => $mcpFileId],
    ]);
    assert_same('text', $mcpReadFile['json']['result']['structuredContent']['document']['contentKind'], 'reads markdown document content through MCP');
    assert_true(str_contains($mcpReadFile['json']['result']['structuredContent']['document']['content'], 'MCP document content'), 'returns markdown content through MCP');

    $mcpUpdatedFile = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_write_document_file',
        'arguments' => ['project' => 'Alpha', 'id' => $mcpFileId, 'content' => 'Updated from MCP.'],
    ]);
    assert_true(str_contains($mcpUpdatedFile['json']['result']['structuredContent']['document']['content'], 'Updated from MCP'), 'updates document file through MCP');

    $mcpBinary = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_write_document_file',
        'arguments' => ['project' => 'Alpha', 'parentId' => $mcpFolderId, 'name' => 'payload.bin', 'content' => 'binary-ish'],
    ]);
    $mcpBinaryRead = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_read_document',
        'arguments' => ['project' => 'Alpha', 'id' => $mcpBinary['json']['result']['structuredContent']['document']['id']],
    ]);
    assert_same('binary', $mcpBinaryRead['json']['result']['structuredContent']['document']['contentKind'], 'returns binary document as download link through MCP');
    assert_true(!array_key_exists('content', $mcpBinaryRead['json']['result']['structuredContent']['document']), 'omits binary document content through MCP');

    $mcpDeletedFile = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_delete_document_file',
        'arguments' => ['project' => 'Alpha', 'id' => $mcpFileId],
    ]);
    assert_true($mcpDeletedFile['json']['result']['structuredContent']['deleted'], 'deletes document file through MCP');
    $mcpDeletedFolder = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_delete_document_folder',
        'arguments' => ['project' => 'Alpha', 'id' => $mcpFolderId],
    ]);
    assert_true($mcpDeletedFolder['json']['result']['structuredContent']['deleted'], 'deletes document folder through MCP');

    $mcpCreated = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_create_task',
        'arguments' => ['project' => 'Alpha', 'title' => 'MCP task', 'desc' => 'Created by JSON-RPC'],
    ]);
    assert_same(200, $mcpCreated['status'], 'creates task through MCP');
    assert_same(2, $mcpCreated['json']['result']['structuredContent']['id'], 'returns structured MCP create result');

    $mcpSemanticSearch = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_list_tasks',
        'arguments' => ['project' => 'Alpha', 'search' => 'JSON-RPC'],
    ]);
    assert_true(str_contains($mcpSemanticSearch['json']['error']['message'], 'Chave da AIVAX'), 'routes MCP task search through semantic reranking');
    assert_same(503, json_request('GET', $base . '/api/projects/Alpha/tasks?search=JSON-RPC')['status'], 'routes UI task search through semantic reranking');
    $mcpList = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_list_tasks',
        'arguments' => ['project' => 'Alpha', 'status' => 'todo'],
    ]);
    assert_same(1, $mcpList['json']['result']['structuredContent']['count'], 'filters tasks through MCP');
    assert_same('MCP task', $mcpList['json']['result']['structuredContent']['tasks'][0]['title'], 'wraps MCP tasks in an object');
    assert_same([], $mcpList['json']['result']['structuredContent']['tasks'][0]['subTasks'] ?? [], 'returns empty subTasks for tasks without subTasks through MCP');

    $mcpUpdated = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_update_task',
        'arguments' => ['project' => 'Alpha', 'id' => 2, 'status' => 'done'],
    ]);
    assert_same('done', $mcpUpdated['json']['result']['structuredContent']['status'], 'updates task through MCP');

    $mcpSubTaskUpdate = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_update_task',
        'arguments' => ['project' => 'Alpha', 'id' => 2, 'subTasks' => [['title' => 'Etapa MCP', 'description' => 'via MCP', 'order' => 0, 'completed' => false]]],
    ]);
    assert_same('Etapa MCP', $mcpSubTaskUpdate['json']['result']['structuredContent']['subTasks'][0]['title'], 'updates task subTasks through MCP');

    $mcpDeleted = mcp_request($base, $token, 'tools/call', [
        'name' => 'manycontext_delete_task',
        'arguments' => ['project' => 'Alpha', 'id' => 2],
    ]);
    assert_true($mcpDeleted['json']['result']['structuredContent']['deleted'], 'deletes task through MCP');

    $sandboxSubTasks = json_request('POST', $base . '/api/projects/SubTaskSandbox/tasks', [
        'title' => 'Tarefa com subtarefas',
        'subTasks' => [
            ['title' => 'Segunda', 'description' => 'B', 'order' => 1, 'completed' => true],
            ['title' => 'Primeira', 'description' => 'A', 'order' => 0, 'completed' => false],
        ],
    ]);
    assert_same(201, $sandboxSubTasks['status'], 'creates task with subTasks');
    assert_same([0, 1], array_column($sandboxSubTasks['json']['subTasks'], 'order'), 'orders subTasks on create');
    assert_same('Primeira', $sandboxSubTasks['json']['subTasks'][0]['title'], 'keeps subTask titles on create');
    $sandboxCleanup = json_request('DELETE', $base . '/api/projects/SubTaskSandbox');
    assert_true($sandboxCleanup['json']['deleted'], 'deletes subTask sandbox project');

    echo "All PHP migration tests passed.\n";
} finally {
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_terminate($process);
    proc_close($process);
    remove_dir($temp);
}

function json_request(string $method, string $url, ?array $body = null): array
{
    $headers = ['Content-Type: application/json'];
    return decode_json(http_request($method, $url, $headers, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function mcp_request(string $base, string $token, string $method, array $params = [], bool $auth = true, bool $queryToken = false): array
{
    $headers = ['Content-Type: application/json', 'MCP-Protocol-Version: 2025-06-18'];
    $url = $base . '/mcp';
    if ($auth) {
        if ($queryToken) {
            $url .= '?token=' . rawurlencode($token);
        } else {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
    }

    return decode_json(http_request('POST', $url, $headers, json_encode([
        'jsonrpc' => '2.0',
        'id' => random_int(1, 100000),
        'method' => $method,
        'params' => $params + [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2025-06-18',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'manycontext-php-tests', 'version' => '0.1.0'],
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

function upload_request(string $url, array $paths): array
{
    global $sessionCookie;
    $post = [];
    foreach ($paths as $index => $path) {
        $post['files[' . $index . ']'] = new CURLFile($path);
    }

    $ch = curl_init($url);
    $headers = $sessionCookie !== '' ? ['Cookie: ' . $sessionCookie] : [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        fail(curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode(substr($raw, $headerSize), true)];
}

function document_upload_request(string $url, int $parentId, array $files): array
{
    global $sessionCookie;
    $post = ['parentId' => (string)$parentId];
    foreach ($files as $index => $file) {
        $post['files[' . $index . ']'] = new CURLFile($file['path']);
        $post['paths[' . $index . ']'] = $file['relative'];
    }

    $ch = curl_init($url);
    $headers = $sessionCookie !== '' ? ['Cookie: ' . $sessionCookie] : [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        fail(curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode(substr($raw, $headerSize), true)];
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    global $sessionCookie;
    if ($sessionCookie !== '' && !array_filter($headers, fn (string $header) => str_starts_with(strtolower($header), 'cookie:'))) {
        $headers[] = 'Cookie: ' . $sessionCookie;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        fail(curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

function session_cookie_from_headers(string $headers): string
{
    if (!preg_match('/^Set-Cookie:\s*([^;]+)/mi', $headers, $match)) {
        fail('Login did not return a session cookie.');
    }

    return trim($match[1]);
}

function decode_json(array $response): array
{
    $response['json'] = json_decode($response['body'], true);
    return $response;
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) {
        fail($errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr(strrchr($name, ':'), 1);
}

function wait_for_server(int $port): void
{
    $deadline = microtime(true) + 5;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket) {
            fclose($socket);
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    fail('Timed out waiting for PHP server.');
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "Test failed: $message\n");
    exit(1);
}

function remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $items = array_values(array_filter(scandir($dir) ?: [], fn (string $item) => $item !== '.' && $item !== '..'));
        if (count($items) === 0) {
            break;
        }
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? remove_dir($path) : unlink($path);
        }
        usleep(100000);
    }
    @rmdir($dir);
}
