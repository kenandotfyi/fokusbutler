<?php
/** Run against a disposable real DokuWiki installation. */
$root = getenv('DOKUWIKI_TEST_ROOT');
if (!$root || !is_file($root . '/inc/init.php')) { fwrite(STDERR, "Set DOKUWIKI_TEST_ROOT to an isolated DokuWiki installation.\n"); exit(2); }
define('DOKU_INC', rtrim($root, '/') . '/');
define('NOSESSION', 1);
require DOKU_INC . 'inc/init.php';
require_once DOKU_INC . 'inc/media.php';
require_once DOKU_INC . 'inc/search.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/modules/media/Action.php';
$checks = 0;
function verifyMedia($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$directory = sys_get_temp_dir() . '/butler-media-' . bin2hex(random_bytes(6));
mkdir($directory);
$conf['mediadir'] = $directory;
$conf['useacl'] = 1;
$conf['superuser'] = '@admin';
$AUTH_ACL = ["*\t@ALL\t8", "private:*\t@ALL\t0", "readonly:*\t@ALL\t1", "private:shared:*\t@ALL\t1"];
$auth = plugin_load('auth', 'authplain');
$INPUT->server->set('REMOTE_USER', ''); $USERINFO = ['grps' => []];
set_exception_handler(static function ($error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });
try {
    foreach (['public', 'private', 'private/shared', 'readonly'] as $folder) mkdir($directory . '/' . $folder, 0777, true);
    foreach (['public/scope.txt', 'private/secret.txt', 'private/shared/allowed.txt', 'readonly/report.txt'] as $file) file_put_contents($directory . '/' . $file, 'test');
    for ($i = 0; $i < 85; $i++) file_put_contents($directory . '/public/plot' . $i . '.txt', 'test');
    symlink($directory . '/private', $directory . '/public/link');
    $action = new FokusButler_Module_Media_Action();
    $result = $action->listing('', 'txt', 0);
    verifyMedia($result['total'] === 88, 'Unreadable media leaked or readable child was omitted');
    verifyMedia(count($result['items']) === 80, 'Pagination did not limit response size');
    verifyMedia(!in_array('private', $result['folders'], true), 'Unreadable namespace leaked');
    verifyMedia(in_array('private:shared', $result['folders'], true), 'Readable child beneath a denied parent was hidden');
    verifyMedia(count($action->listing('public', '', 80)['items']) === 6, 'Second page was incorrect');
    verifyMedia($action->listing('readonly', '', 0)['canUpload'] === false, 'Read-only namespace allowed upload');
    verifyMedia($action->listing('public', '', 0)['canUpload'] === true, 'Upload namespace denied upload');
    verifyMedia($action->listing('private', '', 0)['total'] === 0, 'Private media leaked in direct browsing');
    verifyMedia($action->listing('', 'secret', 0)['total'] === 0, 'Private media leaked through search');
    verifyMedia($action->listing('public', 'allowed', 0)['total'] === 0, 'Search escaped its selected namespace');
    foreach (['public/picture.jpg', 'public/drawing.png', 'public/vector.svg', 'public/report.pdf', 'public/music.mp3', 'public/movie.mp4', 'public/archive.zip', 'private/secret.pdf', 'private/secret.jpg', 'private/shared/allowed.svg'] as $file) file_put_contents($directory . '/' . $file, 'fixture');
    symlink($directory . '/private/secret.jpg', $directory . '/public/linked.jpg');
    file_put_contents($directory . '/public/Invalid image.png', 'Not a valid DokuWiki filename');
    $totals = $action->getSummaryStats();
    verifyMedia($totals === ['total'=>96,'images'=>4,'pdfs'=>1,'audio'=>1,'video'=>1,'other'=>89], 'Media totals must group image formats, separate PDFs, enforce ACLs, include readable children, and exclude symlinks');
    // Build a disposable real reference index, including private and hidden pages.
    foreach (['datadir' => 'pages', 'metadir' => 'meta', 'indexdir' => 'index'] as $key => $subdir) {
        $conf[$key] = $directory . '/' . $subdir;
        mkdir($conf[$key]);
    }
    $conf['hidepages'] = '^:public:hidden_ref$';
    foreach (['public:visible_ref' => 'Public reference', 'private:secret_ref' => 'Private reference', 'public:hidden_ref' => 'Hidden reference'] as $page => $title) {
        io_saveFile(wikiFN($page), "====== $title ======\n\n{{:public:scope.txt}}\n");
        (new \dokuwiki\Search\Indexer())->addPage($page, true);
    }
    $usage = $action->references('public:scope.txt')['references'];
    verifyMedia(count($usage) === 1 && $usage[0]['id'] === 'public:visible_ref', 'References leaked private/hidden page names or omitted the public page');
    verifyMedia($usage[0]['title'] === 'Public reference', 'Reference page title was missing');
    verifyMedia(strpos($usage[0]['url'], 'visible_ref') !== false, 'Reference page link was missing');
    try { $action->references('private:secret.txt'); throw new LogicException('Private media references allowed'); } catch (RuntimeException $e) { verifyMedia($e->getCode() === 403, 'Private media reference lookup was not denied'); }
    try { $action->references('public:missing.txt'); throw new LogicException('Missing media reference lookup allowed'); } catch (RuntimeException $e) { verifyMedia($e->getCode() === 404, 'Missing media reference lookup did not return 404'); }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try { $action->upload('public'); throw new LogicException('GET upload allowed'); } catch (RuntimeException $e) { verifyMedia($e->getCode() === 405, 'GET did not reject upload'); }
    $_SERVER['REQUEST_METHOD'] = 'POST'; $INPUT->server->set('REMOTE_USER', 'reader'); $INPUT->set('sectok', 'bad');
    try { $action->upload('public'); throw new LogicException('CSRF allowed'); } catch (RuntimeException $e) { verifyMedia($e->getCode() === 403, 'CSRF did not reject upload'); }
    echo "PASS: $checks real DokuWiki media checks (ACL, private search, readable children, pagination, symlinks, page references, upload permissions, method and CSRF).\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($directory);
}
