<?php

if (!defined('DOKU_INC')) die();

/** Adds configured snippets to DokuWiki's editor toolbar. */
class FokusButler_Module_Snips_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'snips';
    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('snips')) return;
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'addToolbarPicker');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleTagPicker');
        $controller->register_hook('JS_CACHE_USE', 'BEFORE', $this, 'addSnippetsCacheDependency');
    }

    /**
     * Toolbar definitions are part of DokuWiki's combined JavaScript cache.
     * Make snippet settings a dependency so saving them rebuilds that bundle.
     */
    public function addSnippetsCacheDependency(Doku_Event $event, $param)
    {
        $cache = $event->data;
        if (!is_object($cache)) return;
        if (!isset($cache->depends) || !is_array($cache->depends)) $cache->depends = [];
        if (!isset($cache->depends['files']) || !is_array($cache->depends['files'])) $cache->depends['files'] = [];

        $cache->depends['files'][] = DOKU_CONF . 'plugin/fokusbutler/snips/snippets.json';
        $cache->depends['files'][] = __DIR__ . '/script.js';
    }

    public function addToolbarPicker(Doku_Event $event, $param)
    {
        /** @var FokusButler_Module_Snips_Helper|null $helper */
        $helper = plugin_load('helper', 'fokusbutler_snips');
        if (!$helper) return;

        $items = [];
        foreach ($helper->getSnippets() as $snippet) {
            if (empty($snippet['enabled'])) continue;
            $item = [
                'title' => $snippet['label'],
                'icon' => $snippet['icon'] === '' ? '' : $helper->getToolbarIconPath($snippet['icon']),
                'mode' => $snippet['mode'],
            ];
            if ($snippet['mode'] === 'wrap') {
                $item['open'] = $snippet['open'];
                $item['close'] = $snippet['close'];
            } else {
                $item['insert'] = $snippet['insert'];
            }
            $items[] = $item;
        }

        $toolbarButton = [
            'type' => 'fokusbutlersnips',
            'title' => $this->getLang('toolbar_title'),
            'icon' => '../../plugins/fokusbutler/modules/snips/images/snips.svg',
            'list' => $items,
        ];

        /** @var helper_plugin_tag|null $tagHelper */
        $tagHelper = plugin_load('helper', 'tag');
        if ($tagHelper) {
            $toolbarButton['tagPicker'] = [
                'ajaxUrl' => DOKU_BASE . 'lib/exe/ajax.php?call=fokusbutler_snips_tags',
                'icon' => DOKU_BASE . 'lib/plugins/fokusbutler/modules/snips/images/tag-duotone.svg',
                'strings' => [
                    'heading' => $this->getLang('tag_picker_heading'),
                    'snippetsHeading' => $this->getLang('snippets_heading'),
                    'search' => $this->getLang('tag_search_placeholder'),
                    'open' => $this->getLang('tag_picker_open'),
                    'loading' => $this->getLang('tag_loading'),
                    'empty' => $this->getLang('tag_empty'),
                    'noMatches' => $this->getLang('tag_no_matches'),
                    'loadError' => $this->getLang('tag_load_error'),
                    'insertSyntax' => $this->getLang('tag_insert_syntax'),
                    'insertSelectedTags' => $this->getLang('tag_insert_selected'),
                ],
            ];
        }

        if (!$items && !$tagHelper) return;
        $event->data[] = $toolbarButton;
    }

    /** Return tags visible to the current user for the editor picker. */
    public function handleTagPicker(Doku_Event $event, $param)
    {
        if ($event->data !== 'fokusbutler_snips_tags') return;
        $event->preventDefault();
        $event->stopPropagation();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        /** @var helper_plugin_tag|null $tagHelper */
        $tagHelper = plugin_load('helper', 'tag');
        if (!$tagHelper) {
            echo json_encode(['tags' => []]);
            return;
        }

        $tags = $tagHelper->tagOccurrences([], null, true);
        ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
        $items = [];
        foreach ($tags as $id => $count) {
            $items[] = [
                'id' => (string)$id,
                'label' => str_replace('_', ' ', (string)$id),
                'count' => (int)$count,
            ];
        }

        echo json_encode(['tags' => $items], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}
