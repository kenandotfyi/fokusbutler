<?php

if (!defined('DOKU_INC')) die();

/** Sync page tasks to Todoist and display incoming project tasks. */
class FokusButler_Module_Todoist_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'todoist';
    private $apiError = '';
    private $apiStatus = 0;
    private $resolvedProject = [];

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
            'icon' => '../../plugins/fokusbutler/modules/todoist/icons/todoist.svg',
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
        if (!in_array($event->data, ['fokusbutler_todoist_complete', 'fokusbutler_todoist_status', 'fokusbutler_todoist_list'], true)) return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        global $INPUT;
        $pageId = cleanID($INPUT->post->str('page'));
        if ($event->data === 'fokusbutler_todoist_list') {
            if (!$this->canReadImportedTasks($pageId)) {
                http_response_code(403);
                echo json_encode(['error' => 'You cannot view Todoist tasks on this page.']);
                return;
            }
            $snapshot = $this->getProjectTasks($pageId);
            echo json_encode(['ok' => true, 'tasks' => array_values($snapshot['tasks']), 'error' => $snapshot['error'], 'available' => $snapshot['available'], 'stale' => $snapshot['stale'] ?? false, 'syncedAt' => $snapshot['syncedAt'] ?? 0, 'timezone' => date_default_timezone_get()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        $taskKey = $INPUT->post->str('task');
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
            $record = $this->refreshWikiTaskAvailability($taskKey, $record);
            $removed = false;
            if (!empty($record['remote_missing']) && checkSecurityToken() && auth_quickaclcheck($pageId) >= AUTH_EDIT) {
                try {
                    $this->removeUnavailableWikiTask($taskKey, $pageId);
                    $removed = true;
                } catch (RuntimeException $error) {
                    // Leave the unavailable reference in place and retry on the next editor view.
                }
            }
            echo json_encode([
                'ok' => true,
                'removed' => $removed,
                'synced' => !empty($record['todoist_id']),
                'completed' => !empty($record['completed']),
                'unavailable' => !empty($record['remote_missing']),
                'canComplete' => !empty($record['todoist_id']) && empty($record['completed']) && empty($record['remote_missing']) && auth_quickaclcheck($pageId) >= AUTH_EDIT,
                'duePending' => !empty($record['due_datetime_local']) && ($record['due_status'] ?? '') !== 'synced',
            ]);
            return;
        }

        if (!checkSecurityToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Security token check failed.']);
            return;
        }
        if (!$this->canManageImportedTasks($pageId)) {
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
            if ($taskId !== '' && $this->apiStatus === 404) {
                $tasks[$taskKey]['remote_missing'] = true;
                $tasks[$taskKey]['remote_checked_at'] = time();
                $this->saveTasks($tasks);
                try {
                    $this->removeUnavailableWikiTask($taskKey, $pageId);
                    echo json_encode(['ok' => true, 'removed' => true, 'unavailable' => true]);
                } catch (RuntimeException $error) {
                    http_response_code(409);
                    echo json_encode(['error' => 'This task was deleted or is no longer active in Todoist, but the page could not be cleaned up automatically. ' . $error->getMessage()]);
                }
                return;
            }
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
        $this->invalidateProjectSnapshot();
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
        $scope = $this->snapshotScope();
        if (($this->resolvedProject['scope'] ?? '') === $scope) return $this->resolvedProject['id'];
        $projectId = $this->findProject();
        if ($projectId === null) return null;
        if ($projectId !== '') {
            $this->saveProject(['id' => $projectId, 'name' => $this->projectName(), 'scope' => $this->snapshotScope()]);
            $this->resolvedProject = ['scope' => $scope, 'id' => $projectId];
            return $projectId;
        }

        $created = $this->request('POST', '/projects', ['name' => $this->projectName()]);
        if (!is_array($created) || empty($created['id'])) return null;
        $this->saveProject(['id' => (string)$created['id'], 'name' => $this->projectName(), 'scope' => $this->snapshotScope()]);
        $this->resolvedProject = ['scope' => $scope, 'id' => (string)$created['id']];
        return (string)$created['id'];
    }

    private function closeTodoistTask($taskId)
    {
        $closed = $this->request('POST', '/tasks/' . rawurlencode($taskId) . '/close') !== null;
        // Even a failed response may have reached Todoist; reconcile on retry.
        $this->invalidateProjectSnapshot($closed ? $taskId : null);
        return $closed;
    }

    /** Verify linked tasks occasionally; a Todoist 404 means the task is no longer active. */
    private function refreshWikiTaskAvailability($key, array $record)
    {
        if (empty($record['todoist_id']) || !empty($record['completed'])) return $record;
        if (time() - (int)($record['remote_checked_at'] ?? 0) < 300) return $record;
        $remote = $this->request('GET', '/tasks/' . rawurlencode((string)$record['todoist_id']));
        $tasks = $this->loadTasks();
        if (!isset($tasks[$key]) || ($tasks[$key]['todoist_id'] ?? '') !== $record['todoist_id']) return $record;
        $tasks[$key]['remote_checked_at'] = time();
        if (($remote === null && in_array($this->apiStatus, [404, 410], true)) || (is_array($remote) && !empty($remote['is_deleted']))) $tasks[$key]['remote_missing'] = true;
        elseif (is_array($remote)) {
            unset($tasks[$key]['remote_missing']);
            $tasks[$key]['completed'] = !empty($remote['checked']) || !empty($remote['is_completed']);
        }
        $this->saveTasks($tasks);
        return $tasks[$key];
    }

    /** Remove only this unavailable task's syntax from the wiki source. */
    private function removeUnavailableWikiTask($key, $pageId)
    {
        $tasks = $this->loadTasks();
        if (!isset($tasks[$key]) || ($tasks[$key]['page'] ?? '') !== $pageId || empty($tasks[$key]['remote_missing'])) {
            throw new RuntimeException('This task is not marked as unavailable in Todoist.', 404);
        }
        if (auth_quickaclcheck($pageId) < AUTH_EDIT || !page_exists($pageId)) throw new RuntimeException('You cannot edit this page.', 403);
        checklock($pageId);
        if (is_file(wikiLockFN($pageId))) throw new RuntimeException('This page is currently being edited. Save or close the editor, then try again.', 409);
        lock($pageId);
        try {
            $source = rawWiki($pageId);
            if (!is_string($source)) throw new RuntimeException('Could not read the wiki page.', 500);
            $removed = 0;
            $updated = preg_replace_callback('/\{\{todoistcompanion>(.*?)\}\}/s', function ($match) use ($key, &$removed) {
                $parsed = $this->parseTaskSyntax($match[1]);
                if ($parsed && $parsed[0] === $key) { $removed++; return ''; }
                return $match[0];
            }, $source);
            if (!is_string($updated) || !$removed) {
                unset($tasks[$key]);
                $this->saveTasks($tasks);
                return;
            }
            saveWikiText($pageId, $updated, 'Removed Todoist task that is no longer active');
            unset($tasks[$key]);
            $this->saveTasks($tasks);
        } finally {
            unlock($pageId);
        }
    }

    protected function request($method, $path, $payload = null)
    {
        $this->apiStatus = 0;
        $this->apiError = '';
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
        $this->apiStatus = $status;
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

    /** Imported tasks require login and either a readable aggregate page or admin access. */
    public function canReadImportedTasks($page)
    {
        global $INPUT;
        if (!FokusButler_Modules::enabled('todoist') || $INPUT->server->str('REMOTE_USER') === '') return false;
        if ($page === 'fokusbutler:admin') return auth_isadmin();
        return $page !== '' && auth_quickaclcheck($page) >= AUTH_READ
            && strpos(rawWiki($page), '~~TODOS~~') !== false;
    }

    private function canManageImportedTasks($page)
    {
        return $page === 'fokusbutler:admin' ? auth_isadmin() : auth_quickaclcheck($page) >= AUTH_EDIT;
    }

    /** One remote-backed snapshot powers both ~~TODOS~~ and the statusbar. */
    public function getProjectTasks($page)
    {
        if (!$this->canReadImportedTasks($page)) return ['tasks' => [], 'error' => '', 'available' => false];
        $snapshot = $this->refreshProjectSnapshot();
        $this->applyTodoistCompletion($this->snapshotStates($snapshot));
        $byId = [];
        foreach ($snapshot['completed_tasks'] ?? [] as $task) {
            $task['is_completed'] = true;
            $byId[(string)$task['id']] = $task;
        }
        foreach ($snapshot['tasks'] ?? [] as $task) {
            // Active wins for recurring and reopened tasks.
            $task['is_completed'] = false;
            $byId[(string)$task['id']] = $task;
        }
        $wikiById = [];
        foreach ($this->loadTasks() as $record) {
            if (!empty($record['todoist_id'])) $wikiById[(string)$record['todoist_id']] = $record;
        }
        $tasks = [];
        foreach ($byId as $id => $task) {
            // Local records can supply a backlink, never resurrect a remote task.
            $wikiPage = (string)($wikiById[$id]['page'] ?? '');
            if ($wikiPage !== '' && auth_quickaclcheck($wikiPage) >= AUTH_READ
                && (!function_exists('page_exists') || page_exists($wikiPage))) {
                $task['wiki_page'] = $wikiPage;
                $pageTitle = function_exists('p_get_first_heading') ? trim((string)p_get_first_heading($wikiPage, false)) : '';
                $task['wiki_page_title'] = $pageTitle !== '' ? $pageTitle : $wikiPage;
                $task['wiki_page_url'] = function_exists('wl') ? wl($wikiPage) : '';
            }
            $task['key'] = 'todoist-' . hash('sha256', (string)$id);
            $tasks[$task['key']] = $task;
        }
        $error = (string)($snapshot['error'] ?? '');
        if ($error === '') $error = (string)($snapshot['completion_error'] ?? '');
        if (empty($snapshot['available']) && $error === '') $error = 'Todoist tasks are still being refreshed.';
        return ['tasks' => $tasks, 'error' => $error,
            'available' => !empty($snapshot['available']),
            'stale' => !empty($snapshot['error']) || (int)($snapshot['synced_at'] ?? 0) < time() - 120,
            'syncedAt' => (int)($snapshot['synced_at'] ?? 0)];
    }

    /** Scope cached IDs and snapshots to the configured account and project. */
    private function snapshotScope()
    {
        return hash('sha256', $this->apiToken() . "\n" . $this->projectName());
    }

    private function loadProjectSnapshot()
    {
        $file = $this->storageDirectory() . '/incoming.json';
        $cache = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        // Ignore legacy history/completion caches: they contain synthetic/stale rows.
        return is_array($cache) && ($cache['version'] ?? 0) === 2
            && ($cache['scope'] ?? '') === $this->snapshotScope() ? $cache : [];
    }

    /** Resolve a unique active project, following every page; reads never create it. */
    private function findProject()
    {
        $projects = $this->fetchCollection('/projects');
        if ($projects === null) return null;
        $matches = [];
        foreach ($projects as $project) {
            if (!is_array($project) || empty($project['id']) || !empty($project['is_deleted']) || !empty($project['is_archived'])) continue;
            if (strcasecmp((string)($project['name'] ?? ''), $this->projectName()) === 0) $matches[(string)$project['id']] = true;
        }
        if (count($matches) > 1) {
            $this->apiError = 'Multiple Todoist projects are named ' . $this->projectName() . '. Give the intended project a unique name in the Todoist settings.';
            return null;
        }
        return $matches ? (string)array_key_first($matches) : '';
    }

    /** Keep last good data on failure; successful empty responses clear old tasks. */
    private function refreshProjectSnapshot()
    {
        $cache = $this->loadProjectSnapshot();
        if (time() - (int)($cache['attempted_at'] ?? 0) < 20) return $cache;
        io_mkdir_p($this->storageDirectory());
        $lock = @fopen($this->storageDirectory() . '/incoming.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return $cache;
        }
        try {
            // Re-read under the lock so parallel panes cannot publish older data.
            $cache = $this->loadProjectSnapshot();
            if (time() - (int)($cache['attempted_at'] ?? 0) < 20) return $cache;
            $this->apiError = '';
            $projectId = $this->findProject();
            $active = $projectId === null ? null : ($projectId === '' ? [] : $this->fetchCollection('/tasks?project_id=' . rawurlencode($projectId)));
            if ($active === null) {
                $cache['error'] = $this->apiError ?: 'Could not refresh the Todoist project. Showing the last successful snapshot.';
            } else {
                if (($cache['project_id'] ?? '') !== $projectId) $cache = [];
                $cache['tasks'] = $this->projectRows($active, $projectId, false);
                $cache['project_id'] = $projectId;
                $cache['synced_at'] = time();
                $cache['available'] = true;
                $cache['error'] = '';
                // A full bounded window also removes deleted completed tasks. A
                // delta merged with local history cannot establish deletions.
                $completed = $projectId === '' ? [] : $this->fetchCompletedTasks($projectId,
                    gmdate('Y-m-d\TH:i:s\Z', time() - 89 * 86400), gmdate('Y-m-d\TH:i:s\Z'));
                if ($completed === null) {
                    $cache['completion_error'] = 'Active tasks are current, but completed Todoist tasks could not be refreshed. ' . $this->apiError;
                } else {
                    $cache['completed_tasks'] = $this->projectRows($completed, $projectId, true);
                    $cache['completion_error'] = '';
                }
            }
            $cache['version'] = 2;
            $cache['scope'] = $this->snapshotScope();
            $cache['project'] = $this->projectName();
            $cache['attempted_at'] = time();
            if (!$this->writeJson('incoming.json', $cache)) $cache['error'] = 'The Todoist snapshot could not be saved. Check the wiki metadata directory permissions.';
            return $cache;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function projectRows(array $rows, $projectId, $completed)
    {
        $result = [];
        foreach ($rows as $task) {
            if (!is_array($task) || empty($task['id']) || (string)($task['project_id'] ?? '') !== (string)$projectId || !empty($task['is_deleted'])) continue;
            if (!$completed && (!empty($task['checked']) || !empty($task['is_completed']))) continue;
            $task['is_completed'] = $completed;
            $result[(string)$task['id']] = $task;
        }
        return array_values($result);
    }

    private function snapshotStates(array $snapshot)
    {
        $states = [];
        foreach ($snapshot['completed_tasks'] ?? [] as $task) $states[(string)$task['id']] = ['completed' => true, 'updated_at' => $snapshot['synced_at'] ?? time()];
        foreach ($snapshot['tasks'] ?? [] as $task) $states[(string)$task['id']] = ['completed' => false, 'updated_at' => $snapshot['synced_at'] ?? time()];
        return $states;
    }

    private function syncTodoistCompletion()
    {
        $snapshot = $this->refreshProjectSnapshot();
        $this->applyTodoistCompletion($this->snapshotStates($snapshot));
        return $snapshot;
    }

    /** Preserve the last good snapshot while forcing reconciliation after a write. */
    private function invalidateProjectSnapshot($completedId = null)
    {
        io_mkdir_p($this->storageDirectory());
        $lock = @fopen($this->storageDirectory() . '/incoming.lock', 'c');
        if (!$lock) return;
        try {
            if (!flock($lock, LOCK_EX)) return;
            $cache = $this->loadProjectSnapshot();
            if ($cache) {
                if ($completedId !== null) {
                    foreach ($cache['tasks'] ?? [] as $index => $task) {
                        if ((string)$task['id'] !== (string)$completedId || !empty($task['due']['is_recurring'])) continue;
                        unset($cache['tasks'][$index]);
                        $task['is_completed'] = true;
                        $task['checked'] = true;
                        $task['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
                        $cache['completed_tasks'] = array_values(array_filter($cache['completed_tasks'] ?? [], static function ($row) use ($completedId) {
                            return (string)$row['id'] !== (string)$completedId;
                        }));
                        $cache['completed_tasks'][] = $task;
                    }
                    $cache['tasks'] = array_values($cache['tasks'] ?? []);
                }
                $cache['attempted_at'] = 0;
                $this->writeJson('incoming.json', $cache);
            }
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
            foreach ($response['items'] as $row) {
                if (!is_array($row) || empty($row['id']) || !isset($row['project_id'])) {
                    $this->apiError = 'Todoist returned an invalid completed-task collection.';
                    return null;
                }
            }
            $all = array_merge($all, $response['items']);
            $cursor = $response['next_cursor'] ?? null;
            if ($cursor !== null) {
                if (!is_string($cursor) || isset($seen[$cursor]) || count($seen) >= 100) return null;
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        return $all;
    }

    private function applyTodoistCompletion(array $states)
    {
        if (!$states) return;
        $tasks = $this->loadTasks();
        $changed = false;
        foreach ($tasks as &$task) {
            $id = (string)($task['todoist_id'] ?? '');
            if ($id === '' || !isset($states[$id])) continue;
            if (!empty($task['remote_missing']) && (int)($states[$id]['updated_at'] ?? 0) > (int)($task['remote_checked_at'] ?? 0)) {
                unset($task['remote_missing']);
                $changed = true;
            }
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
            foreach ($response['results'] as $row) {
                if (!is_array($row) || empty($row['id'])
                    || (strpos($path, '/tasks?') === 0 && !isset($row['project_id']))
                    || ($path === '/projects' && !isset($row['name']))) {
                    $this->apiError = 'Todoist returned an invalid collection.';
                    return null;
                }
            }
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
            echo json_encode(['ok' => true, 'synced' => true, 'completed' => !empty($task['is_completed']), 'canComplete' => empty($task['is_completed']) && $this->canManageImportedTasks($page)]);
            return;
        }
        if (!checkSecurityToken() || !$this->canManageImportedTasks($page)) {
            http_response_code(403);
            echo json_encode(['error' => 'You cannot complete this Todoist task.']);
            return;
        }
        $current = $this->request('GET', '/tasks/' . rawurlencode((string)$task['id']));
        if (!is_array($current) || !isset($current['project_id']) || (string)$current['project_id'] !== (string)$task['project_id'] || (!empty($current['is_completed']) || !empty($current['checked']) || !empty($current['is_deleted']))) {
            $this->invalidateProjectSnapshot();
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
        echo json_encode(['ok' => true, 'completed' => true]);
    }

    private function saveTasks($tasks)
    {
        return $this->writeJson('tasks.json', $tasks);
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
