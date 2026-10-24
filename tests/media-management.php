<?php
/** Exercise file changes only inside a disposable DokuWiki installation. */
$root = getenv('DOKUWIKI_TEST_ROOT');
if (!$root || !is_file($root . '/inc/init.php')) { fwrite(STDERR, "Set DOKUWIKI_TEST_ROOT to an isolated DokuWiki installation.\n"); exit(2); }
define('DOKU_INC', rtrim($root, '/') . '/');
define('NOSESSION', 1);
require DOKU_INC . 'inc/init.php';
require_once DOKU_INC . 'inc/media.php';
require_once DOKU_INC . 'inc/search.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/modules/media/Manage.php';
set_exception_handler(static function ($error) { fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1); });
$checks = 0;
function verifyManage($ok, $message) { global $checks; if (!$ok) throw new LogicException($message); $checks++; }
function changeMedia($operation, $id, $extra = []) {
    global $INPUT;
    $INPUT->post->set('id', $id);
    $INPUT->post->set('fingerprint', FokusButler_Media_Manage::fingerprint($id));
    foreach ($extra as $key => $value) $INPUT->post->set($key, $value);
    return FokusButler_Media_Manage::change($operation, $INPUT->post);
}
function rejectManage($code, $callback, $message) {
    try { $callback(); } catch (RuntimeException $error) { verifyManage($error->getCode() === $code, $message . ': ' . $error->getMessage()); return; }
    throw new LogicException($message);
}
$directory = sys_get_temp_dir() . '/butler-manage-' . bin2hex(random_bytes(6)); mkdir($directory);
foreach (['datadir','olddir','mediadir','mediaolddir','metadir','mediametadir','cachedir','indexdir','lockdir','tmpdir'] as $key) { $conf[$key] = $directory . '/' . $key; mkdir($conf[$key]); }
$conf['useacl'] = 1; $conf['refcheck'] = 1; $conf['mediarevisions'] = 1; $conf['hidepages'] = ''; $conf['superuser'] = '@admin';
$AUTH_ACL = ["*\t@ALL\t16", "readonly:*\t@ALL\t1", "private:*\t@ALL\t0", "upload:*\t@ALL\t8"];
$auth = plugin_load('auth', 'authplain'); $USERINFO = ['grps'=>[]]; $INPUT->server->set('REMOTE_USER','');
$_SERVER['REQUEST_METHOD'] = 'POST';
try {
    verifyManage((bool)plugin_load('helper','move_op'), 'Fixture requires the Move plugin');
    $INPUT->post->set('id', 'drawings:schematics');
    $created = FokusButler_Media_Manage::change('namespace', $INPUT->post);
    verifyManage($created['created'] && is_dir($conf['mediadir'] . '/drawings/schematics'), 'Namespace creation failed');
    $INPUT->post->set('id', 'readonly:new_namespace');
    rejectManage(403, fn()=>FokusButler_Media_Manage::change('namespace', $INPUT->post), 'Namespace creation bypassed upload permissions');

    io_saveFile(mediaFN('public:delete.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
    io_saveFile(mediaFN('readonly:locked.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
    io_saveFile(mediaFN('private:secret.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
    rejectManage(403, fn()=>changeMedia('delete','readonly:locked.svg'), 'Read-only media deletion was allowed');
    rejectManage(403, fn()=>FokusButler_Media_Manage::details('private:secret.svg'), 'Private file details leaked');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    rejectManage(405, fn()=>changeMedia('delete','public:delete.svg'), 'GET file mutation was allowed');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    rejectManage(409, fn()=>changeMedia('delete','public:delete.svg',['fingerprint'=>'stale']), 'Stale deletion was allowed');
    $INPUT->server->set('REMOTE_USER','reader'); $INPUT->set('sectok','bad');
    rejectManage(403, fn()=>changeMedia('delete','public:delete.svg'), 'Invalid CSRF token was accepted');
    $INPUT->server->set('REMOTE_USER','');
    $deleted = changeMedia('delete','public:delete.svg');
    verifyManage($deleted['deleted'] && !file_exists(mediaFN('public:delete.svg')) && $deleted['revision'], 'Deletion did not retain an undo revision');
    $restored = changeMedia('restore','public:delete.svg',['revision'=>$deleted['revision']]);
    verifyManage($restored['id'] === 'public:delete.svg' && is_file(mediaFN($restored['id'])), 'Undo deletion did not restore the file');

    $image = imagecreatetruecolor(24,16); imagejpeg($image, $directory . '/sample.jpg');
    $initial = media_save(['name'=>$directory . '/sample.jpg','mime'=>'image/jpeg','ext'=>'jpg'], 'public:photo.jpg', false, AUTH_DELETE, 'copy');
    verifyManage($initial === 'public:photo.jpg', 'Could not initialize JPEG fixture');
    $originalHash = hash_file('sha256', mediaFN($initial));
    $details = FokusButler_Media_Manage::details($initial);
    verifyManage(count($details['metadata']) >= 4, 'Native JPEG metadata fields were missing');
    $metadata = array_column($details['metadata'], 'value', 'key'); $metadata['Iptc.Headline'] = 'Measured scope waveform';
    changeMedia('metadata',$initial,['metadata'=>$metadata]);
    verifyManage((new JpegMeta(mediaFN($initial)))->getField('Iptc.Headline') === 'Measured scope waveform', 'JPEG metadata did not save');
    $revised = FokusButler_Media_Manage::details($initial);
    verifyManage(count($revised['history']) >= 1, 'Metadata edit did not preserve version history');
    changeMedia('restore',$initial,['revision'=>$revised['history'][0]['revision']]);
    verifyManage(hash_file('sha256',mediaFN($initial)) === $originalHash, 'Version restoration did not recover the original bytes');
    $_FILES['upload'] = ['name'=>'wrong.png','tmp_name'=>$directory . '/sample.jpg','error'=>UPLOAD_ERR_OK];
    rejectManage(400, fn()=>changeMedia('replace',$initial), 'Replacement changed file extension');
    io_saveFile(wikiFN('public:measurement'), "====== Measurement ======\n\n{{:public:photo.jpg}}\n");
    (new \dokuwiki\Search\Indexer())->addPage('public:measurement',true);
    rejectManage(409, fn()=>changeMedia('delete',$initial), 'Referenced file deletion was allowed');
    lock('public:measurement');
    rejectManage(409, fn()=>changeMedia('move',$initial,['destination'=>'destination:photo.jpg']), 'Move overwrote a page being edited');
    unlock('public:measurement');
    io_saveFile(mediaFN('destination:occupied.jpg'), 'occupied');
    rejectManage(409, fn()=>changeMedia('move',$initial,['destination'=>'destination:occupied.jpg']), 'Move overwrote an existing file');
    rejectManage(403, fn()=>changeMedia('move',$initial,['destination'=>'readonly:photo.jpg']), 'Move into read-only namespace was allowed');
    $moved = changeMedia('move',$initial,['destination'=>'destination:photo.jpg']);
    verifyManage($moved['id'] === 'destination:photo.jpg' && !is_file(mediaFN($initial)) && is_file(mediaFN($moved['id'])), 'Namespace move failed');
    verifyManage(strpos(io_readFile(wikiFN('public:measurement'),false),'destination:photo.jpg') !== false, 'Move did not rewrite referencing pages');
    verifyManage(count(FokusButler_Media_Manage::details($moved['id'])['history']) >= 1, 'Move did not preserve old revisions');
    io_saveFile(mediaFN('public:history.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
    for ($n = 0; $n < 34; $n++) {
        $revision = time() - 1000 + $n;
        io_saveFile(mediaFN('public:history.svg', $revision), '<svg xmlns="http://www.w3.org/2000/svg"/>');
        addMediaLogEntry($revision, 'public:history.svg', DOKU_CHANGE_TYPE_EDIT, 'Old revision ' . $n);
    }
    addMediaLogEntry(filemtime(mediaFN('public:history.svg')), 'public:history.svg', DOKU_CHANGE_TYPE_EDIT, 'Current revision');
    $history = FokusButler_Media_Manage::history('public:history.svg');
    verifyManage(count($history['history']) === 30 && $history['more'] && $history['offset'] === 30, 'Version history did not paginate');
    $older = FokusButler_Media_Manage::history('public:history.svg', $history['offset']);
    verifyManage(count($older['history']) === 4 && !$older['more'], 'Older versions were not accessible');
    echo "PASS: $checks real media management checks (permissions, CSRF, stale files, delete/undo, JPEG metadata, revisions, Move plans, page links, editor locks, and collisions).\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($directory);
}
