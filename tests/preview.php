<?php
/** Read-only provider/sanitizer/ACL regression checks with no external requests. */
define('DOKU_INC', __DIR__ . '/');
define('DOKU_PLUGIN', DOKU_INC . 'plugins/');
define('AUTH_READ', 1);
class DokuWiki_Action_Plugin {}
class Doku_Event_Handler {
    public $hooks = [];
    public function register_hook($hook, $when, $plugin, $method) { $this->hooks[] = $hook; }
}
class FokusButler_Modules {
    public static $preferences = [];
    public static function enabled($module) { return $module !== 'preview'; }
    public static function config($module, $key, $default = false) { return self::$preferences[$key] ?? $default; }
}
class Doku_Event { public $data; public function preventDefault() {} public function stopPropagation() {} }
trait FokusButler_ModuleContext {}
$permission = 0;
$renders = 0;
function cleanID($id) { return $id; }
function auth_quickaclcheck($id) { global $permission; return $permission; }
function page_exists($id) { return $id === 'test'; }
function p_get_first_heading($id, $fallback) { return 'Page title'; }
function wl($id) { return '/doku.php?id=' . $id; }
function p_wiki_xhtml($id, $rev, $excuse) { global $renders; $renders++; return '<h1>Complete content</h1><p>More than a summary.</p>'; }
class PreviewInput { public $values = []; public function str($key) { return $this->values[$key] ?? ''; } }
$INPUT = new PreviewInput();
require dirname(__DIR__) . '/modules/content/Action.php';
require dirname(__DIR__) . '/modules/preview/Action.php';
class PreviewHttp {
    public $timeout, $max_redirect, $max_bodysize, $agent;
    public $headers = [], $resp_headers = [], $status = '200';
    public static $calls = 0;
    public function get($url) { self::$calls++; return '<h1>Encyclopedia article</h1><p>Full article text.</p>'; }
}
class_alias(PreviewHttp::class, 'dokuwiki\\HTTP\\DokuHTTPClient');
function io_mkdir_p($dir) { if (!is_dir($dir)) mkdir($dir, 0700, true); }
function io_saveFile($file, $data) { file_put_contents($file, $data, LOCK_EX); }
$checks = 0;
function verify($test, $message) { global $checks; if (!$test) throw new RuntimeException($message); $checks++; }
verify(FokusButler_Preview_Settings::values() === ['width'=>560, 'height'=>480, 'open_delay'=>750, 'close_delay'=>300], 'Preview defaults changed');
foreach (FokusButler_Preview_Settings::fields() as $key => $field) {
    verify(FokusButler_Preview_Settings::validate($key, (string)$field['min']) === $field['min'], 'Preview minimum rejected');
    verify(FokusButler_Preview_Settings::validate($key, (string)$field['max']) === $field['max'], 'Preview maximum rejected');
    foreach ([$field['min'] - 1, $field['max'] + 1, '', '1.5', 'NaN'] as $bad) {
        try { FokusButler_Preview_Settings::validate($key, $bad); verify(false, 'Invalid preview preference accepted'); }
        catch (InvalidArgumentException $error) { verify(true, 'Invalid setting rejected'); }
    }
}
FokusButler_Modules::$preferences = ['width'=>640, 'height'=>360, 'open_delay'=>0, 'close_delay'=>0];
$ACT = 'show'; $ID = 'test';
$configuration = new Doku_Event(); $configuration->data = ['script'=>[]];
(new FokusButler_Module_Preview_Action())->configure($configuration, null);
$source = $configuration->data['script'][0]['_data'];
$preferences = json_decode(substr($source, strpos($source, '=') + 1, -1), true);
verify($preferences['width'] === 640 && $preferences['height'] === 360 && $preferences['openDelay'] === 0 && $preferences['closeDelay'] === 0, 'Custom dimensions or zero timings did not reach the browser');
FokusButler_Modules::$preferences = ['width'=>-1, 'open_delay'=>'invalid'];
verify(FokusButler_Preview_Settings::values()['width'] === 560 && FokusButler_Preview_Settings::values()['open_delay'] === 750, 'Invalid stored preferences did not fall back to defaults');
FokusButler_Modules::$preferences = [];
$hooks = new Doku_Event_Handler();
(new FokusButler_Module_Preview_Action())->register($hooks);
verify($hooks->hooks === [], 'Disabled hover module registered hooks');
(new FokusButler_Module_Content_Action())->register($hooks);
verify(in_array('AJAX_CALL_UNKNOWN', $hooks->hooks, true) && in_array('TPL_METAHEADER_OUTPUT', $hooks->hooks, true), 'Content service disappeared when previews were disabled');
function callPreview($provider, $id) {
    global $INPUT;
    $INPUT->values = ['provider' => $provider, 'id' => $id];
    $event = new Doku_Event(); $event->data = 'fokusbutler_content';
    ob_start(); (new FokusButler_Module_Content_Action())->handleRequest($event, null); return json_decode(ob_get_clean(), true);
}
$target = FokusButler_Content_Provider::wikipediaTarget('https://de.m.wikipedia.org/wiki/Resonanzwandler#Schaltung');
verify($target['host'] === 'de.wikipedia.org' && $target['title'] === 'Resonanzwandler', 'Wikipedia language/mobile normalization failed');
$target = FokusButler_Content_Provider::wikipediaTarget('https://en.wikipedia.org/w/index.php?title=LC_circuit');
verify($target['title'] === 'LC_circuit', 'Wikipedia title query failed');
verify(FokusButler_Content_Provider::wikipediaRedirect('/w/rest.php/v1/page/United_States/html?redirect=no', 'en.wikipedia.org') === 'https://en.wikipedia.org/w/rest.php/v1/page/United_States/html?redirect=no', 'Article alias redirect failed');
foreach (['https://evil.test/w/rest.php/v1/page/Earth/html', '//127.0.0.1/private', 'https://en.wikipedia.org/private'] as $redirect) {
    try { FokusButler_Content_Provider::wikipediaRedirect($redirect, 'en.wikipedia.org'); verify(false, 'Unsafe redirect accepted'); }
    catch (RuntimeException $error) { verify($error->getCode() === 502, 'Redirect failure returned wrong status'); }
}
foreach (['https://en.wikipedia.org.evil.test/wiki/Earth', 'https://127.0.0.1/wiki/Earth', 'https://user@en.wikipedia.org/wiki/Earth', 'https://en.wikipedia.org:8080/wiki/Earth', 'file:///wiki/Earth', 'https://en.wikipedia.org/w/index.php?title[]=bad'] as $url) {
    try { FokusButler_Content_Provider::wikipediaTarget($url); verify(false, 'Unsafe URL accepted'); }
    catch (RuntimeException $error) { verify($error->getCode() === 400, 'Bad link returned wrong status'); }
}
$html = FokusButler_Content_Provider::sanitize('<h1 id="page">Page</h1><script>alert(1)</script><iframe src="/private"></iframe><form><input value="secret"></form><p onclick="bad()" style="position:fixed">Full body <a href="jav&#x61;script:alert(1)">unsafe</a><a class="wikilink1" data-wiki-id="other" href="/doku.php?id=other">Next</a></p>');
verify(!preg_match('/<script|<iframe|<form|<input|onclick|position:fixed|javascript:/i', $html), 'Active content survived sanitization');
verify(strpos($html, 'Full body') !== false && strpos($html, 'data-wiki-id="other"') !== false, 'Article text or nested wiki link lost');
$zotero = 'zotero://open-pdf/library/items/A253FYR3?page=10&annotation=FXKJLKMK';
$html = FokusButler_Content_Provider::sanitize('<a class="interwiki iw_zotero" href="' . htmlspecialchars($zotero, ENT_QUOTES) . '">PDF annotation</a><a href="zotero://select/groups/123/items/WUBQZ885">Item</a>');
verify(strpos(html_entity_decode($html), $zotero) !== false && strpos($html, 'zotero://select/groups/123/items/WUBQZ885') !== false, 'Internal Zotero PDF annotation or item links were removed');
$html = FokusButler_Content_Provider::sanitize('<img src="' . htmlspecialchars($zotero, ENT_QUOTES) . '"><a href="zotero://unsupported/library/items/A253FYR3">Unsupported</a>');
verify(strpos($html, 'zotero:') === false, 'Zotero was allowed as an image source or unsupported action');
$html = FokusButler_Content_Provider::sanitize('<a href="' . htmlspecialchars($zotero, ENT_QUOTES) . '">PDF</a>', 'en.wikipedia.org');
verify(strpos($html, 'zotero:') === false, 'Wikipedia content unexpectedly allowed application links');
$html = FokusButler_Content_Provider::sanitize('<p><a href="./Earth">Earth</a><img src="//upload.wikimedia.org/test.png" srcset="evil" onerror="bad()"></p>', 'en.wikipedia.org');
verify(strpos($html, 'https://en.wikipedia.org/wiki/Earth') !== false && strpos($html, 'https://upload.wikimedia.org/test.png') !== false && strpos($html, 'srcset') === false, 'Wikipedia relative links or images failed');
verify(callPreview('wiki', 'test')['ok'] === false && $renders === 0, 'Denied page reached the renderer');
$permission = AUTH_READ;
verify(callPreview('wiki', 'missing')['ok'] === false && $renders === 0, 'Missing page reached renderer');
$result = callPreview('wiki', 'test');
verify($result['ok'] && $result['title'] === 'Page title' && strpos($result['html'], 'More than a summary') !== false, 'Full internal page missing');
verify(callPreview('unknown', 'test')['ok'] === false, 'Unknown provider accepted');
$event = new Doku_Event(); $event->data = [DOKU_PLUGIN . 'preview/script.js', DOKU_PLUGIN . 'other/script.js'];
(new FokusButler_Module_Preview_Action())->replaceLegacyScript($event, null);
verify($event->data === [DOKU_PLUGIN . 'other/script.js'], 'Legacy preview replacement affected other scripts');
$directory = sys_get_temp_dir() . '/fokus-preview-' . bin2hex(random_bytes(5));
$conf = ['cachedir' => $directory];
try {
    $result = FokusButler_Content_Provider::wikipedia('https://en.wikipedia.org/wiki/Earth');
    verify(strpos($result['html'], 'Full article text.') !== false, 'String HTTP 200 status was rejected');
    FokusButler_Content_Provider::wikipedia('https://en.wikipedia.org/wiki/Earth');
    verify(PreviewHttp::$calls === 1, 'Wikipedia cache did not suppress repeat fetch');
} finally {
    foreach (glob($directory . '/fokusbutler/preview/*') ?: [] as $file) unlink($file);
    @rmdir($directory . '/fokusbutler/preview'); @rmdir($directory . '/fokusbutler'); @rmdir($directory);
}
echo "PASS: $checks preview checks.\n";
