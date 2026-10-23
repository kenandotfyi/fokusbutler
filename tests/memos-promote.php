<?php
/** Offline export checks against core-like page/media fixtures. No real wiki writes. */
define('DOKU_INC', __DIR__);
define('AUTH_CREATE', 4); define('AUTH_UPLOAD', 8);
require dirname(__DIR__) . '/modules/memos/Promote.php';
$root = sys_get_temp_dir() . '/fokus-jots-promote-' . bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root . '/meta'); mkdir($root . '/pages'); mkdir($root . '/media');
$conf = ['metadir' => $root . '/meta']; $permission = AUTH_UPLOAD; $actions = true;
function cleanID($id) { return strtolower(trim(preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $id), ':')); }
function getNS($id) { $i = strrpos($id, ':'); return $i === false ? false : substr($id, 0, $i); }
function auth_quickaclcheck($id) { global $permission; return $permission; }
function actionOK($action) { global $actions; return $actions; }
function wikiFN($id) { global $root; return $root . '/pages/' . str_replace(':', '_', $id); }
function wikiLockFN($id) { return wikiFN($id) . '.lock'; }
function page_exists($id) { return is_file(wikiFN($id)); }
function checklock($id) {}
function lock($id) { touch(wikiLockFN($id)); }
function unlock($id) { unlink(wikiLockFN($id)); }
function mediaFN($id) { global $root; return $root . '/media/' . str_replace(':', '_', $id); }
function media_save($input, $id, $overwrite, $auth, $method) { if ($overwrite || $method !== 'copy') throw new RuntimeException('Unsafe media copy'); copy($input['name'], mediaFN($id)); return $id; }
function saveWikiText($id, $source, $summary) { file_put_contents(wikiFN($id), $source); }
function wl($id, $more = '', $absolute = false, $separator = '&') { return 'https://wiki.example/' . $id; }
function ml($id, $more = '', $absolute = false, $separator = '&') { return 'https://wiki.example/_media/' . $id; }
function getMimeTypes() { return ['png' => 'image/png', 'pdf' => 'application/pdf']; }
$checks = 0;
function verify($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function reject($operation, $code) { try { $operation(); } catch (RuntimeException $e) { verify($e->getCode() === $code, 'Unexpected rejection code'); return; } throw new RuntimeException('Expected operation to be rejected'); }
class PromoteFixture {
    public $note; public $path; public $filename = 'figure.png'; public $type = 'image/png';
    public function memo($name) { return $this->note; }
    public function file($key) { return ['path' => $this->path, 'filename' => $this->filename, 'type' => $this->type]; }
}
try {
    $store = new PromoteFixture(); $store->path = $root . '/cached-image'; file_put_contents($store->path, 'fixture-image-bytes');
    $store->note = ['name' => 'memos/FixtureABC', 'content' => 'A **Markdown** note.', 'updateTime' => 'revision-1', 'attachments' => [['name' => 'attachments/img', 'filename' => 'figure.png']]];
    $source = "A **Markdown** note.\n\n![Figure](/file/attachments/img/figure.png)";
    $prepare = function ($target = 'jots:measurement', $version = 'revision-1', $copy = true) use ($store, $source) { return FokusButler_Memos_Promote::prepare($store, 'https://memos.example', 'memos/fixture', $target, $source, $version, $copy); };
    $plan = $prepare();
    verify(strpos($plan['source'], 'A **Markdown** note.') === 0, 'Markdown must remain unchanged');
    verify(strpos($plan['source'], '/file/attachments/') === false, 'Managed attachment URLs must point to wiki media');
    verify(substr_count($plan['source'], '{{:' . $plan['files'][0]['id'] . '|Figure}}') === 1, 'Inline images must use one absolute wiki media reference');
    verify(strpos($plan['source'], '/_media/') === false, 'Media routes must not be parsed as relative media IDs');
    $appended = FokusButler_Memos_Promote::prepare($store, 'https://memos.example', 'memos/fixture', 'jots:appended', 'No inline image.', 'revision-1', true);
    verify(strpos($appended['source'], '{{:' . $appended['files'][0]['id'] . '|figure.png}}') !== false, 'Unreferenced attachments must use native wiki media syntax');
    verify(strpos($plan['source'], 'Original jot in Memos') !== false, 'Source attribution must remain');
    verify(count($plan['files']) === 1 && $plan['files'][0]['auth'] >= AUTH_UPLOAD, 'Export must preflight attachment permissions');
    verify($plan['files'][0]['id'] === 'jots:measurement:figure.png', 'Media namespace must include the full destination page ID');
    $nested = $prepare('components:capacitor:new_note');
    verify($nested['files'][0]['id'] === 'components:capacitor:new_note:figure.png', 'Nested destinations must not acquire extra jots or memo namespaces');
    $rootPage = $prepare('measurement');
    verify($rootPage['files'][0]['id'] === 'measurement:figure.png', 'Root pages must also have their own media namespace');
    $store->filename = 'paper.pdf'; $store->type = 'application/pdf';
    $document = FokusButler_Memos_Promote::prepare($store, 'https://memos.example', 'memos/fixture', 'jots:document', '[Read paper](https://memos.example/file/attachments/img/figure.png)', 'revision-1', true);
    verify(strpos($document['source'], '{{:' . $document['files'][0]['id'] . '?linkonly|Read paper}}') !== false, 'Document attachments must become download links, not images');
    $store->filename = 'figure.png'; $store->type = 'image/png';
    $permission = 0; reject($prepare, 403); $permission = AUTH_CREATE; reject($prepare, 403); $permission = AUTH_UPLOAD;
    reject(function () use ($prepare) { $prepare('jots:other', 'old-revision'); }, 409);
    reject(function () use ($prepare) { $prepare('INVALID PAGE ID'); }, 400);
    $actions = false; reject($prepare, 403); $actions = true;
    $without = $prepare('jots:without', 'revision-1', false);
    verify($without['files'] === [], 'Unchecking attachment copy must omit media writes');
    verify(strpos($without['source'], '/file/attachments/') !== false, 'Excluded attachment references must remain unchanged');
    touch(wikiLockFN('jots:measurement')); reject(function () use ($plan) { FokusButler_Memos_Promote::create($plan); }, 409); unlink(wikiLockFN('jots:measurement'));
    $result = FokusButler_Memos_Promote::create($plan);
    verify($result['id'] === 'jots:measurement' && $result['attachments'] === 1, 'Export must return the created page');
    verify(file_get_contents(wikiFN($result['id'])) === $plan['source'], 'Core saving must receive the prepared source');
    verify(is_file($store->path), 'Copying attachments must preserve the local Memos mirror');
    $media = mediaFN($plan['files'][0]['id']); verify(file_get_contents($media) === 'fixture-image-bytes', 'Copied wiki media must contain the cached bytes');
    verify(!is_file(wikiLockFN($result['id'])), 'Page lock must be released');
    reject($prepare, 409);
    $other = $prepare('jots:another'); verify(!$other['files'][0]['exists'], 'Different pages must have independent media namespaces');
    copy($media, mediaFN($other['files'][0]['id']));
    $reused = $prepare('jots:another'); verify($reused['files'][0]['exists'], 'A matching copied attachment may be reused within its page namespace');
    file_put_contents(mediaFN($other['files'][0]['id']), 'different-image'); reject(function () use ($prepare) { $prepare('jots:another'); }, 409);
    unlink($store->path); verify(is_file($media), 'Exported media must be independent of the Memos cache');
    verify($store->note['content'] === 'A **Markdown** note.', 'Promotion must not mutate the original memo');
    echo "PASS: $checks Memos-to-wiki page and attachment checks.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); rmdir($root);
}
