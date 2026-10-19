<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Content.php';

class FokusButler_Module_Content_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'content';

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'configure');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleRequest');
    }

    public function configure(Doku_Event $event, $param)
    {
        global $ACT, $ID;
        if (!in_array($ACT, ['show', 'preview'], true)) return;
        $event->data['script'][] = ['type' => 'text/javascript', '_data' => 'window.FokusButlerContentConfig=' . json_encode([
            'enabled' => true,
            'pageId' => $ID,
            'ajaxUrl' => DOKU_BASE . 'lib/exe/ajax.php?call=fokusbutler_content',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'];
    }

    public function handleRequest(Doku_Event $event, $param)
    {
        if ($event->data !== 'fokusbutler_content') return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        global $INPUT;
        try {
            if ($INPUT->str('provider') === 'wikipedia') {
                $result = FokusButler_Content_Provider::wikipedia($INPUT->str('url'));
            } elseif ($INPUT->str('provider') === 'wiki') {
                $id = cleanID($INPUT->str('id'));
                // Check ACL before existence and before using the renderer cache.
                if ($id === '' || auth_quickaclcheck($id) < AUTH_READ) throw new RuntimeException('This page is not available.', 403);
                if (!page_exists($id)) throw new RuntimeException('This page does not exist.', 404);
                $title = trim((string)p_get_first_heading($id, false));
                $result = [
                    'provider' => 'wiki', 'id' => $id,
                    'title' => $title !== '' ? $title : $id,
                    'url' => wl($id),
                    'html' => FokusButler_Content_Provider::sanitize((string)p_wiki_xhtml($id, '', false)),
                ];
            } else {
                throw new RuntimeException('Unsupported preview.', 400);
            }
            echo json_encode(['ok' => true] + $result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            $status = (int)$error->getCode();
            http_response_code(in_array($status, [400, 403, 404, 502], true) ? $status : 502);
            echo json_encode(['ok' => false, 'error' => $error instanceof RuntimeException ? $error->getMessage() : 'The preview could not be loaded.']);
        }
    }
}
