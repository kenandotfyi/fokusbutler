<?php
if (!defined('DOKU_INC')) die();

/** Native media operations plus the installed Move plugin's link-preserving plan. */
final class FokusButler_Media_Manage
{
    public static function acl($id)
    {
        return auth_quickaclcheck(function_exists('mediaAclPath') ? mediaAclPath($id) : ltrim(getNS($id) . ':*', ':'));
    }

    public static function capabilities($id)
    {
        global $conf;
        $auth = self::acl($id);
        return [
            'delete' => $auth >= AUTH_DELETE,
            'replace' => $auth >= (!empty($conf['mediarevisions']) ? AUTH_UPLOAD : AUTH_DELETE),
            'move' => $auth >= AUTH_DELETE && (bool)plugin_load('helper', 'move_op'),
            'metadata' => $auth >= AUTH_UPLOAD && mimetype($id)[1] === 'image/jpeg',
            'revisions' => !empty($conf['mediarevisions']),
        ];
    }

    public static function source($id, $allowDeleted = false)
    {
        if (!$id || cleanID($id) !== $id) throw new RuntimeException('Use a valid media ID.', 400);
        if (self::acl($id) < AUTH_READ) throw new RuntimeException('You cannot access this media file.', 403);
        self::safePath($id);
        if (!is_file(mediaFN($id)) && !($allowDeleted && is_file(mediaMetaFN($id, '.changes')))) throw new RuntimeException('This media file no longer exists.', 404);
    }

    private static function safePath($id)
    {
        global $conf;
        $root = realpath($conf['mediadir']);
        if (!$root) throw new RuntimeException('The media directory is unavailable.', 500);
        $path = mediaFN($id);
        if (strpos($path, rtrim($conf['mediadir'], '/') . '/') !== 0) throw new RuntimeException('Invalid media destination.', 400);
        $relative = substr($path, strlen(rtrim($conf['mediadir'], '/')) + 1);
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('Invalid media destination.', 400);
            $cursor .= '/' . $part;
            if (is_link($cursor)) throw new RuntimeException('Media actions cannot follow filesystem links.', 400);
        }
    }

    public static function fingerprint($id)
    {
        clearstatcache(true, mediaFN($id));
        if (!is_file(mediaFN($id))) return hash('sha256', 'deleted:' . (is_file(mediaMetaFN($id, '.changes')) ? hash_file('sha256', mediaMetaFN($id, '.changes')) : ''));
        $hash = hash_file('sha256', mediaFN($id));
        if ($hash === false) throw new RuntimeException('The media file could not be read.', 500);
        return hash('sha256', filemtime(mediaFN($id)) . ':' . filesize(mediaFN($id)) . ':' . $hash);
    }

    public static function metadataFields()
    {
        $fields = [];
        foreach (getConfigFiles('mediameta') as $file) if (is_file($file)) include $file;
        return array_filter($fields, static function ($field) { return !empty($field[0]); });
    }

    public static function details($id)
    {
        global $lang;
        self::source($id);
        $capabilities = self::capabilities($id);
        $history = self::history($id);
        $metadata = [];
        if (mimetype($id)[1] === 'image/jpeg') {
            $image = new JpegMeta(mediaFN($id));
            foreach (self::metadataFields() as $field) {
                $value = $image->getField(array_merge([$field[0]], $field[3] ?? []));
                $metadata[] = ['key' => $field[0], 'label' => $lang[$field[1]] ?? $field[1], 'multiline' => $field[2] !== 'text', 'value' => (string)($value ?: '')];
            }
        }
        return ['fingerprint' => self::fingerprint($id), 'allowed' => $capabilities, 'history' => $history['history'], 'historyMore' => $history['more'], 'historyOffset' => $history['offset'], 'metadata' => $metadata];
    }

    public static function history($id, $offset = 0)
    {
        global $conf;
        self::source($id);
        $history = [];
        $offset = max(0, (int)$offset);
        $revisions = !empty($conf['mediarevisions']) ? (new \dokuwiki\ChangeLog\MediaChangeLog($id))->getRevisions($offset, 31) : [];
        $more = count($revisions) > 30;
        $log = new \dokuwiki\ChangeLog\MediaChangeLog($id);
        foreach (array_unique(array_slice($revisions, 0, 30)) as $rev) {
            if (!is_file(mediaFN($id, $rev))) continue;
            $entry = $log->getRevisionInfo($rev);
            $history[] = ['revision' => (int)$rev, 'summary' => (string)($entry['sum'] ?? ''), 'url' => ml($id, ['rev' => $rev], true, '&')];
        }
        return ['history' => $history, 'offset' => $offset + min(30, count($revisions)), 'more' => $more];
    }

    public static function change($operation, $input)
    {
        global $conf;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('File actions require POST.', 405);
        if (!checkSecurityToken()) throw new RuntimeException('Your session expired. Reload the page before changing files.', 403);
        // Share the sidebar's lock so page and media moves cannot overlap a plan.
        $lock = fopen($conf['metadir'] . '/.fokus-sidebar-actions.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); throw new RuntimeException('Another file action is running. Try again shortly.', 409); }
        try {
            $id = $input->str('id');
            if ($operation === 'namespace') {
                if (!$id || cleanID($id) !== $id) throw new RuntimeException('Use a valid namespace ID, for example drawings:schematics.', 400);
                if (self::acl($id . ':fokus_media_probe.bin') < AUTH_UPLOAD) throw new RuntimeException('You cannot create this namespace.', 403);
                self::safePath($id . ':fokus_media_probe.bin');
                $directory = dirname(mediaFN($id . ':fokus_media_probe.bin'));
                if (is_dir($directory)) throw new RuntimeException('That namespace already exists.', 409);
                io_createNamespace($id . ':fokus_media_probe.bin', 'media');
                if (!is_dir($directory)) throw new RuntimeException('DokuWiki could not create the namespace.', 500);
                return ['id' => $id, 'namespace' => $id, 'created' => true];
            }
            self::source($id, $operation === 'restore');
            $allowed = self::capabilities($id);
            $permission = in_array($operation, ['replace', 'restore'], true) ? 'replace' : $operation;
            if (empty($allowed[$permission])) throw new RuntimeException('You do not have permission for this action, or its required module is unavailable.', 403);
            if (!hash_equals(self::fingerprint($id), $input->str('fingerprint'))) throw new RuntimeException('This file changed. Select it again before continuing.', 409);
            // Give rapid replacements distinct timestamps in DokuWiki's revision store.
            if (in_array($operation, ['replace', 'restore', 'metadata'], true) && is_file(mediaFN($id)) && filemtime(mediaFN($id)) === time()) {
                usleep((int)((1 - (microtime(true) - floor(microtime(true)))) * 1000000) + 1000);
            }
            switch ($operation) {
                case 'delete':
                    $revision = (int)filemtime(mediaFN($id));
                    $result = media_delete($id, self::acl($id));
                    if ($result & DOKU_MEDIA_INUSE) throw new RuntimeException('DokuWiki prevents deletion because this file is used by one or more pages.', 409);
                    if (!($result & DOKU_MEDIA_DELETED)) throw new RuntimeException('DokuWiki could not delete this file.', 409);
                    return ['id' => $id, 'deleted' => true, 'revision' => $allowed['revisions'] && is_file(mediaFN($id, $revision)) ? $revision : null, 'fingerprint' => self::fingerprint($id)];
                case 'move':
                    return self::move($id, $input->str('destination'));
                case 'replace':
                    $file = $_FILES['upload'] ?? null;
                    if (!is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed or exceeded the server upload limit.', 400);
                    [$ext, $mime] = mimetype($id);
                    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== strtolower($ext)) throw new RuntimeException('The new version must have the same file extension.', 400);
                    self::saved(media_save(['name' => $file['tmp_name'], 'ext' => $ext, 'mime' => $mime], $id, true, self::acl($id), 'copy_uploaded_file'));
                    break;
                case 'restore':
                    $rev = $input->int('revision');
                    if (!$allowed['revisions'] || !$rev || !is_file(mediaFN($id, $rev))) throw new RuntimeException('That revision is unavailable.', 404);
                    $previousRev = $GLOBALS['REV'] ?? null;
                    $GLOBALS['REV'] = $rev;
                    try { self::saved(media_restore($id, $rev)); } finally { $GLOBALS['REV'] = $previousRev; }
                    break;
                case 'metadata':
                    $values = $input->arr('metadata');
                    $data = [];
                    foreach (self::metadataFields() as $field) {
                        if (!isset($values[$field[0]]) || !is_string($values[$field[0]]) || strlen($values[$field[0]]) > 10000) throw new RuntimeException('Invalid image metadata.', 400);
                        $data[$field[0]] = $values[$field[0]];
                    }
                    self::saved(media_metasave($id, $data));
                    break;
                default: throw new RuntimeException('Unknown file action.', 400);
            }
            return ['id' => $id];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function saved($result)
    {
        if (!is_string($result) || $result === '') throw new RuntimeException(is_array($result) ? html_entity_decode(strip_tags($result[0]), ENT_QUOTES, 'UTF-8') : 'DokuWiki could not save this change.', 400);
    }

    private static function move($id, $destination)
    {
        if (!$destination || cleanID($destination) !== $destination || $destination === $id) throw new RuntimeException('Enter a different, valid destination media ID.', 400);
        if (self::acl($destination) < AUTH_UPLOAD) throw new RuntimeException('You cannot upload into the destination namespace.', 403);
        self::safePath($destination);
        if (file_exists(mediaFN($destination))) throw new RuntimeException('A file already exists at that destination.', 409);
        if (pathinfo($destination, PATHINFO_EXTENSION) !== pathinfo($id, PATHINFO_EXTENSION)) throw new RuntimeException('Moving or renaming cannot change the file extension.', 400);
        $operator = plugin_load('helper', 'move_op');
        $plan = plugin_load('helper', 'move_plan', true);
        if (!$operator || !$plan || $plan->isCommited()) throw new RuntimeException('The Move plugin is unavailable or another move is in progress.', 409);
        if (!$operator->checkMedia($id, $destination)) throw new RuntimeException('The Move plugin cannot move this file with your permissions.', 409);
        $pages = class_exists('dokuwiki\\Search\\MetadataSearch') ? (new \dokuwiki\Search\MetadataSearch())->mediause($id, true) : ft_mediause($id, true);
        $lockedPages = [];
        try {
            foreach ($pages as $page) {
                checklock($page);
                if (file_exists(wikiLockFN($page))) throw new RuntimeException('Close editors for pages using this file before moving it.', 409);
                lock($page); $lockedPages[] = $page;
            }
            $plan->setOption('autorewrite', true);
            $plan->setOption('autoskip', false);
            $plan->addMediaMove($id, $destination);
            if (!$plan->commit()) throw new RuntimeException('The Move plugin could not prepare this move.', 409);
            do {
                $remaining = $plan->nextStep();
                if ($remaining === false) throw new RuntimeException('The move needs attention in DokuWiki’s Move manager before you retry.', 409);
            } while ($remaining > 0);
        } finally { foreach ($lockedPages as $page) unlock($page); }
        clearstatcache();
        if (file_exists(mediaFN($id)) || !is_file(mediaFN($destination))) throw new RuntimeException('The Move plugin could not finish this move. Check its manager before retrying.', 409);
        return ['id' => $destination, 'from' => $id, 'moved' => true];
    }
}
