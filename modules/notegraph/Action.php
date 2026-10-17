<?php
if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\File\PageResolver;

/** Load NoteGraph's pane button, graph UI, and nearby-page endpoint. */
class FokusButler_Module_Notegraph_Action extends ActionPlugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'notegraph';
    private const MAX_DEPTH = 3;
    private const MAX_NODES = 60;

    public function register(EventHandler $controller)
    {
        if (!FokusButler_Modules::enabled('notegraph')) return;
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'addAssets');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleGraphRequest');
    }

    public function addAssets(Event $event)
    {
        global $ACT, $ID;
        if (!isset($ACT) || $ACT !== 'show') return;

        $event->data['script'][] = [
            'type' => 'text/javascript',
            'charset' => 'utf-8',
            'defer' => 'defer',
            '_data' => 'window.FokusButlerNoteGraphConfig = ' . json_encode([
                'currentPageId' => $ID,
                'ajaxUrl' => DOKU_BASE . 'lib/exe/ajax.php?call=fokusbutler_notegraph',
                'maxDepth' => self::MAX_DEPTH,
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
        ];
    }

    public function handleGraphRequest(Event $event)
    {
        if ($event->data !== 'fokusbutler_notegraph') return;

        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');

        global $INPUT;
        $id = cleanID($INPUT->get->str('id'));
        $depth = max(1, min(self::MAX_DEPTH, $INPUT->get->int('depth', 1)));

        if ($id === '' || !page_exists($id)) {
            http_response_code(404);
            echo json_encode(['error' => 'Page not found']);
            return;
        }
        if (auth_quickaclcheck($id) < AUTH_READ) {
            http_response_code(403);
            echo json_encode(['error' => 'Page access denied']);
            return;
        }

        echo json_encode($this->buildGraph($id, $depth), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function buildGraph($rootId, $maxDepth)
    {
        $nodes = [$rootId => $this->nodeData($rootId, 0)];
        $edges = [];
        $queue = [$rootId];
        $truncated = false;

        while ($queue) {
            $sourceId = array_shift($queue);
            $sourceDepth = $nodes[$sourceId]['depth'];
            if ($sourceDepth >= $maxDepth) continue;

            foreach ($this->getOutgoingPages($sourceId) as $targetId) {
                if (!isset($nodes[$targetId])) {
                    if (count($nodes) >= self::MAX_NODES) {
                        $truncated = true;
                        continue;
                    }
                    $nodes[$targetId] = $this->nodeData($targetId, $sourceDepth + 1);
                    $queue[] = $targetId;
                }
                $this->addEdge($edges, $sourceId, $targetId);
            }

            foreach ($this->getIncomingPages($sourceId) as $sourceLinkId) {
                if (!isset($nodes[$sourceLinkId])) {
                    if (count($nodes) >= self::MAX_NODES) {
                        $truncated = true;
                        continue;
                    }
                    $nodes[$sourceLinkId] = $this->nodeData($sourceLinkId, $sourceDepth + 1);
                    $queue[] = $sourceLinkId;
                }
                $this->addEdge($edges, $sourceLinkId, $sourceId);
            }
        }

        return [
            'root' => $rootId,
            'depth' => $maxDepth,
            'truncated' => $truncated,
            'nodes' => array_values($nodes),
            'links' => array_values($edges),
        ];
    }

    private function nodeData($id, $depth)
    {
        $title = trim((string)p_get_first_heading($id, false));
        if ($title === '') $title = noNS($id);

        return [
            'id' => $id,
            'title' => $title,
            'namespace' => getNS($id),
            'url' => wl($id),
            'depth' => $depth,
        ];
    }

    private function getOutgoingPages($pageId)
    {
        $found = [];
        $instructions = p_cached_instructions(wikiFN($pageId), false, $pageId);
        if (!is_array($instructions)) return [];

        foreach ($instructions as $instruction) {
            if (($instruction[0] ?? '') !== 'internallink') continue;
            $target = $instruction[1][0] ?? '';
            if (!is_string($target) || $target === '') continue;
            $target = explode('#', $target, 2)[0];
            if ($target === '') continue;

            $targetId = (new PageResolver($pageId))->resolveId($target);
            if (!is_string($targetId)) continue;
            $targetId = cleanID($targetId);
            if ($targetId === '' || $targetId === $pageId || !page_exists($targetId)) continue;
            if (auth_quickaclcheck($targetId) < AUTH_READ) continue;
            $found[$targetId] = true;
        }

        return array_keys($found);
    }

    private function getIncomingPages($pageId)
    {
        $found = [];
        foreach (ft_backlinks($pageId, true) as $sourceId) {
            if (!is_string($sourceId)) continue;
            $sourceId = cleanID($sourceId);
            if ($sourceId === '' || $sourceId === $pageId || !page_exists($sourceId)) continue;
            if (auth_quickaclcheck($sourceId) < AUTH_READ) continue;
            $found[$sourceId] = true;
        }

        return array_keys($found);
    }

    private function addEdge(&$edges, $sourceId, $targetId)
    {
        if ($sourceId === $targetId) return;
        $key = $sourceId . "\0" . $targetId;
        $edges[$key] = ['source' => $sourceId, 'target' => $targetId];
    }
}
