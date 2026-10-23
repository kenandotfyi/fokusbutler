<?php
/** Isolated UI fixture: MEMOS_UI_DIR=/tmp/... php -S 127.0.0.1:8189 this-file.php */
if (PHP_SAPI !== 'cli-server' || !getenv('MEMOS_UI_DIR')) { http_response_code(403); exit; }
define('DOKU_INC', __DIR__);
require dirname(__DIR__) . '/modules/memos/Store.php';
$root = getenv('MEMOS_UI_DIR');
if (!is_dir($root)) mkdir($root, 0700, true);
$assets = dirname(__DIR__) . '/modules/memos/';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/assets/') === 0) {
    $name = substr($path, 8);
    if (!in_array($name, ['script.js', 'style.css', 'vendor/marked-18.1.1.js', 'vendor/purify-3.4.17.js'], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . (substr($name, -4) === '.css' ? 'text/css' : 'application/javascript')); readfile($assets . $name); exit;
}
if ($path === '/icon.svg') { header('Content-Type: image/svg+xml'); readfile(dirname(__DIR__) . '/core/icons/pen-nib-duotone.svg'); exit; }
class JotsUIConnection extends FokusButler_Memos_Connection {
    private $root;
    public function __construct($root) { $this->root = $root; }
    public function baseUrl() { return 'https://fixture.invalid'; }
    public function scope() { return 'ui-fixture'; }
    public function request($method, $path, $data = null) {
        $notes = json_decode(file_get_contents($this->root . '/remote.json'), true);
        if (file_exists($this->root . '/offline')) throw new RuntimeException('Fixture is offline', 502);
        if ($method === 'GET' && strpos($path, '/memos?') === 0) {
            parse_str(parse_url($path, PHP_URL_QUERY), $query);
            return ['memos' => array_values(array_filter($notes, static function ($m) use ($query) { return ($m['state'] ?? 'NORMAL') === $query['state']; }))];
        }
        if ($method === 'GET') { $name = substr($path, 1); if (!isset($notes[$name])) throw new RuntimeException('Not found', 404); return $notes[$name]; }
        if ($method === 'POST') {
            parse_str(parse_url($path, PHP_URL_QUERY), $query); $name = 'memos/' . $query['memoId'];
            preg_match_all('/(?:^|\s)#([\w\/]+)/u', $data['content'], $matches);
            $note = ['name' => $name, 'content' => $data['content'], 'tags' => $matches[1], 'state' => 'NORMAL', 'createTime' => gmdate('c'), 'updateTime' => gmdate('c')];
        } else { $name = $data['name']; $note = array_merge($notes[$name], $data, ['updateTime' => gmdate('c')]); }
        $notes[$name] = $note; file_put_contents($this->root . '/remote.json', json_encode($notes)); return $note;
    }
    public function download(array $attachment, $destination, $limit) { file_put_contents($destination, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aCrkAAAAASUVORK5CYII=')); return filesize($destination); }
}
if (!file_exists($root . '/remote.json')) file_put_contents($root . '/remote.json', json_encode([
    'memos/engineering' => ['name' => 'memos/engineering', 'content' => "## Inductor measurement\n\nRemember to check **core temperature** at full load. #engineering\n\n![Capture](/file/attachments/figure/capture.png)\n\n<img src=x onerror=alert(1)><script>alert(1)</script>", 'tags' => ['engineering'], 'createTime' => '2026-10-23T09:00:00Z', 'updateTime' => '2026-10-23T09:00:00Z', 'attachments' => [['name' => 'attachments/figure', 'filename' => 'capture.png', 'type' => 'image/png', 'size' => '68']]],
    'memos/personal' => ['name' => 'memos/personal', 'content' => 'A thought from yesterday. #personal', 'tags' => ['personal'], 'state' => 'ARCHIVED', 'createTime' => '2026-10-22T08:00:00Z', 'updateTime' => '2026-10-22T08:00:00Z']
]));
$store = new FokusButler_Memos_Store(new JotsUIConnection($root), $root, 'fixture');
if (!file_exists($root . '/seeded')) { do { $step = $store->syncStep(true); } while ($step['more']); touch($root . '/seeded'); }
if ($path === '/api') {
    header('Content-Type: application/json');
    try {
        $a = $_REQUEST['action'] ?? 'list';
        if ($a === 'list') $result = $store->snapshot();
        elseif ($a === 'sync') $result = $store->syncStep(!empty($_POST['force']));
        elseif ($a === 'save') $result = $store->queue($_POST['operation'], $_POST['name'], $_POST['content'], $_POST['baseUpdateTime']);
        elseif ($a === 'retry') $result = $store->retry($_POST['operation']);
        elseif ($a === 'discard') $result = $store->cancelPending($_POST['operation']);
        elseif ($a === 'file') { $file = $store->file($_GET['key']); header('Content-Type: image/png'); readfile($file['path']); exit; }
        else throw new RuntimeException('Unsupported fixture action');
        echo json_encode(['ok' => true] + $result);
    } catch (Throwable $e) { http_response_code(502); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}
if ($path === '/toggle') { file_exists($root . '/offline') ? unlink($root . '/offline') : touch($root . '/offline'); header('Location: /'); exit; }
?>
<!doctype html><html><head><meta charset="utf-8"><title>Isolated Jots UI fixture</title>
<link rel="stylesheet" href="/assets/style.css"><style>:root{--fokus-text:#222;--fokus-muted:#777;--fokus-surface:#fff;--fokus-control-background:#fff;--fokus-control-text:#333;--fokus-border:#ddd;--fokus-vault-accent:#272727;--fokus-on-accent:#fff;--fokus-body-font:system-ui;--fokus-surface-soft:#f4f4f4}body{font:14px system-ui;max-width:980px;margin:30px auto}.topBanner{display:flex;align-items:center;padding:10px;border:1px solid #ddd}.fokus-jots-page{padding:20px}.fokus-page-heading{display:flex;align-items:center;gap:8px}</style>
<script>window.FokusButlerJotsConfig={endpoint:'/api',token:'fixture',homeUrl:location.origin+'/',pageId:'fixture',pageUrl:location.origin+'/',settingsUrl:'',shortcut:'Alt+Shift+J',configured:true,storageKey:'fokus-jots-ui-fixture',icon:'/icon.svg'};window.FokusPaneProviders={register(p){this.provider=p}};window.infinitepanelsOpenPage=async()=>{const pane=await FokusPaneProviders.provider.load();document.getElementById('mount').replaceChildren(pane.content)};</script>
<script defer src="/assets/vendor/marked-18.1.1.js"></script><script defer src="/assets/vendor/purify-3.4.17.js"></script><script defer src="/assets/script.js"></script>
</head><body><h1>Isolated Jots UI fixture</h1><p>No real notes or Memos account are used.</p><button data-fokus-jot>Quick jot</button> <form action="/toggle" method="post" style="display:inline"><button>Toggle fixture connection</button></form><main id="mount"></main><script>document.addEventListener('DOMContentLoaded',()=>window.infinitepanelsOpenPage());</script></body></html>
