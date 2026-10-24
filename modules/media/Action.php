<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Manage.php';

/** Editor media picker. DokuWiki owns validation, storage and upload hooks. */
class FokusButler_Module_Media_Action extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('media')) return;
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'addToolbarButton');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    public function addToolbarButton(Doku_Event $event, $param)
    {
        $event->data = array_values(array_filter($event->data, static function ($entry) {
            return !in_array($entry['type'] ?? '', ['mediapopup', 'fokusbutlermedia'], true);
        }));
        $event->data[] = [
            'type' => 'fokusbutlermedia', 'title' => 'Media Library',
            'icon' => '../../plugins/fokusbutler/modules/media/images/images-duotone.svg',
            'ajaxUrl' => DOKU_BASE . 'lib/exe/ajax.php',
            'namespace' => (string)getNS($GLOBALS['ID'] ?? ''),
        ];
    }

    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'fokusbutler_media') return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        // Keep diagnostics from core/plugin upload hooks out of the JSON body.
        ob_start();
        try {
            require_once DOKU_INC . 'inc/media.php';
            require_once DOKU_INC . 'inc/search.php';
            global $INPUT;
            if (!actionOK('media')) throw new RuntimeException('Media access is disabled.', 403);
            $ns = cleanID($INPUT->str('ns'));
            $operation = $INPUT->str('operation');
            if (in_array($operation, ['delete', 'move', 'replace', 'restore', 'metadata', 'namespace'], true)) {
                $result = FokusButler_Media_Manage::change($operation, $INPUT->post);
            } elseif ($operation === 'history') {
                $result = FokusButler_Media_Manage::history(cleanID($INPUT->str('id')), $INPUT->int('offset'));
            } elseif ($operation === 'details') {
                $id = cleanID($INPUT->str('id'));
                $result = $this->references($id) + FokusButler_Media_Manage::details($id);
            } elseif ($operation === 'upload') {
                $result = $this->upload($ns);
            } elseif ($INPUT->str('operation') === 'references') {
                $result = $this->references(cleanID($INPUT->str('id')));
            } elseif ($operation === '' || $operation === 'list') {
                $result = $this->listing($ns, trim($INPUT->str('q')), max(0, $INPUT->int('offset')));
            } else { throw new RuntimeException('Unknown media operation.', 400); }
            ob_end_clean();
            echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (Throwable $error) {
            ob_end_clean();
            $status = (int)$error->getCode();
            http_response_code($status >= 400 && $status <= 599 ? $status : 500);
            if (!$error instanceof RuntimeException) error_log('Fokus Media Library: ' . $error->getMessage());
            echo json_encode(['error' => $error instanceof RuntimeException ? $error->getMessage() : 'DokuWiki could not complete the media request. Check the server log.'], JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    protected function acl($id)
    {
        return auth_quickaclcheck(function_exists('mediaAclPath') ? mediaAclPath($id) : ltrim(getNS($id) . ':*', ':'));
    }

    /** Counts current files only, with the same namespace ACL and symlink rules as the library. */
    public function getSummaryStats()
    {
        if (!actionOK('media')) return null;
        require_once DOKU_INC . 'inc/search.php';
        global $conf;
        $base = realpath($conf['mediadir']);
        if (!$base) throw new RuntimeException('The media directory is unavailable.');
        $counts = ['total' => 0, 'images' => 0, 'pdfs' => 0, 'audio' => 0, 'video' => 0, 'other' => 0];
        $unused = [];
        search($unused, $base, function (&$data, $root, $path, $type) use ($base, &$counts) {
            if (is_link($root . '/' . $path)) return false;
            $real = realpath($root . '/' . $path);
            if (!$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return false;
            // A readable child may live below an unreadable parent.
            if ($type === 'd') return true;
            $id = pathID($path, true);
            if ($id !== cleanID($id) || $this->acl($id) < AUTH_READ) return false;
            [, $mime] = mimetype($id);
            $kind = str_starts_with($mime, 'image/') ? 'images' : ($mime === 'application/pdf' ? 'pdfs' :
                (str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'video/') ? 'video' : 'other')));
            $counts[$kind]++; $counts['total']++;
            return false;
        }, []);
        return $counts;
    }

    public function listing($ns, $query, $offset)
    {
        global $conf;
        $base = realpath($conf['mediadir']);
        if (!$base) throw new RuntimeException('The media directory is unavailable.', 500);
        $files = [];
        $folders = [];
        // Traverse unreadable parents too: a child can have a different ACL.
        // Never follow symlinks outside (or back into) the media tree.
        $callback = function (&$data, $root, $path, $type, $level, $opts) use (&$folders, $ns, $query, $base) {
            if (is_link($root . '/' . $path)) return false;
            $real = realpath($root . '/' . $path);
            if (!$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return false;
            $id = pathID($path, true);
            if ($type === 'd') {
                if ($this->acl($id . ':fokus_media_probe.bin') >= AUTH_READ) $folders[$id] = $id;
                return true;
            }
            if ($this->acl($id) < AUTH_READ) return false;
            if ($query === '' ? (string)getNS($id) !== $ns : ($ns !== '' && strpos($id, $ns . ':') !== 0)) return false;
            if ($query !== '' && stripos($id, $query) === false) return false;
            return search_media($data, $root, $path, $type, $level, []);
        };
        search($files, $base, $callback, []);
        usort($files, static function ($a, $b) { return strnatcasecmp($a['id'], $b['id']); });
        natcasesort($folders);
        $items = [];
        foreach (array_slice($files, $offset, 80) as $file) {
            $id = $file['id'];
            [, $mime] = mimetype($id);
            $image = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'], true);
            $dimensions = $image ? @getimagesize(mediaFN($id)) : false;
            $items[] = [
                'id' => $id, 'name' => noNS($id), 'size' => $file['size'], 'image' => $image,
                'canMove' => FokusButler_Media_Manage::capabilities($id)['move'],
                'mime' => $mime, 'width' => $dimensions ? $dimensions[0] : null,
                'height' => $dimensions ? $dimensions[1] : null,
                'url' => ml($id, ['cache' => 'cache', 't' => $file['mtime']], true, '&'),
                'manageUrl' => media_managerURL(['ns' => (string)getNS($id), 'image' => $id, 'tab_details' => 'view'], '&'),
                'thumbnail' => $image ? ml($id, ['w' => 240, 'h' => 180, 'cache' => 'cache', 't' => $file['mtime']], true, '&') : null,
            ];
        }
        return [
            'items' => $items, 'folders' => array_values($folders), 'namespace' => $ns,
            'uploadNamespaces' => array_values(array_filter(array_merge([''], $folders), static function ($folder) { return FokusButler_Media_Manage::acl($folder === '' ? 'fokus_media_probe.bin' : $folder . ':fokus_media_probe.bin') >= AUTH_UPLOAD; })),
            'total' => count($files), 'offset' => $offset,
            'canUpload' => $this->acl($ns === '' ? 'fokus_media_probe.bin' : $ns . ':fokus_media_probe.bin') >= AUTH_UPLOAD,
            'maxUpload' => min($this->bytes(ini_get('upload_max_filesize')), $this->bytes(ini_get('post_max_size')) ?: PHP_INT_MAX),
        ];
    }

    /** Use the same indexed media relations as DokuWiki's reference section. */
    public function references($id)
    {
        if ($id === '' || $this->acl($id) < AUTH_READ) throw new RuntimeException('You cannot access this media file.', 403);
        if (!is_file(mediaFN($id))) throw new RuntimeException('This media file no longer exists.', 404);
        if (class_exists('dokuwiki\\Search\\MetadataSearch')) {
            $pages = (new \dokuwiki\Search\MetadataSearch())->mediause($id);
        } else {
            require_once DOKU_INC . 'inc/fulltext.php';
            $pages = ft_mediause($id, false);
        }
        $references = [];
        foreach ($pages as $page) {
            if (auth_quickaclcheck($page) < AUTH_READ || !isVisiblePage($page) || !page_exists($page)) continue;
            $references[] = [
                'id' => $page,
                'title' => p_get_metadata($page, 'title', METADATA_DONT_RENDER) ?: noNS($page),
                'url' => wl($page, '', true, '&'),
            ];
        }
        return ['references' => $references];
    }

    private function bytes($value)
    {
        $number = (float)$value;
        switch (strtolower(substr(trim($value), -1))) {
            case 'g': $number *= 1024;
            case 'm': $number *= 1024;
            case 'k': $number *= 1024;
        }
        return (int)$number;
    }

    public function upload($ns)
    {
        global $INPUT, $MSG;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Uploads require POST.', 405);
        if (!checkSecurityToken()) throw new RuntimeException('Your session expired. Reload the page before uploading.', 403);
        $file = $_FILES['upload'] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) throw new RuntimeException('No file received. Check the server upload limit.', 400);
        if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed or exceeded the server upload limit.', 400);
        // A filename cannot redirect an upload into another namespace.
        $name = str_replace(':', '_', cleanID(basename(str_replace('\\', '/', $file['name']))));
        if ($name === '') throw new RuntimeException('Choose a valid filename.', 400);
        $id = cleanID($ns . ':' . $name);
        $auth = $this->acl($id);
        if ($auth < AUTH_UPLOAD) throw new RuntimeException('You cannot upload into this namespace.', 403);
        if (file_exists(mediaFN($id))) throw new RuntimeException('A file named ' . $name . ' already exists. Rename your file before uploading.', 409);
        [$ext, $mime] = mimetype($name);
        $result = media_save(['name' => $file['tmp_name'], 'ext' => $ext, 'mime' => $mime], $id, false, $auth, 'copy_uploaded_file');
        if (!is_string($result)) {
            $message = is_array($result) ? html_entity_decode(strip_tags($result[0]), ENT_QUOTES, 'UTF-8') : 'DokuWiki could not save this file.';
            throw new RuntimeException($message, 400);
        }
        return ['id' => $result];
    }
}
