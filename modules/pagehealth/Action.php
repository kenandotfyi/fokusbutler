<?php
if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Mark links to blank and heading-only pages in rendered wiki content.
 */
class FokusButler_Module_Pagehealth_Action extends ActionPlugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'pagehealth';
    private const CLASSIFICATION_VERSION = 2;
    private const SCAN_BATCH_SIZE = 500;

    public function register(EventHandler $controller)
    {
        if (!FokusButler_Modules::enabled('pagehealth')) return;
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'handlePageWrite');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'addStyleSettings');
    }

    public function addStyleSettings(Event $event)
    {
        $color = $this->validColor($this->getConf('empty_color'), '#89919b');
        $hoverColor = $this->validColor($this->getConf('empty_hover_color'), '#626b76');
        $decoration = $this->validChoice(
            $this->getConf('empty_decoration'),
            ['none', 'underline', 'overline', 'line-through'],
            'none'
        );
        $decorationStyle = $this->validChoice(
            $this->getConf('empty_decoration_style'),
            ['solid', 'dotted', 'dashed', 'wavy'],
            'solid'
        );
        $opacity = $this->validOpacity($this->getConf('empty_opacity'));
        $fontStyle = $this->getConf('empty_italic') ? 'italic' : 'normal';

        $css = ':root{' .
            '--pagehealth-empty-color:' . $color . ';' .
            '--pagehealth-empty-hover-color:' . $hoverColor . ';' .
            '--pagehealth-empty-decoration:' . $decoration . ';' .
            '--pagehealth-empty-decoration-style:' . $decorationStyle . ';' .
            '--pagehealth-empty-opacity:' . $opacity . ';' .
            '--pagehealth-empty-font-style:' . $fontStyle . ';' .
            '}';
        $event->data['style'][] = ['type' => 'text/css', '_data' => $css];
    }

    private function validColor($value, $fallback)
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }

    private function validChoice($value, $choices, $fallback)
    {
        return is_string($value) && in_array($value, $choices, true) ? $value : $fallback;
    }

    private function validOpacity($value)
    {
        if (!is_numeric($value)) return '0.75';
        return (string)max(0.2, min(1.0, (float)$value));
    }

    public function handleAjax(Event $event)
    {
        if ($event->data === 'fokusbutler_pagehealth_scan') {
            $event->preventDefault();
            $event->stopPropagation();

            header('Content-Type: application/json; charset=utf-8');
            // Keep the site-wide backfill from being triggered by anonymous
            // traffic. Normal per-page link checks still work for all readers.
            if (!auth_isadmin()) {
                http_response_code(403);
                echo json_encode(['error' => true]);
                return;
            }
            if (!checkSecurityToken()) {
                http_response_code(403);
                echo json_encode(['error' => true]);
                return;
            }
            global $INPUT;
            $status = $this->scanPageBatch($INPUT->post->bool('restart'));
            if ($status === null) {
                http_response_code(500);
                echo json_encode(['error' => true]);
                return;
            }
            echo json_encode($status);
            return;
        }

        if ($event->data === 'fokusbutler_pagehealth_stats') {
            $event->preventDefault();
            $event->stopPropagation();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->getSummaryStats());
            return;
        }

        if ($event->data !== 'fokusbutler_pagehealth') return;

        $event->preventDefault();
        $event->stopPropagation();

        header('Content-Type: application/json; charset=utf-8');
        global $INPUT;
        $ids = json_decode($INPUT->post->str('ids'), true);
        if (!is_array($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid page list']);
            return;
        }

        // Keep a single request bounded; the browser submits larger lists in batches.
        $ids = array_slice($ids, 0, 500);
        $classifications = $this->loadClassifications();
        $result = [];
        $newClassifications = [];
        foreach ($ids as $candidate) {
            if (!is_string($candidate)) continue;
            $id = cleanID($candidate);
            if ($id === '') continue;

            // Do not reveal whether unreadable pages are empty or populated.
            if (auth_quickaclcheck($id) < AUTH_READ) continue;

            if (array_key_exists($id, $classifications) && is_bool($classifications[$id])) {
                $empty = $classifications[$id];
            } else {
                $source = page_exists($id) ? rawWiki($id) : '';
                $empty = $this->classify((string)$source);
                $newClassifications[$id] = $empty;
            }
            $result[$id] = $empty;
        }

        if ($newClassifications) $this->saveClassifications($newClassifications);
        echo json_encode($result);
    }

    private function getSummaryStats()
    {
        $pages = $this->loadClassifications();
        $healthy = 0;
        $noContent = 0;
        foreach ($pages as $id => $empty) {
            $id = (string)$id;
            if (!is_bool($empty) || !page_exists($id)) continue;
            if (auth_quickaclcheck($id) < AUTH_READ) continue;
            if ($empty) {
                $noContent++;
            } else {
                $healthy++;
            }
        }

        $scan = $this->readScanState();
        $complete = is_array($scan)
            && ($scan['version'] ?? null) === self::CLASSIFICATION_VERSION
            && !empty($scan['done'])
            && $this->hasCurrentIndex();
        $message = $complete
            ? sprintf($this->getLang('summary_complete'), $healthy, $noContent)
            : sprintf($this->getLang('summary_partial'), $healthy, $noContent);

        return [
            'healthy' => $healthy,
            'noContent' => $noContent,
            'classified' => $healthy + $noContent,
            'complete' => $complete,
            'message' => $message,
        ];
    }

    private function readScanState()
    {
        $file = $this->cacheDirectory() . '/scan.json';
        if (!is_file($file)) return null;
        $state = json_decode((string)file_get_contents($file), true);
        return is_array($state) ? $state : null;
    }

    /**
     * Classify one bounded batch of all wiki pages so existing pages do not
     * need to appear in an nspages list or be opened by a user first.
     */
    private function scanPageBatch($restart = false)
    {
        global $conf;
        $dir = $this->cacheDirectory();
        io_mkdir_p($dir);

        $lock = fopen($dir . '/scan.lock', 'c');
        if ($lock === false) return null;
        flock($lock, LOCK_EX);

        $stateFile = $dir . '/scan.json';
        $state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : null;
        if (
            !is_array($state) ||
            ($state['version'] ?? null) !== self::CLASSIFICATION_VERSION ||
            !is_array($state['ids'] ?? null) ||
            !$this->hasCurrentIndex() ||
            $restart
        ) {
            $pages = [];
            // This scan stays server-side; IDs and classifications for pages
            // the current user cannot read are never returned to the browser.
            search($pages, $conf['datadir'], 'search_allpages', ['skipacl' => true]);
            $ids = array_values(array_unique(array_filter(array_column($pages, 'id'), 'is_string')));
            $state = [
                'version' => self::CLASSIFICATION_VERSION,
                'ids' => $ids,
                'offset' => 0,
                'force' => (bool)$restart,
            ];
        }

        $offset = max(0, (int)($state['offset'] ?? 0));
        $batch = array_slice($state['ids'], $offset, self::SCAN_BATCH_SIZE);
        $classifications = $this->loadClassifications();
        $updates = [];
        foreach ($batch as $id) {
            if (empty($state['force']) && array_key_exists($id, $classifications) && is_bool($classifications[$id])) continue;
            if (!page_exists($id)) continue;
            $updates[$id] = $this->classify((string)rawWiki($id));
        }

        if ($updates && !$this->saveClassifications($updates)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return null;
        }
        $state['offset'] = $offset + count($batch);
        $done = $state['offset'] >= count($state['ids']);
        $state['done'] = $done;

        $temporary = $stateFile . '.' . uniqid('', true) . '.tmp';
        $saved = file_put_contents($temporary, json_encode($state)) !== false
            && rename($temporary, $stateFile);

        flock($lock, LOCK_UN);
        fclose($lock);
        return $saved ? [
            'done' => $done,
            'processed' => min($state['offset'], count($state['ids'])),
            'total' => count($state['ids']),
        ] : null;
    }

    private function hasCurrentIndex()
    {
        $file = $this->indexFile();
        if (!is_file($file)) return false;
        $value = json_decode((string)file_get_contents($file), true);
        return is_array($value)
            && ($value['version'] ?? null) === self::CLASSIFICATION_VERSION
            && is_array($value['pages'] ?? null);
    }

    public function handlePageWrite(Event $event)
    {
        // IO_WIKIPAGE_WRITE carries io_saveFile arguments at data[0], followed
        // by namespace and page name. Recompute from the content being saved.
        if (!isset($event->data[0]) || !array_key_exists(1, $event->data[0]) || !isset($event->data[2])) return;
        $namespace = $event->data[1] ?? false;
        $page = $event->data[2];
        if (!is_string($page) || $page === '') return;

        $id = ($namespace && $namespace !== false) ? $namespace . ':' . $page : $page;
        $this->saveClassifications([$id => $this->classify((string)$event->data[0][1])]);
    }

    private function loadClassifications()
    {
        $file = $this->indexFile();
        if (!is_file($file)) return [];
        $value = json_decode((string)file_get_contents($file), true);
        if (!is_array($value) || ($value['version'] ?? null) !== self::CLASSIFICATION_VERSION) return [];
        return isset($value['pages']) && is_array($value['pages']) ? $value['pages'] : [];
    }

    private function saveClassifications($updates)
    {
        $dir = $this->cacheDirectory();
        io_mkdir_p($dir);
        $lock = fopen($dir . '/index.lock', 'c');
        if ($lock === false) return false;
        flock($lock, LOCK_EX);

        // Merge under a lock so simultaneous scans and saves cannot overwrite
        // one another's newly classified page IDs.
        $current = $this->loadClassifications();
        foreach ($updates as $id => $empty) {
            $current[$id] = (bool)$empty;
        }
        $temporary = $this->indexFile() . '.' . uniqid('', true) . '.tmp';
        $index = [
            'version' => self::CLASSIFICATION_VERSION,
            'pages' => $current,
        ];
        $saved = file_put_contents($temporary, json_encode($index)) !== false
            && rename($temporary, $this->indexFile());

        flock($lock, LOCK_UN);
        fclose($lock);
        return $saved;
    }

    private function cacheDirectory()
    {
        global $conf;
        return $conf['cachedir'] . '/fokusbutler/pagehealth';
    }

    private function indexFile()
    {
        return $this->cacheDirectory() . '/index.json';
    }

    /**
     * Strip heading lines and markup-only directives. Remaining non-space
     * content, including link labels, media, tables, and code, counts as content.
     */
    private function classify($source)
    {
        $source = preg_replace('/<!--[\s\S]*?-->/', '', $source);
        $source = preg_replace('/<h[1-6]\b[^>]*>[\s\S]*?<\/h[1-6]\s*>/i', '', $source);
        // Markdown Setext headings: the heading text followed by === or ---.
        $source = preg_replace('/^[^\r\n]+\r?\n[ \t]*(?:={1,}|-{1,})[ \t]*(?:\r?\n|$)/m', '', $source);
        // Markdown ATX headings: # through ######, with optional closing hashes.
        $source = preg_replace('/^[ \t]{0,3}#{1,6}(?:[ \t]+[^\r\n]*|[ \t]*)$/m', '', $source);
        $source = preg_replace('/^[ \t]*={2,}[^\r\n]*?={2,}[ \t]*(?:\r?\n|$)/m', '', $source);
        $source = preg_replace('/^[ \t]*~~(?:NOTOC|NOCACHE|NODATE|NOTIFY)~~[ \t]*(?:\r?\n|$)/mi', '', $source);
        $source = preg_replace('/^[ \t]*\/\*[\s\S]*?\*\/[ \t]*(?:\r?\n|$)/m', '', $source);
        return trim((string)$source) === '';
    }
}
