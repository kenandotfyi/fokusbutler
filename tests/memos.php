<?php
/** Offline persistence/sync/write fixtures. Never contacts Memos or the user's wiki. */
define('DOKU_INC', __DIR__);
require dirname(__DIR__) . '/modules/memos/Store.php';
$checks = 0;
function expect($condition, $message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function fails($run, $code) { try { $run(); } catch (RuntimeException $e) { expect($e->getCode() === $code, 'Unexpected error code'); return; } throw new RuntimeException('Expected failure'); }
class MemosFixture extends FokusButler_Memos_Connection {
    public $remote = []; public $pages = []; public $calls = []; public $creates = 0; public $patches = 0; public $lostResponse = false; public $offline = false; public $downloads = 0; public $downloadFail = false;
    public function __construct() {}
    public function baseUrl() { return 'https://memos.example'; }
    public function scope() { return 'fixture'; }
    public function request($method, $path, $data = null) {
        $this->calls[] = [$method, $path, $data];
        if ($this->offline) throw new RuntimeException('Offline', 502);
        if (strpos($path, '/memos?') === 0 && $method === 'GET') {
            $page = array_shift($this->pages);
            if ($page instanceof Throwable) throw $page;
            return $page ?? [];
        }
        if ($method === 'GET') {
            $name = substr($path, 1);
            if (!isset($this->remote[$name])) throw new RuntimeException('Not found', 404);
            return $this->remote[$name];
        }
        if ($method === 'POST') {
            parse_str(parse_url($path, PHP_URL_QUERY), $q); $name = 'memos/' . $q['memoId'];
            $this->creates++; $this->remote[$name] = ['name' => $name, 'content' => $data['content'], 'updateTime' => '2026-10-23T10:00:00Z', 'visibility' => $data['visibility']];
            if ($this->lostResponse) { $this->lostResponse = false; throw new RuntimeException('Lost response', 502); }
            return $this->remote[$name];
        }
        if ($method === 'PATCH') {
            $this->patches++; $this->remote[$data['name']]['content'] = $data['content']; $this->remote[$data['name']]['updateTime'] = '2026-10-23T11:00:00Z';
            return $this->remote[$data['name']];
        }
        throw new RuntimeException('Unexpected fixture request');
    }
    public function download(array $attachment, $destination, $limit) { $this->downloads++; if ($this->downloadFail) throw new RuntimeException('Attachment offline'); file_put_contents($destination, 'cached-attachment'); return 17; }
}
$root = sys_get_temp_dir() . '/fokus-memos-test-' . bin2hex(random_bytes(8));
try {
    $client = new MemosFixture();
    $store = new FokusButler_Memos_Store($client, $root, 'ken');
    expect($store->summary()['total'] === null, 'An unsynced mirror is not an empty Memos account');
    $attachment = ['name' => 'attachments/image1', 'filename' => 'image.png', 'type' => 'image/png', 'size' => '17'];
    $first = ['name' => 'memos/first', 'content' => 'First #work', 'tags' => ['work'], 'attachments' => [$attachment]];
    $client->pages = [['memos' => [$first], 'nextPageToken' => 'page2'], ['memos' => [['name' => 'memos/second', 'content' => 'Second']]], ['memos' => [['name' => 'memos/archived', 'content' => 'Old', 'state' => 'ARCHIVED']]]];
    expect($store->syncStep(true)['more'], 'First page must continue');
    expect(count($store->snapshot()['memos']) === 0, 'Partial scan must not replace mirror');
    expect($store->syncStep()['more'], 'Second page must continue');
    expect($store->syncStep()['more'], 'Archived scan must continue into attachments');
    expect($store->syncStep()['more'], 'Attachment step must continue');
    expect(!$store->syncStep()['more'], 'Completed scan must publish');
    $snapshot = $store->snapshot();
    expect(count($snapshot['memos']) === 3, 'All active and archived notes must be present');
    $callsBeforeSummary = count($client->calls);
    expect($store->summary()['total'] === 3, 'Jots totals must include active and archived notes');
    expect(count($client->calls) === $callsBeforeSummary, 'Status totals must not request remote Memos data');
    expect((new FokusButler_Memos_Store($client, $root, 'other-user'))->summary()['total'] === null, 'Status totals must use the current user mirror');
    expect($snapshot['files']['attachments/image1']['cached'], 'Attachment must be cached locally');
    expect(is_file($store->file(hash('sha256', 'attachments/image1'))['path']), 'Private attachment lookup must work');
    expect(strpos($client->calls[1][1], 'pageToken=page2') !== false, 'Follow page tokens');
    expect(strpos($client->calls[2][1], 'state=ARCHIVED') !== false, 'Scan archived notes separately');
    fails(function () use ($store) { $store->file('../anything'); }, 404);
    expect((new FokusButler_Memos_Store($client, $root, 'other-user'))->snapshot()['memos'] === [], 'User mirrors must be isolated');
    $client->pages = [new RuntimeException('Interrupted', 502)];
    fails(function () use ($store) { $store->syncStep(true); }, 502);
    expect(count($store->snapshot()['memos']) === 3, 'Failed refresh must retain previous mirror');
    expect($store->summary()['total'] === 3 && $store->summary()['stale'], 'Failed sync retains and identifies cached status totals');

    $id = '12345678-1234-4234-8234-123456789abc';
    $client->offline = true;
    expect($store->queue($id, '', 'Queued note')['pending'], 'Offline save must queue durably');
    $reopened = new FokusButler_Memos_Store($client, $root, 'ken');
    expect($reopened->snapshot()['pending'][0]['content'] === 'Queued note', 'Pending save must survive a new request');
    $client->offline = false; $client->lostResponse = true;
    expect($reopened->retry($id)['pending'], 'Lost create response must retain pending operation');
    expect($client->creates === 1, 'Only one remote create attempted');
    expect($reopened->retry($id)['saved'], 'Retry must recover the created note by its stable ID');
    expect($client->creates === 1, 'Retry must not duplicate the remote note');
    expect($reopened->snapshot()['pending'] === [], 'Confirmed operation must leave outbox');
    expect($reopened->queue($id, '', 'Queued note')['saved'], 'Duplicate browser submit must be idempotent');
    expect($client->creates === 1, 'Repeated operation must never create again');
    $name = 'memos/' . str_replace('-', '', $id);
    expect($client->remote[$name]['visibility'] === 'PRIVATE', 'New notes must default to private');
    $editId = '22345678-1234-4234-8234-123456789abc';
    expect($store->queue($editId, $name, 'Edited', '2026-10-23T10:00:00Z')['saved'], 'Unchanged remote revision may be edited');
    expect($client->patches === 1, 'Edit must patch content');
    expect($client->calls[count($client->calls) - 1][2] === ['name' => $name, 'content' => 'Edited'], 'Edits must not replace visibility or attachments');
    $conflictId = '32345678-1234-4234-8234-123456789abc';
    expect($store->queue($conflictId, $name, 'Conflicting edit', 'old-time')['conflict'], 'Changed remote note must flag a conflict');
    expect($client->patches === 1, 'Conflict must not overwrite Memos');
    expect($store->snapshot()['pending'][0]['content'] === 'Conflicting edit', 'Conflict must preserve local text');
    $store->cancelPending($conflictId);
    expect($store->snapshot()['pending'] === [], 'Explicit discard must remove only pending copy');
    expect(isset($client->remote[$name]), 'Discarding pending copy must not delete remote note');
    fails(function () use ($store) { $store->queue('bad-id', '', 'text'); }, 400);
    fails(function () use ($store) { $store->queue('42345678-1234-4234-8234-123456789abc', '', '  '); }, 400);
    fails(function () { FokusButler_Memos_Connection::validateUrl('https://token:secret@memos.example'); }, 400);
    fails(function () { FokusButler_Memos_Connection::validateUrl('https://memos.example/api/v1'); }, 400);
    $key = hash('sha256', 'attachments/image1');
    $client->pages = [['memos' => [$first]], []];
    do { $step = $store->syncStep(true); } while ($step['more']);
    expect($client->downloads === 1, 'Unchanged attachments must be reused without another download');
    $oldPath = $store->file($key)['path'];
    $first['attachments'][0]['filename'] = 'updated.png';
    $client->pages = [['memos' => [$first]], []];
    $store->syncStep(true); $store->syncStep(); $store->syncStep();
    expect($store->file($key)['path'] === $oldPath && is_file($oldPath), 'Staged attachment changes must not alter the published mirror');
    expect(!$store->syncStep()['more'], 'Changed-attachment scan must publish');
    expect($store->file($key)['path'] !== $oldPath, 'Updated attachment must use a new immutable cache file');
    expect(!is_file($oldPath), 'Old attachment bytes must be pruned after publication');
    $first['attachments'][0]['filename'] = 'retry.png'; $client->downloadFail = true;
    $client->pages = [['memos' => [$first]], []];
    do { $step = $store->syncStep(true); } while ($step['more']);
    expect(!$store->snapshot()['files']['attachments/image1']['cached'], 'Failed attachment must be marked unavailable');
    expect(strpos($store->snapshot()['syncError'], 'attachment') !== false, 'Incomplete attachment status must be visible');
    expect(count(array_filter($store->snapshot()['memos'], static function ($m) { return $m['name'] === 'memos/first'; })) === 1, 'Attachment failure must not hide note text');
    fails(function () use ($store, $key) { $store->file($key); }, 404);
    $client->downloadFail = false; $client->pages = [['memos' => [$first]], []];
    do { $step = $store->syncStep(true); } while ($step['more']);
    expect($store->snapshot()['files']['attachments/image1']['cached'], 'Next sync must retry the missing attachment');
    $removedPath = $store->file($key)['path']; $client->pages = [[], []];
    do { $step = $store->syncStep(true); } while ($step['more']);
    expect(!array_filter($store->snapshot()['memos'], static function ($m) { return $m['name'] === 'memos/first'; }), 'Successful full scan must reconcile removed remote notes');
    expect($store->snapshot()['files'] === [] && !is_file($removedPath), 'Removed attachments must be removed from metadata and disk');
    echo "PASS: $checks Memos persistence, synchronization and conflict checks.\n";
} finally {
    if (is_dir($root)) { $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); } rmdir($root); }
}
