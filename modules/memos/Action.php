<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Store.php';

class FokusButler_Module_Memos_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'memos';
    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('memos')) return;
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'configure');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleRequest');
    }
    public static function allowed()
    {
        if (empty($_SERVER['REMOTE_USER']) || !FokusButler_Modules::enabled('memos')) return false;
        $owner = trim((string)FokusButler_Modules::config('memos', 'wiki_user', ''));
        return $owner !== '' ? $_SERVER['REMOTE_USER'] === $owner : auth_isadmin();
    }
    public function configure(Doku_Event $event, $param)
    {
        global $ACT, $ID, $conf;
        if (!self::allowed() || !in_array($ACT, ['show', 'preview', 'admin'], true)) return;
        $base = DOKU_BASE . 'lib/plugins/fokusbutler/modules/memos/';
        $config = ['endpoint' => DOKU_BASE . 'lib/exe/ajax.php?call=fokusbutler_memos', 'token' => getSecurityToken(),
            'homeUrl' => wl($conf['start'], '', true, '&'), 'pageId' => $ID, 'pageUrl' => wl($ID, '', true, '&'),
            'settingsUrl' => auth_isadmin() ? FokusButler_Modules::adminUrl('memos') : '',
            'shortcut' => (string)$this->getConf('shortcut'),
            'configured' => FokusButler_Memos_Connection::url() !== '' && FokusButler_Memos_Connection::credential()['source'] !== 'missing',
            'storageKey' => 'fokus-jots:' . hash('sha256', DOKU_BASE . ':' . $_SERVER['REMOTE_USER'] . ':' . FokusButler_Memos_Connection::url()),
            'icon' => DOKU_BASE . 'lib/plugins/fokusbutler/core/icons/pen-nib-duotone.svg',
            'actionIcons' => [
                'memos' => DOKU_BASE . 'lib/plugins/fokusbutler/core/icons/trend-up-duotone.svg',
                'edit' => DOKU_BASE . 'lib/plugins/fokusbutler/core/icons/note-pencil-duotone.svg',
                'promote' => DOKU_BASE . 'lib/plugins/fokusbutler/core/icons/sparkle-duotone.svg',
                'delete' => DOKU_BASE . 'lib/plugins/fokusbutler/modules/preview/icons/trash-duotone.svg']];
        $event->data['script'][] = ['type' => 'text/javascript', '_data' => 'window.FokusButlerJotsConfig=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'];
        foreach (['vendor/marked-18.1.1.js', 'vendor/purify-3.4.17.js'] as $file) {
            $event->data['script'][] = ['type' => 'text/javascript', 'defer' => true, 'src' => $base . $file];
        }
        FokusButler_Assets::add($event, 'memos');
    }
    public function handleRequest(Doku_Event $event, $param)
    {
        if ($event->data !== 'fokusbutler_memos') return;
        $event->preventDefault(); $event->stopPropagation();
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        global $INPUT, $conf;
        try {
            if (!self::allowed()) throw new RuntimeException('Your wiki account does not have access to Jots.', 403);
            $action = $INPUT->str('action', 'list');
            if ($action !== 'file' && ($INPUT->server->str('REQUEST_METHOD') !== 'POST' || !checkSecurityToken())) throw new RuntimeException('Reload the page before using Jots.', 403);
            // Do not hold the wiki session lock while talking to Memos.
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $connection = new FokusButler_Memos_Connection();
            $store = new FokusButler_Memos_Store($connection, $conf['metadir'], $_SERVER['REMOTE_USER'], max(1, min(2048, (int)$this->getConf('attachment_max_mb'))) * 1048576);
            switch ($action) {
                case 'list': $result = $store->snapshot(); break;
                case 'sync': $result = $store->syncStep($INPUT->bool('force')); break;
                case 'save': $result = $store->queue($INPUT->str('operation'), $INPUT->str('name'), $INPUT->str('content'), $INPUT->str('baseUpdateTime')); break;
                case 'retry': $result = $store->retry($INPUT->str('operation')); break;
                case 'discard': $result = $store->cancelPending($INPUT->str('operation')); break;
                case 'delete': $result = $store->deleteMemo($INPUT->str('name')); break;
                case 'promote':
                    require_once DOKU_INC . 'inc/media.php';
                    require_once __DIR__ . '/Promote.php';
                    $plan = FokusButler_Memos_Promote::prepare($store, $connection->baseUrl(), $INPUT->str('name'), $INPUT->str('target'), $INPUT->str('content'), $INPUT->str('version'), $INPUT->bool('attachments'));
                    $result = FokusButler_Memos_Promote::create($plan);
                    break;
                case 'file':
                    $file = $store->file($INPUT->str('key'));
                    $inline = in_array($file['type'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'audio/mpeg', 'audio/ogg', 'video/mp4', 'video/webm'], true);
                    header('Content-Type: ' . $file['type']);
                    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="attachment"; filename*=UTF-8\'\'' . rawurlencode($file['filename']));
                    header('Content-Security-Policy: sandbox; default-src \'none\'');
                    header('Content-Length: ' . filesize($file['path']));
                    readfile($file['path']); return;
                default: throw new RuntimeException('Unknown Jots action.', 400);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true] + $result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            $status = in_array($error->getCode(), [400, 403, 404, 409, 423, 429], true) ? $error->getCode() : 502;
            http_response_code($status); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error instanceof RuntimeException ? $error->getMessage() : 'Jots could not complete this request. Your local data is retained.']);
        }
    }
}
