<?php

if (!defined('DOKU_INC')) die();

/** Sync page tasks to Todoist and display incoming project tasks. */
class FokusButler_Module_Todoist_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'todoist';
    private $apiError = '';

    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('todoist')) return;
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handlePageWrite');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'disableTodosListCache');
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'addToolbarButton');
        $controller->register_hook('JS_CACHE_USE', 'BEFORE', $this, 'addScriptDependency');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'injectSecurityToken');
    }

    /** DokuWiki exposes the CSRF token to PHP, so pass it into JSINFO for AJAX. */
    public function injectSecurityToken(Doku_Event $event, $param)
    {
        if (empty($event->data['script']) || !is_array($event->data['script'])) return;
        $payload = json_encode(
            ['token' => getSecurityToken()],
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($payload === false) return;
        foreach ($event->data['script'] as &$scriptTag) {
            if (isset($scriptTag['_data']) && strpos($scriptTag['_data'], 'var JSINFO') !== false) {
                $scriptTag['_data'] .= 'JSINFO.todoistcompanion=' . $payload . ';';
                break;
            }
        }
        unset($scriptTag);
    }

    public function addScriptDependency(Doku_Event $event, $param)
    {
        $cache = $event->data;
        if (!is_object($cache)) return;
        if (!isset($cache->depends) || !is_array($cache->depends)) $cache->depends = [];
        if (!isset($cache->depends['files']) || !is_array($cache->depends['files'])) $cache->depends['files'] = [];
        $cache->depends['files'][] = __DIR__ . '/script.js';
    }

    public function addToolbarButton(Doku_Event $event, $param)
    {
        $event->data[] = [
            'type' => 'fokusbutlertodoist',
            'title' => $this->getLang('toolbar_title'),
            'icon' => '../../plugins/fokusbutler/modules/todoist/images/check-square-duotone.svg',
        ];
    }

    public function handlePageWrite(Doku_Event $event, $param)
    {
        if (!isset($event->data[0][1]) || !is_string($event->data[0][1])) return;

        $namespace = $event->data[1] ?? false;
        $page = $event->data[2] ?? '';
        if (!is_string($page) || $page === '') return;
        $pageId = ($namespace && $namespace !== false) ? $namespace . ':' . $page : $page;
        $this->syncPageTasks($pageId, $event->data[0][1]);
    }

    /** Keep the aggregate TODO list fresh when any page containing it is opened. */
    public function disableTodosListCache(Doku_Event $event, $param)
    {
        $cache = $event->data;
        if (!is_object($cache) || ($cache->mode ?? '') !== 'xhtml' || empty($cache->page)) return;
        if (!p_get_metadata($cache->page, 'todoistcompanion_todos')) return;
        $event->preventDefault();
        $event->stopPropagation();
        $event->result = false;
    }

    public function handleAjax(Doku_Event $event, $param)
    {
        if (!in_array($event->data, ['fokusbutler_todoist_complete', 'fokusbutler_todoist_status'], true)) return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        global $INPUT;
        $taskKey = $INPUT->post->str('task');
        $pageId = cleanID($INPUT->post->str('page'));
        if (strpos($taskKey, 'todoist-') === 0) {
            $this->handleImportedTask($taskKey, $pageId, $event->data);
            return;
        }
        if (!$this->validTaskKey($taskKey) || $pageId === '') {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid page task.']);
            return;
        }

        if ($event->data === 'fokusbutler_todoist_status') {
            if (auth_quickaclcheck($pageId) < AUTH_READ) {
                http_response_code(403);
                echo json_encode(['error' => 'You cannot view this page task.']);
                return;
            }
            $this->syncTodoistCompletion();
            $record = $this->getTaskRecord($taskKey);
            if (!is_array($record) || ($record['page'] ?? '') !== $pageId) {
                echo json_encode(['ok' => true, 'synced' => false, 'completed' => false, 'canComplete' => false]);
                return;
            }
            echo json_encode([
                'ok' => true,
                'synced' => !empty($record['todoist_id']),
                'completed' => !empty($record['completed']),
                'canComplete' => !empty($record['todoist_id']) && empty($record['completed']) && auth_quickaclcheck($pageId) >= AUTH_EDIT,
                'duePending' => !empty($record['due_datetime_local']) && ($record['due_status'] ?? '') !== 'synced',
            ]);
            return;
        }

        if (!checkSecurityToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Security token check failed.']);
            return;
        }
        if (auth_quickaclcheck($pageId) < AUTH_EDIT) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot complete this page task.']);
            return;
        }

        $tasks = $this->loadTasks();
        if (!isset($tasks[$taskKey]) || ($tasks[$taskKey]['page'] ?? '') !== $pageId) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found for this page.']);
            return;
        }
        if (!empty($tasks[$taskKey]['completed'])) {
            echo json_encode(['ok' => true, 'completed' => true]);
            return;
        }

        $taskId = (string)($tasks[$taskKey]['todoist_id'] ?? '');
        if ($taskId === '' || !$this->closeTodoistTask($taskId)) {
            http_response_code(502);
            echo json_encode(['error' => $this->apiError ?: 'Todoist could not complete the task.']);
            return;
        }

        $tasks[$taskKey]['completed'] = true;
        $tasks[$taskKey]['completed_at'] = time();
        $this->saveTasks($tasks);
        echo json_encode(['ok' => true, 'completed' => true]);
    }

    private function syncPageTasks($pageId, $source)
    {
        preg_match_all('/\{\{todoistcompanion>(.*?)\}\}/s', $source, $matches, PREG_SET_ORDER);
        if (!$matches) return;

        $tasks = $this->loadTasks();
        foreach ($matches as $match) {
            $parsed = $this->parseTaskSyntax($match[1]);
            if (!$parsed) continue;
            [$key, $title, $dueLocal, $timezone] = $parsed;
            if (isset($tasks[$key]) && ($tasks[$key]['page'] ?? '') === $pageId) {
                $tasks[$key]['title'] = $title;
                $tasks[$key]['updated_at'] = time();
                if (empty($tasks[$key]['todoist_id']) && $this->apiToken() !== '') {
                    $result = $this->createTodoistTask($key, $pageId, $title, $dueLocal, $timezone);
                    if ($result) {
                        $tasks[$key]['todoist_id'] = $result['id'];
                        $tasks[$key]['status'] = 'synced';
                        if ($dueLocal !== null) {
                            $tasks[$key]['due_datetime_local'] = $dueLocal;
                            $tasks[$key]['due_timezone'] = $timezone;
                            $tasks[$key]['due_status'] = !empty($result['due_set']) ? 'synced' : 'pending';
                        }
                    }
                }
                if ($dueLocal !== null) $this->syncTaskDueDate($tasks[$key], $dueLocal, $timezone);
                continue;
            }
            // Avoid attaching an ID collision to an unrelated page/task.
            if (isset($tasks[$key])) continue;

            $result = $this->apiToken() !== '' ? $this->createTodoistTask($key, $pageId, $title, $dueLocal, $timezone) : null;
            $tasks[$key] = [
                'page' => $pageId,
                'title' => $title,
                'todoist_id' => $result['id'] ?? '',
                'completed' => false,
                'status' => $result ? 'synced' : 'pending',
                'updated_at' => time(),
            ];
            if ($dueLocal !== null) {
                $tasks[$key]['due_datetime_local'] = $dueLocal;
                $tasks[$key]['due_timezone'] = $timezone;
                $tasks[$key]['due_status'] = !empty($result['due_set']) ? 'synced' : 'pending';
            }
        }
        $this->saveTasks($tasks);
    }

    private function parseTaskSyntax($body)
    {
        if (!preg_match('/^(.*?)\|id=((?:[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|[a-z0-9]{8}))(?:\|(?:due|reminder)=(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})\|tz=([A-Za-z0-9_+.-]+(?:\/[A-Za-z0-9_+.-]+)*))?$/is', trim($body), $parts)) return null;
        $title = trim($parts[1]);
        $key = strtolower($parts[2]);
        if ($title === '' || !$this->validTaskKey($key)) return null;
        $dueLocal = $parts[3] ?? null;
        $timezone = $parts[4] ?? null;
        if ($dueLocal !== null && !$this->validDueDateTime($dueLocal, $timezone)) return null;
        return [$key, $title, $dueLocal, $timezone];
    }

    private function validDueDateTime($dateTime, $timezone)
    {
        if (!is_string($dateTime) || !is_string($timezone) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dateTime)) return false;
        try {
            $zone = new DateTimeZone($timezone);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $dateTime, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d\\TH:i') === $dateTime;
        } catch (Exception $error) {
            return false;
        }
    }

    private function todoistDueDateTime($dateTime, $timezone)
    {
        if (!$this->validDueDateTime($dateTime, $timezone)) return null;
        $zone = new DateTimeZone($timezone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $dateTime, $zone);
        return $date ? $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z') : null;
    }

    private function syncTaskDueDate(array &$task, $dateTime, $timezone)
    {
        if (!$this->validDueDateTime($dateTime, $timezone)) return;
        $sameDue = ($task['due_datetime_local'] ?? '') === $dateTime && ($task['due_timezone'] ?? '') === $timezone;
        $task['due_datetime_local'] = $dateTime;
        $task['due_timezone'] = $timezone;
        if ($sameDue && ($task['due_status'] ?? '') === 'synced') return;
        $task['due_status'] = 'pending';
        if (empty($task['todoist_id'])) return;

        $dueDateTime = $this->todoistDueDateTime($dateTime, $timezone);
        if ($dueDateTime === null) return;
        $result = $this->request('POST', '/tasks/' . rawurlencode((string)$task['todoist_id']), ['due_datetime' => $dueDateTime]);
        if (is_array($result) && (!empty($result['id']) || !empty($result['ok']))) $task['due_status'] = 'synced';
    }

    private function validTaskKey($key)
    {
        return is_string($key) && (
            preg_match('/^[a-z0-9]{8}$/i', $key) === 1 ||
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $key) === 1
        );
    }

    private function createTodoistTask($key, $pageId, $title, $dueLocal = null, $timezone = null)
    {
        $projectId = $this->getOrCreateProject();
        if (!$projectId) return null;
        global $conf;
        $url = wl($pageId, [], true, '&');
        $payload = [
            'content' => $title,
            'description' => 'Wiki page: ' . $url . "\n\nDokuWiki task key: " . $key,
            'project_id' => $projectId,
        ];
        $dueSet = false;
        if ($dueLocal !== null) {
            $dueDateTime = $this->todoistDueDateTime($dueLocal, $timezone);
            if ($dueDateTime !== null) {
                $payload['due_datetime'] = $dueDateTime;
                $dueSet = true;
            }
        }
        $result = $this->request('POST', '/tasks', $payload);
        if (!is_array($result) || empty($result['id'])) return null;
        return ['id' => (string)$result['id'], 'due_set' => $dueSet];
    }

    private function getOrCreateProject()
    {
        $state = $this->loadProject();
        if (!empty($state['id']) && ($state['name'] ?? '') === $this->projectName()) return (string)$state['id'];

        $projects = $this->request('GET', '/projects');
        if (!is_array($projects)) return null;
        foreach (($projects['results'] ?? $projects) as $project) {
            if (is_array($project) && strcasecmp((string)($project['name'] ?? ''), $this->projectName()) === 0) {
                $this->saveProject(['id' => (string)$project['id'], 'name' => $this->projectName()]);
                return (string)$project['id'];
            }
        }

        $created = $this->request('POST', '/projects', ['name' => $this->projectName()]);
        if (!is_array($created) || empty($created['id'])) return null;
        $this->saveProject(['id' => (string)$created['id'], 'name' => $this->projectName()]);
        return (string)$created['id'];
    }

    private function closeTodoistTask($taskId)
    {
        return $this->request('POST', '/tasks/' . rawurlencode($taskId) . '/close') !== null;
    }

    protected function request($method, $path, $payload = null)
    {
        $token = $this->apiToken();
        if ($token === '') return null;
        $url = 'https://api.todoist.com/api/v1' . $path;
        $headers = "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\n";
        $options = ['http' => [
            'method' => $method,
            'header' => $headers,
            'timeout' => 8,
            'ignore_errors' => true,
        ]];
        if ($payload !== null) {
            $options['http']['header'] .= "Content-Type: application/json\r\n";
            $options['http']['content'] = json_encode($payload);
        }
        $response = @file_get_contents($url, false, stream_context_create($options));
        if ($response === false) {
            $this->apiError = 'Could not reach Todoist. Check outbound HTTPS access from the DokuWiki server.';
            return null;
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) $status = (int)$match[1];
        }
        if ($status < 200 || $status >= 300) {
            $this->apiError = 'Todoist returned HTTP ' . $status . '.';
            return null;
        }
        if ($response === '') return ['ok' => true];
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function projectName()
    {
        $name = trim((string)$this->getConf('project_name'));
        return $name === '' ? 'Wiki' : $name;
    }

    private function apiToken()
    {
        $secretFile = '/run/secrets/todoist_api_token';
        if (is_readable($secretFile)) {
            $secretToken = @file_get_contents($secretFile);
            if (is_string($secretToken) && trim($secretToken) !== '') return trim($secretToken);
        }

        $environmentToken = getenv('TODOIST_API_TOKEN');
        if (is_string($environmentToken) && trim($environmentToken) !== '') return trim($environmentToken);
        return trim((string)$this->getConf('api_token'));
    }

    private function storageDirectory()
    {
        global $conf;
        return rtrim($conf['metadir'], '/\\') . '/fokusbutler/todoist';
    }

    private function loadTasks()
    {
        $file = $this->storageDirectory() . '/tasks.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** Return the local task status used by the syntax renderer. */
    public function getTaskRecord($key)
    {
        if (!$this->validTaskKey($key)) return null;
        $tasks = $this->loadTasks();
        return $tasks[$key] ?? null;
    }

    /** Scan current page sources and attach known Todoist completion state. */
    public function getAllWikiTasks($includeRestricted = false)
    {
        global $conf;
        $pages = [];
        search($pages, $conf['datadir'], 'search_allpages', ['skipacl' => true]);
        $pageIds = array_values(array_unique(array_filter(array_column($pages, 'id'), 'is_string')));
        sort($pageIds, SORT_NATURAL | SORT_FLAG_CASE);
        $stored = $this->loadTasks();
        $result = [];

        foreach ($pageIds as $pageId) {
            if (!$includeRestricted && auth_quickaclcheck($pageId) < AUTH_READ) continue;
            $source = rawWiki($pageId);
            if (!is_string($source) || !preg_match_all('/\{\{todoistcompanion>(.*?)\}\}/s', $source, $matches, PREG_SET_ORDER)) continue;
            foreach ($matches as $match) {
                $parsed = $this->parseTaskSyntax($match[1]);
                if (!$parsed) continue;
                [$key, $title, $dueLocal] = $parsed;
                $record = $stored[$key] ?? [];
                if (!empty($record['page']) && $record['page'] !== $pageId) $record = [];
                $result[] = [
                    'key' => $key,
                    'title' => $title,
                    'due' => $dueLocal ?? '',
                    'page' => $pageId,
                    'todoist_id' => (string)($record['todoist_id'] ?? ''),
                    'completed' => !empty($record['completed']),
                    'status' => (string)($record['status'] ?? 'pending'),
                ];
            }
        }
        return $result;
    }

    /** Imported tasks inherit the ACL of a real aggregate page and require login. */
    public function canReadImportedTasks($page)
    {
        global $INPUT;
        return FokusButler_Modules::enabled('todoist') && $INPUT->server->str('REMOTE_USER') !== ''
            && $page !== '' && auth_quickaclcheck($page) >= AUTH_READ
            && strpos(rawWiki($page), '~~TODOS~~') !== false;
    }

    /** Read only the configured project's active tasks. Never create projects on a read. */
    public function getProjectTasks($page)
    {
        if (!$this->canReadImportedTasks($page)) return ['tasks' => [], 'error' => ''];
        $cacheFile = $this->storageDirectory() . '/incoming.json';
        $cache = is_file($cacheFile) ? json_decode((string)@file_get_contents($cacheFile), true) : [];
        $cache = is_array($cache) ? $cache : [];
        if (($cache['project'] ?? '') !== $this->projectName()) $cache = [];
        if (time() - ($cache['attempted_at'] ?? 0) >= 20) {
            // Serialize refreshes across simultaneous panes and requests.
            io_mkdir_p($this->storageDirectory());
            $lock = @fopen($this->storageDirectory() . '/incoming.lock', 'c');
            if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                $this->apiError = '';
                $projects = $this->fetchCollection('/projects');
                $projectId = null;
                foreach ($projects ?? [] as $project) {
                    if (strcasecmp((string)($project['name'] ?? ''), $this->projectName()) === 0) {
                        $projectId = (string)$project['id'];
                        break;
                    }
                }
                $remote = $projects === null ? null : ($projectId === null ? [] : $this->fetchCollection('/tasks?project_id=' . rawurlencode($projectId)));
                if ($remote !== null) {
                    $active = array_values(array_filter($remote, static function ($task) use ($projectId) {
                        return is_array($task) && !empty($task['id']) && (string)($task['project_id'] ?? '') === $projectId;
                    }));
                    $this->syncTodoistCompletion($projectId, $active);
                    $cache = ['project' => $this->projectName(), 'tasks' => $active, 'synced_at' => time()];
                }
                $cache['attempted_at'] = time();
                $cache['project'] = $this->projectName();
                $cache['error'] = $remote === null ? ($this->apiError ?: 'Todoist connection is not configured.') : '';
                $this->writeJson('incoming.json', $cache);
                flock($lock, LOCK_UN);
            }
            if ($lock) fclose($lock);
        }
        $completion = $this->syncTodoistCompletion();
        // Exclude every wiki-linked ID, including tasks on restricted pages.
        $linkedIds = array_column($this->loadTasks(), 'todoist_id');
        $tasks = [];
        $projectId = (string)($completion['project_id'] ?? '');
        $byId = [];
        foreach ($cache['tasks'] ?? [] as $task) {
            if (is_array($task) && !empty($task['id'])) {
                $task['is_completed'] = false;
                $byId[(string)$task['id']] = $task;
            }
        }
        foreach ($completion['completed_tasks'] ?? [] as $task) {
            if (!is_array($task) || empty($task['id']) || (string)($task['project_id'] ?? '') !== $projectId) continue;
            $id = (string)$task['id'];
            if (!isset($byId[$id])) {
                $task['is_completed'] = true;
                $byId[$id] = $task;
            }
        }
        foreach ($byId as $task) {
            if (in_array((string)$task['id'], $linkedIds, true)) continue;
            $task['key'] = 'todoist-' . hash('sha256', (string)$task['id']);
            $tasks[$task['key']] = $task;
        }
        $error = (string)($cache['error'] ?? '');
        if ($error === '') $error = (string)($completion['error'] ?? '');
        return ['tasks' => $tasks, 'error' => $error];
    }

    /** Refresh completion state from active tasks and recent completed-task history. */
    private function syncTodoistCompletion($projectId = null, $activeTasks = null)
    {
        $cache = $this->loadCompletionState();
        if ($projectId === null) {
            if (($cache['project_name'] ?? '') === $this->projectName()) $projectId = (string)($cache['project_id'] ?? '');
            if ($projectId === '') {
                $saved = $this->loadProject();
                if (($saved['name'] ?? '') === $this->projectName()) $projectId = (string)($saved['id'] ?? '');
            }
        }
        if ($projectId !== null && $projectId !== '' && ($cache['project_id'] ?? '') !== $projectId) $cache = [];
        if (time() - (int)($cache['attempted_at'] ?? 0) < 20) {
            $this->applyTodoistCompletion($cache['states'] ?? []);
            return $cache;
        }

        io_mkdir_p($this->storageDirectory());
        $lock = @fopen($this->storageDirectory() . '/completion.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            $this->applyTodoistCompletion($cache['states'] ?? []);
            return $cache;
        }
        try {
            // Another request may have refreshed while this one waited for the lock.
            $latest = $this->loadCompletionState();
            if ($projectId !== null && $projectId !== '' && ($latest['project_id'] ?? '') !== $projectId) $latest = [];
            if (time() - (int)($latest['attempted_at'] ?? 0) < 20) {
                $this->applyTodoistCompletion($latest['states'] ?? []);
                return $latest;
            }
            $cache = $latest;
            if ($projectId === null || $projectId === '') {
                $projects = $this->fetchCollection('/projects');
                if ($projects !== null) {
                    foreach ($projects as $project) {
                        if (strcasecmp((string)($project['name'] ?? ''), $this->projectName()) === 0) {
                            $projectId = (string)$project['id'];
                            break;
                        }
                    }
                } else {
                    $projectId = null;
                }
            }
            if ($projectId === null || $projectId === '') {
                $cache['attempted_at'] = time();
                $cache['project_name'] = $this->projectName();
                $cache['error'] = $projectId === null ? ($this->apiError ?: 'Could not load the Todoist project.') : '';
                $this->writeJson('completion.json', $cache);
                $this->applyTodoistCompletion($cache['states'] ?? []);
                return $cache;
            }

            $this->apiError = '';
            if (!is_array($activeTasks)) $activeTasks = $this->fetchCollection('/tasks?project_id=' . rawurlencode($projectId));
            $lastSuccess = (int)($cache['synced_at'] ?? 0);
            $sinceTimestamp = $lastSuccess > 0 ? max(time() - 89 * 86400, $lastSuccess - 86400) : time() - 89 * 86400;
            $since = gmdate('Y-m-d\TH:i:s\Z', $sinceTimestamp);
            $until = gmdate('Y-m-d\TH:i:s\Z');
            $completed = $this->fetchCompletedTasks($projectId, $since, $until);
            if ($activeTasks === null || $completed === null) {
                $cache['attempted_at'] = time();
                $cache['project_id'] = (string)$projectId;
                $cache['project_name'] = $this->projectName();
                $cache['error'] = $this->apiError ?: 'Could not refresh Todoist task completion state.';
                $this->writeJson('completion.json', $cache);
                $this->applyTodoistCompletion($cache['states'] ?? []);
                return $cache;
            }

            $states = is_array($cache['states'] ?? null) ? $cache['states'] : [];
            $completedTasks = is_array($cache['completed_tasks'] ?? null) ? $cache['completed_tasks'] : [];
            $cutoff = time() - 89 * 86400;
            foreach ($completed as $task) {
                if (!is_array($task) || empty($task['id']) || (string)($task['project_id'] ?? '') !== (string)$projectId) continue;
                $id = (string)$task['id'];
                $states[$id] = ['completed' => true, 'updated_at' => time()];
                $task['is_completed'] = true;
                $completedTasks[$id] = $task;
            }
            foreach ($activeTasks as $task) {
                if (!is_array($task) || empty($task['id']) || (string)($task['project_id'] ?? '') !== (string)$projectId) continue;
                $id = (string)$task['id'];
                // Active wins for recurring tasks and tasks reopened in Todoist.
                $states[$id] = ['completed' => false, 'updated_at' => time()];
                unset($completedTasks[$id]);
            }
            foreach ($completedTasks as $id => $task) {
                $completedAt = strtotime((string)($task['completed_at'] ?? ''));
                if ($completedAt !== false && $completedAt < $cutoff) unset($completedTasks[$id]);
            }
            $cache = [
                'project_id' => (string)$projectId,
                'project_name' => $this->projectName(),
                'states' => $states,
                'completed_tasks' => $completedTasks,
                'attempted_at' => time(),
                'synced_at' => time(),
                'error' => '',
            ];
            $this->writeJson('completion.json', $cache);
            $this->applyTodoistCompletion($states);
            return $cache;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function fetchCompletedTasks($projectId, $since, $until)
    {
        $all = [];
        $cursor = null;
        $seen = [];
        do {
            $path = '/tasks/completed/by_completion_date?project_id=' . rawurlencode($projectId)
                . '&since=' . rawurlencode($since) . '&until=' . rawurlencode($until) . '&limit=200';
            if ($cursor !== null) $path .= '&cursor=' . rawurlencode($cursor);
            $response = $this->request('GET', $path);
            if (!is_array($response) || !isset($response['items']) || !is_array($response['items'])) return null;
            $all = array_merge($all, $response['items']);
            $cursor = $response['next_cursor'] ?? null;
            if ($cursor !== null) {
                if (!is_string($cursor) || isset($seen[$cursor]) || count($seen) >= 100) return null;
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        return $all;
    }

    private function loadCompletionState()
    {
        $file = $this->storageDirectory() . '/completion.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function applyTodoistCompletion(array $states)
    {
        if (!$states) return;
        $tasks = $this->loadTasks();
        $changed = false;
        foreach ($tasks as &$task) {
            $id = (string)($task['todoist_id'] ?? '');
            if ($id === '' || !isset($states[$id])) continue;
            $completed = !empty($states[$id]['completed']);
            if (!array_key_exists('completed', $task) || (bool)$task['completed'] !== $completed) {
                $task['completed'] = $completed;
                if ($completed) $task['completed_at'] = (int)($states[$id]['updated_at'] ?? time());
                else unset($task['completed_at']);
                $changed = true;
            }
        }
        unset($task);
        if ($changed) $this->saveTasks($tasks);
    }

    /** Follow every cursor; failed/partial responses must never replace a good snapshot. */
    private function fetchCollection($path)
    {
        $all = [];
        $cursor = null;
        $seen = [];
        do {
            $url = $path . ($cursor === null ? '' : (strpos($path, '?') === false ? '?' : '&') . 'cursor=' . rawurlencode($cursor));
            $response = $this->request('GET', $url);
            if (!is_array($response) || !isset($response['results']) || !is_array($response['results'])) return null;
            $all = array_merge($all, $response['results']);
            $cursor = $response['next_cursor'] ?? null;
            if ($cursor !== null) {
                if (!is_string($cursor) || isset($seen[$cursor]) || count($seen) >= 100) return null;
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        return $all;
    }

    private function handleImportedTask($key, $page, $call)
    {
        if (!$this->canReadImportedTasks($page)) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot view this Todoist task.']);
            return;
        }
        $snapshot = $this->getProjectTasks($page);
        $task = $snapshot['tasks'][$key] ?? null;
        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Task is no longer available. Reload the list.']);
            return;
        }
        if ($call === 'fokusbutler_todoist_status') {
            echo json_encode(['ok' => true, 'synced' => true, 'completed' => !empty($task['is_completed']), 'canComplete' => empty($task['is_completed']) && auth_quickaclcheck($page) >= AUTH_EDIT]);
            return;
        }
        if (!checkSecurityToken() || auth_quickaclcheck($page) < AUTH_EDIT) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot complete this Todoist task.']);
            return;
        }
        $current = $this->request('GET', '/tasks/' . rawurlencode((string)$task['id']));
        if (!is_array($current) || !isset($current['project_id']) || (string)$current['project_id'] !== (string)$task['project_id'] || (!empty($current['is_completed']) || !empty($current['checked']))) {
            http_response_code(409);
            echo json_encode(['error' => 'Task changed in Todoist. Reload the list before completing it.']);
            return;
        }
        if (!$this->closeTodoistTask((string)$task['id'])) {
            http_response_code(502);
            echo json_encode(['error' => $this->apiError ?: 'Todoist could not complete the task.']);
            return;
        }
        // Refresh on next view, including recurring tasks with a new due date.
        @unlink($this->storageDirectory() . '/incoming.json');
        echo json_encode(['ok' => true, 'completed' => true]);
    }

    private function saveTasks($tasks)
    {
        return $this->writeJson('tasks.json', $tasks);
    }

    private function loadProject()
    {
        $file = $this->storageDirectory() . '/project.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function saveProject($project)
    {
        return $this->writeJson('project.json', $project);
    }

    private function writeJson($name, $data)
    {
        $dir = $this->storageDirectory();
        io_mkdir_p($dir);
        $tmp = $dir . '/' . $name . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
        return @rename($tmp, $dir . '/' . $name);
    }
}
