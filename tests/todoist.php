<?php
/** Offline Todoist reconciliation regressions: no network or real wiki writes. */
define('DOKU_INC', __DIR__);
class DokuWiki_Action_Plugin {}
class DokuWiki_Syntax_Plugin { public function getLang($key) { return 'Todoist tasks'; } }
class Doku_Renderer { public $doc = ''; public $info = []; }
class Doku_Event {
    public $data;
    public function preventDefault() {}
    public function stopPropagation() {}
}
class FokusButler_Modules {
    public static $enabled = true;
    public static $token = '';
    public static $project = 'Wiki';
    public static function enabled($module) { return self::$enabled; }
    public static function config($module, $key, $notset = false) { return $key === 'project_name' ? self::$project : ($key === 'api_token' ? self::$token : ''); }
    public static function language($module, $key) { return 'Todoist tasks'; }
}
class TestInput {
    public $server; public $post; public $values = [];
    public function __construct() { $this->server = $this; $this->post = $this; }
    public function str($key) { return $this->values[$key] ?? ''; }
}
define('AUTH_READ', 1);
define('AUTH_EDIT', 2);
$permission = AUTH_EDIT;
$csrf = true;
$source = '~~TODOS~~';
function auth_quickaclcheck($page) { global $permission; return $permission; }
function checkSecurityToken() { global $csrf; return $csrf; }
function rawWiki($page) { global $source; return $source; }
function plugin_load($type, $id) { global $action; return $type === 'action' ? $action : null; }
function io_mkdir_p($dir) { if (!is_dir($dir)) mkdir($dir, 0700, true); }
function cleanID($id) { return $id; }
function hsc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/core/ModuleContext.php';
require dirname(__DIR__) . '/modules/todoist/Action.php';
require dirname(__DIR__) . '/modules/todoist/Syntax.php';
class IncomingTest extends FokusButler_Module_Todoist_Action {
    public $calls = [];
    public $responses = [];
    protected function request($method, $path, $payload = null) {
        $this->calls[] = [$method, $path, $payload];
        if (!$this->responses) throw new RuntimeException('Unexpected API request: ' . $method . ' ' . $path);
        $response = array_shift($this->responses);
        $status = is_array($response) && isset($response['_http']) ? $response['_http'] : ($response === null ? 503 : 200);
        (new ReflectionProperty(FokusButler_Module_Todoist_Action::class, 'apiStatus'))->setValue($this, $status);
        (new ReflectionProperty(FokusButler_Module_Todoist_Action::class, 'apiError'))->setValue($this, $status >= 400 ? 'Fixture HTTP ' . $status : '');
        return isset($response['_http']) ? ($response['body'] ?? null) : $response;
    }
}
$checks = 0;
function verify($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$dir = sys_get_temp_dir() . '/todoist-sync-' . bin2hex(random_bytes(5));
$conf = ['metadir' => $dir];
$INPUT = new TestInput();
$INPUT->values['REMOTE_USER'] = 'admin';
$ID = 'start';
$action = new IncomingTest();
$task = ['id' => 'remote1', 'project_id' => 'wiki', 'content' => 'Actual remote task', 'checked' => false, 'is_deleted' => false];
$completedTask = ['id' => 'remote-old', 'project_id' => 'wiki', 'content' => 'Completed idea', 'completed_at' => gmdate('Y-m-d\TH:i:s\Z')];
$storage = $dir . '/fokusbutler/todoist';
io_mkdir_p($storage);
function putState($name, $data) { global $storage; file_put_contents($storage . '/' . $name . '.json', json_encode($data)); }
function state($name) { global $storage; return json_decode(file_get_contents($storage . '/' . $name . '.json'), true); }
function expireIncoming() { $cache = state('incoming'); $cache['attempted_at'] = 0; putState('incoming', $cache); }
function refreshWith($active, $completed = []) {
    global $action;
    if (is_file($GLOBALS['storage'] . '/incoming.json')) expireIncoming();
    $action->responses = [['results' => [['id' => 'wiki', 'name' => 'Wiki']], 'next_cursor' => null],
        ['results' => $active, 'next_cursor' => null], $completed === null ? null : ['items' => $completed, 'next_cursor' => null]];
    return $action->getProjectTasks('start');
}
function keysOf($snapshot) { return array_column(array_values($snapshot['tasks']), 'id'); }
function eventCall($call, $key) {
    global $action, $INPUT;
    $INPUT->values['task'] = $key; $INPUT->values['page'] = 'start';
    $event = new Doku_Event(); $event->data = 'fokusbutler_todoist_' . $call;
    ob_start(); $action->handleAjax($event, null); return json_decode(ob_get_clean(), true);
}
function privateCall($method, ...$args) {
    global $action;
    return (new ReflectionMethod(FokusButler_Module_Todoist_Action::class, $method))->invoke($action, ...$args);
}
try {
    // Reproduce both ghosts: legacy local history and obsolete wiki-linked records.
    putState('tasks', [
        'oldkey01' => ['todoist_id' => 'deleted-linked', 'page' => 'secret', 'title' => 'Deleted remotely', 'completed' => false],
        'oldkey02' => ['todoist_id' => 'old-completed', 'page' => 'secret', 'title' => 'Deleted completed', 'completed' => true],
        'pending1' => ['page' => 'secret', 'title' => 'Never synced', 'completed' => false],
        'linked01' => ['todoist_id' => 'remote1', 'page' => 'start', 'completed' => false],
    ]);
    putState('history', ['project' => 'Wiki', 'tasks' => ['ghost' => ['id' => 'ghost', 'project_id' => 'wiki', 'is_completed' => false]]]);
    putState('completion', ['project_name' => 'Wiki', 'completed_tasks' => ['old-completed' => ['id' => 'old-completed', 'project_id' => 'wiki']], 'attempted_at' => time()]);
    putState('incoming', ['project' => 'Wiki', 'tasks' => [['id' => 'legacy-ghost', 'project_id' => 'wiki']], 'attempted_at' => time()]);
    $action->responses = [
        ['results' => [['id' => 'other', 'name' => 'Other']], 'next_cursor' => 'p2'],
        ['results' => [['id' => 'wiki', 'name' => 'Wiki']], 'next_cursor' => null],
        ['results' => [$task], 'next_cursor' => 't2'],
        ['results' => [array_merge($task, ['id' => 'deleted', 'is_deleted' => true]), array_merge($task, ['id' => 'checked', 'checked' => true]), ['id' => 'wrong', 'project_id' => 'other']], 'next_cursor' => null],
        ['items' => [$completedTask], 'next_cursor' => 'c2'],
        ['items' => [array_merge($completedTask, ['id' => 'deleted-completed', 'is_deleted' => true])], 'next_cursor' => null],
    ];
    $snapshot = $action->getProjectTasks('start');
    verify(keysOf($snapshot) === ['remote-old', 'remote1'], 'Legacy/wiki records resurrected absent/deleted/wrong-project tasks');
    verify($snapshot['available'] && !$snapshot['stale'] && $snapshot['error'] === '', 'Fresh snapshot metadata incorrect');
    verify(count($action->calls) === 6 && str_contains($action->calls[5][1], 'cursor=c2'), 'Project/active/completed pagination not followed');
    $key = 'todoist-' . hash('sha256', 'remote1');
    verify(($snapshot['tasks'][$key]['wiki_page'] ?? '') === 'start', 'Confirmed remote task lost its wiki backlink');
    verify(state('incoming')['version'] === 2, 'Legacy snapshot did not migrate automatically');
    $before = count($action->calls);
    $action->getProjectTasks('start');
    verify(count($action->calls) === $before, 'Shared snapshot did not throttle refresh');

    // Active rows disappear even when completed-history refresh is unavailable.
    $partial = refreshWith([], null);
    verify(keysOf($partial) === ['remote-old'] && $partial['error'] !== '' && !$partial['stale'], 'Completed-endpoint failure kept a phantom open task or discarded good active data');
    $empty = refreshWith([]);
    verify($empty['tasks'] === [] && $empty['available'], 'Authoritative empty project did not clear deleted active/completed rows');
    verify($action->getProjectTasks('start')['tasks'] === [], 'A second render resurrected old wiki records');
    verify(count(state('tasks')) === 4, 'Aggregate read unexpectedly altered wiki source/registry');

    $fresh = refreshWith([$task], [$completedTask]);
    expireIncoming();
    $action->responses = [['results' => [['id' => 'wiki', 'name' => 'Wiki']]], ['results' => [], 'next_cursor' => 'more'], null];
    $failed = $action->getProjectTasks('start');
    verify(keysOf($failed) === keysOf($fresh) && $failed['stale'] && $failed['error'] !== '', 'Partial active refresh destroyed last good snapshot or hid failure');
    expireIncoming();
    $action->responses = [['results' => [], 'next_cursor' => 'repeat'], ['results' => [], 'next_cursor' => 'repeat']];
    verify($action->getProjectTasks('start')['stale'], 'Repeated project cursor accepted');
    expireIncoming();
    $action->responses = [['results' => [['id' => 'wiki', 'name' => 'Wiki']]], ['results' => [$task], 'next_cursor' => 'repeat'], ['results' => [], 'next_cursor' => 'repeat']];
    verify($action->getProjectTasks('start')['stale'], 'Repeated active-task cursor accepted');
    expireIncoming();
    $action->responses = [['results' => [['id' => 'wiki', 'name' => 'Wiki'], ['id' => 'duplicate', 'name' => 'wiki']]]];
    verify(str_contains($action->getProjectTasks('start')['error'], 'Multiple'), 'Ambiguous duplicate project names silently selected a project');
    expireIncoming();
    $action->responses = [['results' => []]];
    verify($action->getProjectTasks('start')['tasks'] === [], 'Missing/deleted project retained ghosts');
    verify(end($action->calls)[0] === 'GET', 'Read created a missing project');
    refreshWith([$task], [$completedTask]);
    expireIncoming();
    $action->responses = [['results' => [['id' => 'replacement', 'name' => 'Wiki']]], ['results' => []], null];
    verify($action->getProjectTasks('start')['tasks'] === [], 'Recreated project inherited previous project history');

    // A different token or project must never inherit another account's snapshot.
    refreshWith([$task]);
    FokusButler_Modules::$token = 'fixture-other-account';
    $action->responses = [null];
    $switched = $action->getProjectTasks('start');
    verify(!$switched['available'] && $switched['tasks'] === [] && $switched['error'] !== '', 'Credential change leaked a previous account snapshot');
    verify(!str_contains(file_get_contents($storage . '/incoming.json'), 'fixture-other-account'), 'Token was persisted in snapshot');
    FokusButler_Modules::$token = '';
    refreshWith([$task]);
    FokusButler_Modules::$project = 'Different';
    $action->responses = [null];
    verify(!$action->getProjectTasks('start')['available'], 'Project-name change reused another project snapshot');
    FokusButler_Modules::$project = 'Wiki';
    refreshWith([$task]);

    $before = count($action->calls);
    $INPUT->values['REMOTE_USER'] = '';
    verify(!$action->getProjectTasks('start')['tasks'], 'Anonymous user saw personal tasks');
    $INPUT->values['REMOTE_USER'] = 'admin'; $permission = 0;
    verify(!$action->getProjectTasks('start')['tasks'], 'Page ACL bypass');
    $permission = AUTH_EDIT; $source = 'Ordinary page';
    verify(!$action->getProjectTasks('start')['tasks'], 'Task API accepted unrelated page');
    $source = '~~TODOS~~'; FokusButler_Modules::$enabled = false;
    verify(!$action->getProjectTasks('start')['tasks'], 'Disabled module exposed remote tasks');
    FokusButler_Modules::$enabled = true;
    verify(count($action->calls) === $before, 'Rejected readers reached remote API');
    $permission = AUTH_READ;
    verify(eventCall('status', $key)['canComplete'] === false, 'Read-only user allowed completion');
    verify(isset(eventCall('complete', $key)['error']), 'Read-only completion accepted');
    $permission = AUTH_EDIT; $csrf = false;
    verify(isset(eventCall('complete', $key)['error']) && count($action->calls) === $before, 'CSRF bypass reached API');
    $csrf = true;
    $action->responses = [['id' => 'remote1', 'project_id' => 'other']];
    verify(isset(eventCall('complete', $key)['error']), 'Moved task allowed completion');
    verify(state('incoming')['attempted_at'] === 0, 'Moved task did not trigger list reconciliation');
    refreshWith([$task]);
    $action->responses = [$task, ['ok' => true]];
    verify(eventCall('complete', $key)['completed'] === true, 'Valid task completion failed');
    verify(state('incoming')['attempted_at'] === 0 && count(state('incoming')['tasks']) === 0 && count(state('incoming')['completed_tasks']) === 1, 'Completion discarded good fallback data or failed to invalidate snapshot');
    $recurring = array_merge($task, ['due' => ['date' => '2026-12-01', 'is_recurring' => true]]);
    $reopened = refreshWith([$recurring], [$completedTask, $task]);
    verify(empty($reopened['tasks'][$key]['is_completed']) && $reopened['tasks'][$key]['due']['date'] === '2026-12-01', 'Recurring/reopened active task lost precedence over completion history');
    verify(empty(state('tasks')['linked01']['completed']), 'Wiki-linked task did not reflect reopened remote state');
    $ajax = eventCall('list', '');
    verify($ajax['available'] && !$ajax['stale'] && $ajax['syncedAt'] > 0, 'List AJAX omitted freshness metadata');

    // A confirmed close must not reopen locally if the next remote read fails.
    refreshWith([$task]);
    $action->responses = [$task, ['ok'=>true]];
    verify(eventCall('complete', $key)['completed'], 'Second close fixture failed');
    $action->responses = [null];
    $fallback = $action->getProjectTasks('start');
    verify($fallback['stale'] && !empty($fallback['tasks'][$key]['is_completed']), 'Confirmed close became an open task after a transport failure');
    $reopened = refreshWith([$recurring], [$completedTask, $task]);
    expireIncoming();
    $action->responses = [['results'=>[['id'=>'wiki','name'=>'Wiki']]], ['results'=>[['id'=>'malformed-with-no-project']]]];
    verify($action->getProjectTasks('start')['stale'], 'Malformed active rows silently erased a good snapshot');
    expireIncoming();
    $action->responses = [['results'=>[['id'=>'wiki','name'=>'Wiki']]], ['results'=>[]], ['items'=>[['broken'=>'row']]]];
    $malformed = $action->getProjectTasks('start');
    verify($malformed['error'] !== '' && count(array_filter($malformed['tasks'], static fn($row) => empty($row['is_completed']))) === 0, 'Malformed completion response kept an obsolete open task');
    $reopened = refreshWith([$recurring], [$completedTask]);

    // Serialize against another pane. Never wait on a remote refresh or invent zeros.
    expireIncoming();
    $lock = fopen($storage . '/incoming.lock', 'c'); flock($lock, LOCK_EX);
    $before = count($action->calls);
    verify(keysOf($action->getProjectTasks('start')) === keysOf($reopened) && count($action->calls) === $before, 'Parallel refresh ignored shared lock or discarded last snapshot');
    flock($lock, LOCK_UN); fclose($lock);

    // Inline references recognize both tombstones and missing-task HTTP responses.
    $record = ['todoist_id' => 'gone', 'page' => 'start', 'completed' => false];
    putState('tasks', ['abcd1234' => $record]);
    $action->responses = [['_http' => 404]];
    verify(!empty(privateCall('refreshWikiTaskAvailability', 'abcd1234', $record)['remote_missing']), '404 did not mark inline task unavailable');
    $action->responses = [array_merge($task, ['id' => 'gone', 'is_deleted' => true])];
    verify(!empty(privateCall('refreshWikiTaskAvailability', 'abcd1234', $record)['remote_missing']), 'Explicit remote tombstone did not mark unavailable');
    $action->responses = [['_http' => 503]];
    putState('tasks', ['abcd1234' => $record]);
    verify(empty(privateCall('refreshWikiTaskAvailability', 'abcd1234', $record)['remote_missing']), 'Transport failure was treated as deletion');

    $record = ['todoist_id'=>'remote1','page'=>'start','completed'=>false,'remote_missing'=>true,'remote_checked_at'=>time()-30];
    putState('tasks', ['linked01'=>$record]);
    refreshWith([$task]);
    verify(empty(state('tasks')['linked01']['remote_missing']), 'A new confirmed active snapshot did not clear an obsolete missing-task marker');

    // A stale project.json must not send newly created tasks into a renamed project.
    putState('project', ['id'=>'old-renamed-project','name'=>'Wiki','scope'=>privateCall('snapshotScope')]);
    $action->responses = [['results'=>[['id'=>'other','name'=>'Other']], 'next_cursor'=>'next'], ['results'=>[['id'=>'wiki','name'=>'Wiki']], 'next_cursor'=>null]];
    verify(privateCall('getOrCreateProject') === 'wiki', 'New wiki tasks trusted an obsolete project ID or missed the next project page');
    $before = count($action->calls);
    verify(privateCall('getOrCreateProject') === 'wiki' && count($action->calls) === $before, 'Project resolution was repeated within the same page save');

    $renderer = new Doku_Renderer(); $syntax = new FokusButler_Module_Todoist_Syntax();
    $syntax->render('xhtml', $renderer, ['type' => 'list']);
    verify(str_contains($renderer->doc, 'Loading Todoist tasks') && $renderer->info['cache'] === false, 'Task-list renderer blocked on network or enabled parser caching');
    echo "PASS: $checks Todoist snapshot/reconciliation checks.\n";
} finally {
    foreach (glob($storage . '/*') ?: [] as $file) unlink($file);
    @rmdir($storage); @rmdir(dirname($storage)); @rmdir($dir);
}
