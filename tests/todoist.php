<?php
/** Isolated incoming-sync checks. Never calls the network or touches wiki state. */
define('DOKU_INC', __DIR__);
class DokuWiki_Action_Plugin {}
class DokuWiki_Syntax_Plugin {}
class Doku_Renderer { public $doc = ''; public $info = []; }
class Doku_Event {
    public $data;
    public function preventDefault() {}
    public function stopPropagation() {}
}
class FokusButler_Modules {
    public static $enabled = true;
    public static function enabled($module) { return self::$enabled; }
    public static function config($module, $key, $notset = false) { return $key === 'project_name' ? 'Wiki' : ''; }
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
        $this->calls[] = [$method, $path];
        return array_shift($this->responses);
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
$task = ['id' => 'remote1', 'project_id' => 'wiki', 'content' => '<script>idea</script>', 'description' => "<img src=x>\nSecond line", 'added_at' => '2026-10-19T08:30:00Z', 'priority' => 4, 'labels' => ['hardware'], 'due' => ['date' => '2026-10-20']];
$storage = $dir . '/fokusbutler/todoist';
function expireIncoming() { global $storage; @unlink($storage . '/incoming.json'); }
function eventCall($call, $key) {
    global $action, $INPUT;
    $INPUT->values['task'] = $key;
    $INPUT->values['page'] = 'start';
    $event = new Doku_Event(); $event->data = 'fokusbutler_todoist_' . $call;
    ob_start(); $action->handleAjax($event, null); return json_decode(ob_get_clean(), true);
}
try {
    $action->responses = [
        ['results' => [['id' => 'other', 'name' => 'Private']], 'next_cursor' => 'p2'],
        ['results' => [['id' => 'wiki', 'name' => 'Wiki']], 'next_cursor' => null],
        ['results' => [$task], 'next_cursor' => 't2'],
        ['results' => [['id' => 'wrong', 'project_id' => 'other']], 'next_cursor' => null],
    ];
    $snapshot = $action->getProjectTasks('start');
    verify(count($snapshot['tasks']) === 1, 'Project filter or pagination failed');
    verify(count($action->calls) === 4 && $action->calls[3][1] === '/tasks?project_id=wiki&cursor=t2', 'Cursors not followed');
    $key = key($snapshot['tasks']);
    $action->getProjectTasks('start');
    verify(count($action->calls) === 4, 'Cache did not throttle refresh');
    file_put_contents($storage . '/tasks.json', json_encode(['abcd1234' => ['todoist_id' => 'remote1', 'page' => 'secret']]));
    verify(!$action->getProjectTasks('start')['tasks'], 'Restricted wiki task duplicated into imported list');
    unlink($storage . '/tasks.json');
    $INPUT->values['REMOTE_USER'] = '';
    verify(!$action->getProjectTasks('start')['tasks'], 'Anonymous user saw personal tasks');
    $INPUT->values['REMOTE_USER'] = 'admin';
    $permission = 0;
    verify(!$action->getProjectTasks('start')['tasks'], 'Page ACL bypass');
    $permission = AUTH_EDIT;
    $source = 'Ordinary page';
    verify(!$action->getProjectTasks('start')['tasks'], 'Task API accepted unrelated page');
    $source = '~~TODOS~~';
    FokusButler_Modules::$enabled = false;
    verify(!$action->getProjectTasks('start')['tasks'], 'Disabled module read remote tasks');
    FokusButler_Modules::$enabled = true;
    $renderer = new Doku_Renderer();
    $syntax = new FokusButler_Module_Todoist_Syntax();
    $render = new ReflectionMethod($syntax, 'renderImportedTasks');
    $render->invoke($syntax, $renderer, $action);
    verify(strpos($renderer->doc, '<script>') === false && strpos($renderer->doc, '&lt;script&gt;') !== false, 'Title not escaped');
    verify(strpos($renderer->doc, '&lt;img src=x&gt;') !== false && strpos($renderer->doc, 'P1') === false && strpos($renderer->doc, '@hardware') !== false, 'Description or metadata lost');
    verify(strpos($renderer->doc, 'class="todoistcompanion-origin"') !== false && $renderer->info['cache'] === false, 'Origin or cache policy missing');
    $cache = json_decode(file_get_contents($storage . '/incoming.json'), true); $cache['attempted_at'] = 0;
    file_put_contents($storage . '/incoming.json', json_encode($cache));
    $action->responses = [['results' => [['id' => 'wiki', 'name' => 'Wiki']]], ['results' => [], 'next_cursor' => 'next'], null];
    $failed = $action->getProjectTasks('start');
    verify(count($failed['tasks']) === 1 && $failed['error'] !== '', 'Partial failure discarded last successful list');
    $permission = AUTH_READ;
    verify(eventCall('status', $key)['canComplete'] === false, 'Read-only user allowed completion');
    $before = count($action->calls);
    verify(isset(eventCall('complete', $key)['error']) && count($action->calls) === $before, 'Read-only completion reached API');
    $permission = AUTH_EDIT; $csrf = false;
    verify(isset(eventCall('complete', $key)['error']) && count($action->calls) === $before, 'CSRF bypass');
    $csrf = true;
    $action->responses = [['id' => 'remote1', 'project_id' => 'other']];
    verify(isset(eventCall('complete', $key)['error']), 'Moved task allowed completion');
    $action->responses = [$task, ['ok' => true]];
    verify(eventCall('complete', $key)['completed'] === true && !file_exists($storage . '/incoming.json'), 'Completion did not invalidate recurring-task snapshot');
    $action->responses = [['results' => [['id' => 'other', 'name' => 'Private']]]];
    verify(!$action->getProjectTasks('start')['tasks'] && end($action->calls)[0] === 'GET', 'Read created a missing project');
    expireIncoming();
    $action->responses = [['results' => [['id' => 'wiki', 'name' => 'Wiki']]], ['results' => [array_merge($task, ['checked' => true])]]];
    verify(!$action->getProjectTasks('start')['tasks'], 'Completed API v1 task remained active');
    expireIncoming();
    $action->responses = [['results' => [], 'next_cursor' => 'repeat'], ['results' => [], 'next_cursor' => 'repeat']];
    verify($action->getProjectTasks('start')['error'] !== '', 'Repeated cursor was accepted');
    echo "PASS: $checks incoming Todoist checks.\n";
} finally {
    foreach (glob($storage . '/*') ?: [] as $file) unlink($file);
    @rmdir($storage); @rmdir(dirname($storage)); @rmdir($dir);
}
