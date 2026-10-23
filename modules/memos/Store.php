<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Connection.php';

/** Persistent mirror and outbox, isolated by instance, token and wiki user. */
class FokusButler_Memos_Store
{
    private $connection;
    private $directory;
    private $attachmentLimit;
    public function __construct($connection, $root, $user, $attachmentLimit = 104857600)
    {
        $this->connection = $connection;
        $this->directory = rtrim($root, '/') . '/fokusbutler/memos/' . $connection->scope() . '/' . hash('sha256', $user);
        $this->attachmentLimit = $attachmentLimit;
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('Could not create local Jots storage.');
        if (!is_dir($this->directory . '/files')) mkdir($this->directory . '/files', 0700);
    }
    private function read($file, $default = [])
    {
        $path = $this->directory . '/' . $file . '.json';
        if (!is_file($path)) return $default;
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) throw new RuntimeException('The local Jots store could not be read. Your files have been retained.');
        return $data;
    }
    private function write($file, array $data)
    {
        $path = $this->directory . '/' . $file . '.json';
        $temp = tempnam($this->directory, '.write-');
        if ($temp === false) throw new RuntimeException('Could not write local Jots storage.');
        try {
            chmod($temp, 0600);
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $path)) throw new RuntimeException('Could not save Jots locally.');
        } finally { if (is_file($temp)) unlink($temp); }
    }
    private function locked($operation)
    {
        $lock = fopen($this->directory . '/store.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            throw new RuntimeException('Jots is finishing another operation. Retry in a moment.', 423);
        }
        try { return $operation(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public function snapshot()
    {
        $snapshot = $this->read('snapshot', ['memos' => [], 'files' => [], 'syncedAt' => 0]);
        $outbox = $this->read('outbox');
        $memos = $snapshot['memos'];
        $pending = [];
        foreach ($outbox as $id => $op) {
            $pending[] = ['id' => $id, 'name' => $op['name'], 'content' => $op['content'], 'error' => $op['error'] ?? '', 'conflict' => !empty($op['conflict'])];
        }
        $files = [];
        foreach ($snapshot['files'] as $key => $file) $files[$file['attachment']['name']] = ['key' => $key, 'cached' => !empty($file['cached']), 'error' => $file['error'] ?? ''];
        $stage = $this->read('stage');
        return ['memos' => array_values($memos), 'files' => $files, 'pending' => $pending,
            'syncedAt' => $snapshot['syncedAt'], 'syncing' => (bool)$stage,
            'syncError' => $this->read('status')['error'] ?? '', 'url' => $this->connection->baseUrl()];
    }
    private function collectFiles(array $memos)
    {
        $files = [];
        foreach ($memos as $memo) foreach ($memo['attachments'] ?? [] as $attachment) {
            FokusButler_Memos_Connection::resource($attachment['name'] ?? '', 'attachments');
            if (!is_string($attachment['filename'] ?? null) || strlen($attachment['filename']) > 1024) continue;
            $key = hash('sha256', $attachment['name']);
            $files[$key] = ['attachment' => $attachment, 'fingerprint' => hash('sha256', json_encode($attachment)), 'cached' => false];
        }
        return $files;
    }
    /** One bounded page or one attachment per request; incomplete scans never replace the mirror. */
    public function syncStep($force = false)
    {
        return $this->locked(function () use ($force) {
            $snapshot = $this->read('snapshot', ['memos' => [], 'files' => [], 'syncedAt' => 0]);
            $stage = $this->read('stage');
            if (!$stage && !$force && time() - $snapshot['syncedAt'] < 60) return ['more' => false];
            if (!$stage) $stage = ['phase' => 'NORMAL', 'cursor' => '', 'seen' => [], 'memos' => [], 'startedAt' => time()];
            if (time() - $stage['startedAt'] > 3600) $stage = ['phase' => 'NORMAL', 'cursor' => '', 'seen' => [], 'memos' => [], 'startedAt' => time()];
            try {
                if ($stage['phase'] !== 'FILES') {
                    $query = ['pageSize' => 100, 'state' => $stage['phase'], 'orderBy' => 'create_time desc'];
                    if ($stage['cursor'] !== '') $query['pageToken'] = $stage['cursor'];
                    $data = $this->connection->request('GET', '/memos?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
                    if (isset($data['memos']) && !is_array($data['memos'])) throw new RuntimeException('Memos returned an invalid note list.');
                    foreach ($data['memos'] ?? [] as $memo) {
                        FokusButler_Memos_Connection::resource($memo['name'] ?? '');
                        if (!is_string($memo['content'] ?? null)) throw new RuntimeException('Memos returned an incomplete note.');
                        $stage['memos'][$memo['name']] = $memo;
                    }
                    $next = $data['nextPageToken'] ?? '';
                    if (!is_string($next) || ($next !== '' && isset($stage['seen'][$stage['phase'] . $next]))) throw new RuntimeException('Memos returned a repeated pagination token.');
                    if ($next !== '') { $stage['seen'][$stage['phase'] . $next] = true; $stage['cursor'] = $next; }
                    elseif ($stage['phase'] === 'NORMAL') { $stage['phase'] = 'ARCHIVED'; $stage['cursor'] = ''; }
                    else {
                        $stage['phase'] = 'FILES';
                        $stage['files'] = $this->collectFiles($stage['memos']);
                        $stage['queue'] = [];
                        foreach ($stage['files'] as $key => &$file) {
                            $old = $snapshot['files'][$key] ?? [];
                            if (!empty($old['cached']) && ($old['fingerprint'] ?? '') === $file['fingerprint']
                                && is_file($this->directory . '/files/' . $key . '-' . $file['fingerprint'])) $file['cached'] = true;
                            else $stage['queue'][] = $key;
                        }
                        unset($file);
                    }
                    $this->write('stage', $stage);
                    $this->write('status', []);
                    return ['more' => true, 'message' => count($stage['memos']) . ' notes fetched'];
                }
                if ($stage['queue']) {
                    $key = array_shift($stage['queue']);
                    $file = $stage['files'][$key];
                    $old = $snapshot['files'][$key] ?? [];
                    $path = $this->directory . '/files/' . $key . '-' . $file['fingerprint'];
                    if (!empty($old['cached']) && ($old['fingerprint'] ?? '') === $file['fingerprint'] && is_file($path)) $file['cached'] = true;
                    else {
                        try {
                            if ((int)($file['attachment']['size'] ?? 0) > $this->attachmentLimit) throw new RuntimeException('Attachment exceeds the local size limit. Increase it in Jots settings.');
                            $this->connection->download($file['attachment'], $path . '.part', $this->attachmentLimit);
                            chmod($path . '.part', 0600);
                            if (!rename($path . '.part', $path)) throw new RuntimeException('Could not publish the cached attachment.');
                            $file['cached'] = true;
                        } catch (Throwable $error) { $file['error'] = $error->getMessage(); @unlink($path . '.part'); }
                    }
                    $stage['files'][$key] = $file;
                    $this->write('stage', $stage);
                    return ['more' => true, 'message' => count($stage['queue']) . ' attachments remaining'];
                }
                // Merge saves completed since the scan began so a late scan cannot roll them back.
                foreach ($snapshot['memos'] as $name => $memo) {
                    if (($memo['_localSavedAt'] ?? 0) >= $stage['startedAt']) $stage['memos'][$name] = $memo;
                }
                $this->write('snapshot', ['memos' => $stage['memos'], 'files' => $stage['files'], 'syncedAt' => time()]);
                $this->write('stage', []);
                $missing = count(array_filter($stage['files'], static function ($f) { return empty($f['cached']); }));
                $this->write('status', ['error' => $missing ? $missing . ' attachment(s) are not cached. Refresh to retry.' : '']);
                $retained = [];
                foreach ($stage['files'] as $key => $file) $retained[$key . '-' . $file['fingerprint']] = true;
                foreach (glob($this->directory . '/files/*') ?: [] as $path) {
                    if (preg_match('/^[a-f0-9]{64}-[a-f0-9]{64}$/D', basename($path)) && !isset($retained[basename($path)])) @unlink($path);
                }
                return ['more' => false];
            } catch (Throwable $error) {
                $this->write('status', ['error' => $error->getMessage()]);
                throw $error;
            }
        });
    }
    /** Persist before attempting a remote write. Stable operation IDs make retries safe. */
    public function queue($id, $name, $content, $baseUpdateTime = '')
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new RuntimeException('Invalid save operation.', 400);
        if (!is_string($content) || trim($content) === '' || strlen($content) > 1000000) throw new RuntimeException('Write a note of up to 1 MB before saving.', 400);
        $name = $name !== '' ? FokusButler_Memos_Connection::resource($name) : '';
        return $this->locked(function () use ($id, $name, $content, $baseUpdateTime) {
            $done = $this->read('completed');
            if (isset($done[$id])) return ['saved' => true, 'name' => $done[$id]['name']];
            $outbox = $this->read('outbox');
            $op = ['name' => $name ?: 'memos/' . str_replace('-', '', $id), 'create' => $name === '', 'content' => $content, 'baseUpdateTime' => $baseUpdateTime, 'queuedAt' => time()];
            if (isset($outbox[$id]) && ($outbox[$id]['content'] !== $content || $outbox[$id]['name'] !== $op['name'])) throw new RuntimeException('This save is already queued. Finish it before changing the draft.', 409);
            $outbox[$id] = $outbox[$id] ?? $op;
            $this->write('outbox', $outbox);
            return $this->deliverLocked($id);
        });
    }
    public function retry($id) { return $this->locked(function () use ($id) { return $this->deliverLocked($id); }); }
    private function deliverLocked($id)
    {
        $outbox = $this->read('outbox');
        if (!isset($outbox[$id])) return ['saved' => true];
        $op = $outbox[$id];
        try {
            $remote = null;
            try { $remote = $this->connection->request('GET', '/' . $op['name']); }
            catch (RuntimeException $error) { if (!$op['create'] || $error->getCode() !== 404) throw $error; }
            if ($op['create'] && !$remote) {
                $remote = $this->connection->request('POST', '/memos?memoId=' . rawurlencode(substr($op['name'], 6)), ['content' => $op['content'], 'visibility' => 'PRIVATE', 'state' => 'NORMAL']);
            } elseif ($op['create'] && ($remote['content'] ?? '') !== $op['content']) {
                throw new RuntimeException('A different note already uses this ID. The pending text is retained.', 409);
            } elseif (!$op['create'] && ($remote['content'] ?? '') !== $op['content']) {
                if ($op['baseUpdateTime'] === '' || ($remote['updateTime'] ?? '') !== $op['baseUpdateTime']) throw new RuntimeException('This note changed in Memos. Open its latest version and copy your pending changes into a new edit.', 409);
                $remote = $this->connection->request('PATCH', '/' . $op['name'] . '?updateMask=content', ['name' => $op['name'], 'content' => $op['content']]);
            }
            if (!is_array($remote) || ($remote['name'] ?? '') !== $op['name'] || !is_string($remote['content'] ?? null)) throw new RuntimeException('Memos did not confirm the saved note. Retry this pending save.');
            $snapshot = $this->read('snapshot', ['memos' => [], 'files' => [], 'syncedAt' => 0]);
            $remote['_localSavedAt'] = time();
            $snapshot['memos'][$op['name']] = $remote;
            $this->write('snapshot', $snapshot);
            $done = $this->read('completed');
            $done[$id] = ['name' => $op['name'], 'time' => time()];
            $this->write('completed', $done);
            unset($outbox[$id]); $this->write('outbox', $outbox);
            return ['saved' => true, 'name' => $op['name']];
        } catch (Throwable $error) {
            $outbox[$id]['error'] = $error->getMessage();
            $outbox[$id]['conflict'] = $error->getCode() === 409;
            $this->write('outbox', $outbox);
            return ['saved' => false, 'pending' => true, 'error' => $error->getMessage(), 'conflict' => $error->getCode() === 409];
        }
    }
    public function cancelPending($id)
    {
        return $this->locked(function () use ($id) { $outbox = $this->read('outbox'); unset($outbox[$id]); $this->write('outbox', $outbox); return []; });
    }
    /** Delete one remotely stored note, then remove it from the local snapshot. */
    public function deleteMemo($name)
    {
        $name = FokusButler_Memos_Connection::resource($name);
        return $this->locked(function () use ($name) {
            $snapshot = $this->read('snapshot', ['memos' => [], 'files' => [], 'syncedAt' => 0]);
            if (!isset($snapshot['memos'][$name])) throw new RuntimeException('Refresh Jots; this note is no longer in the local list.', 404);
            foreach ($this->read('outbox') as $op) if (($op['name'] ?? '') === $name) {
                throw new RuntimeException('Resolve this note’s pending save before deleting it.', 409);
            }
            $this->connection->request('DELETE', '/' . $name);
            unset($snapshot['memos'][$name]);
            $this->write('snapshot', $snapshot);
            return ['deleted' => true, 'name' => $name];
        });
    }
    public function file($key)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('Attachment not found.', 404);
        $snapshot = $this->read('snapshot');
        $file = $snapshot['files'][$key] ?? [];
        $path = $this->directory . '/files/' . $key . '-' . ($file['fingerprint'] ?? '');
        if (empty($file['cached']) || !is_file($path)) throw new RuntimeException('This attachment is not cached yet. Refresh Jots to retry.', 404);
        return ['path' => $path, 'filename' => $file['attachment']['filename'], 'type' => (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream'];
    }
    public function memo($name)
    {
        FokusButler_Memos_Connection::resource($name);
        $snapshot = $this->read('snapshot');
        if (!isset($snapshot['memos'][$name])) throw new RuntimeException('Refresh Jots before creating a page from this note.', 404);
        foreach ($this->read('outbox') as $op) if ($op['name'] === $name) throw new RuntimeException('Finish this note’s pending save before turning it into a wiki page.', 409);
        return $snapshot['memos'][$name];
    }
}
