<?php
if (!defined('DOKU_INC')) die();

/** Explicit export into ordinary DokuWiki pages and media; never changes Memos. */
class FokusButler_Memos_Promote
{
    public static function prepare($store, $baseUrl, $name, $target, $content, $version, $copyAttachments)
    {
        $id = cleanID($target);
        if ($id === '' || strlen($id) > 512 || $id !== trim($target)) throw new RuntimeException('Use a valid wiki page ID, for example jots:measurement-notes.', 400);
        if (!actionOK('edit') || !actionOK('save') || auth_quickaclcheck($id) < AUTH_CREATE) throw new RuntimeException('You cannot create a wiki page at that destination.', 403);
        if (page_exists($id)) throw new RuntimeException('That wiki page already exists. Choose another page ID; existing pages are never overwritten.', 409);
        if (!is_string($content) || strlen($content) > 1000000) throw new RuntimeException('The note is too large to create a page.', 400);
        $memo = $store->memo($name);
        if (($memo['updateTime'] ?? '') !== $version) throw new RuntimeException('This jot changed. Close this dialog and open the latest version before creating a page.', 409);
        $files = [];
        if ($copyAttachments) foreach ($memo['attachments'] ?? [] as $attachment) {
            $key = hash('sha256', $attachment['name']);
            $file = $store->file($key);
            $filename = str_replace(':', '_', cleanID(basename(str_replace('\\', '/', $file['filename']))));
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($filename === '' || $ext === '' || !isset(getMimeTypes()[$ext])) throw new RuntimeException('An attachment has an unsupported wiki media extension. Create the page without copying attachments, or change the filename in Memos.', 400);
            $mediaId = cleanID($id . ':' . $filename);
            foreach ($files as $planned) {
                if ($planned['id'] === $mediaId && !hash_equals(hash_file('sha256', $planned['path']), hash_file('sha256', $file['path']))) throw new RuntimeException('Two attachments have the same filename but different contents. Rename one in Memos before copying attachments.', 409);
            }
            $auth = auth_quickaclcheck(function_exists('mediaAclPath') ? mediaAclPath($mediaId) : getNS($mediaId) . ':*');
            if ($auth < AUTH_UPLOAD) throw new RuntimeException('You cannot upload attachments into the destination’s media namespace.', 403);
            $existing = mediaFN($mediaId);
            if (is_file($existing) && !hash_equals(hash_file('sha256', $existing), hash_file('sha256', $file['path']))) throw new RuntimeException('A different file already exists at the attachment destination. Choose another page namespace.', 409);
            $image = in_array($file['type'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'], true);
            $media = static function ($label) use ($mediaId, $image) {
                $label = str_replace(['|', '{', '}', "\r", "\n"], ' ', $label);
                return '{{:' . $mediaId . ($image ? '' : '?linkonly') . '|' . $label . '}}';
            };
            $path = '/file/' . $attachment['name'] . '/';
            $variants = [$path . $attachment['filename'], $path . rawurlencode($attachment['filename'])];
            $variants = array_merge(array_map(static function ($v) use ($baseUrl) { return rtrim($baseUrl, '/') . $v; }, $variants), $variants);
            if (!empty($attachment['externalLink'])) array_unshift($variants, $attachment['externalLink']);
            usort($variants, static function ($a, $b) { return strlen($b) <=> strlen($a); });
            $referenced = false;
            foreach ($variants as $from) {
                // Replace the whole Markdown expression, not just its URL: local
                // /_media URLs are otherwise interpreted as relative media IDs.
                $pattern = '~!?\\[((?:\\\\.|[^\\]\\\\])*)\\]\\(<?' . preg_quote($from, '~') . '>?\\)~u';
                $content = preg_replace_callback($pattern, static function ($match) use ($media, &$referenced) {
                    $referenced = true;
                    return $media($match[1]);
                }, $content);
                if (strpos($content, $from) !== false) {
                    $content = str_replace($from, $media($file['filename']), $content);
                    $referenced = true;
                }
            }
            if (!$referenced) $content .= "\n\n" . $media($file['filename']);
            $files[] = $file + ['id' => $mediaId, 'ext' => $ext, 'auth' => $auth, 'exists' => is_file($existing)];
        }
        $content = rtrim($content) . "\n\n[Original jot in Memos](" . rtrim($baseUrl, '/') . '/' . $memo['name'] . ")\n";
        return ['id' => $id, 'source' => $content, 'files' => $files];
    }
    public static function create($plan)
    {
        global $conf;
        $mutex = fopen(rtrim($conf['metadir'], '/') . '/.fokus-jots-promote.lock', 'c');
        if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) { if ($mutex) fclose($mutex); throw new RuntimeException('Another page is being created. Retry in a moment.', 423); }
        $id = $plan['id']; $locked = false;
        try {
            if (page_exists($id)) throw new RuntimeException('That wiki page was just created. Choose another page ID.', 409);
            checklock($id);
            if (is_file(wikiLockFN($id))) throw new RuntimeException('This page is currently being edited. Choose another page ID.', 409);
            lock($id); $locked = true;
            if (page_exists($id)) throw new RuntimeException('That wiki page was just created. Choose another page ID.', 409);
            foreach ($plan['files'] as $file) {
                if ($file['exists']) continue;
                $result = media_save(['name' => $file['path'], 'mime' => $file['type'], 'ext' => $file['ext']], $file['id'], false, $file['auth'], 'copy');
                if (!is_string($result) || $result !== $file['id']) throw new RuntimeException('A wiki attachment could not be copied. Your original jot is retained; check Media Manager permissions before retrying.', 400);
            }
            saveWikiText($id, $plan['source'], 'Created from a Memos jot');
            clearstatcache(true, wikiFN($id));
            if (!page_exists($id)) throw new RuntimeException('DokuWiki could not create this page. Your jot is retained.');
            return ['id' => $id, 'url' => wl($id, '', true, '&'), 'attachments' => count($plan['files'])];
        } finally { if ($locked) unlock($id); flock($mutex, LOCK_UN); fclose($mutex); }
    }
}
