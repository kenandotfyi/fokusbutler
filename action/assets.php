<?php
if (!defined('DOKU_INC')) die();
require_once dirname(__DIR__) . '/bootstrap.php';

class action_plugin_fokusbutler_assets extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'addAssets');
        $controller->register_hook('JS_CACHE_USE', 'BEFORE', $this, 'addCacheDependencies');
    }

    public function addAssets(Doku_Event $event, $param)
    {
        global $ACT, $INPUT;
        // Toolbar APIs also need to exist on show pages for Fokus's editor popup.
        foreach (['snips', 'todoist', 'media', 'imagepop'] as $module) {
            if (FokusButler_Modules::enabled($module)) FokusButler_Assets::add($event, $module);
        }
        if (in_array($ACT, ['show', 'preview'], true)) {
            foreach (['content', 'folding', 'pagehealth', 'notegraph', 'preview'] as $module) {
                if (FokusButler_Modules::enabled($module) || in_array($module, ['folding', 'pagehealth'], true)) {
                    FokusButler_Assets::add($event, $module, FokusButler_Modules::enabled($module));
                }
            }
            if (!FokusButler_Modules::enabled('todoist')) FokusButler_Assets::add($event, 'todoist', false);
        }
        if ($ACT === 'admin' && $INPUT->str('page') === 'fokusbutler') {
            FokusButler_Assets::add($event, 'core', false);
            $module = $INPUT->str('module', 'modules');
            FokusButler_Assets::add($event, $module, FokusButler_Modules::enabled($module));
        }
        // Core's combined JS can be browser-cached independently of snippet
        // changes. Supply the current definitions with each rendered page.
        $toolbarData = [];
        $toolbar = new Doku_Event('TOOLBAR_DEFINE', $toolbarData);
        if (FokusButler_Modules::enabled('snips')) plugin_load('action', 'fokusbutler_snips')->addToolbarPicker($toolbar, null);
        if (FokusButler_Modules::enabled('todoist')) plugin_load('action', 'fokusbutler_todoist')->addToolbarButton($toolbar, null);
        if (FokusButler_Modules::enabled('media')) plugin_load('action', 'fokusbutler_media')->addToolbarButton($toolbar, null);
        $editorConfig = ['toolbar' => $toolbar->data];
        if (FokusButler_Modules::enabled('media')) {
            $editorConfig['media'] = ['ajaxUrl' => DOKU_BASE . 'lib/exe/ajax.php', 'namespace' => (string)getNS($GLOBALS['ID'] ?? ''), 'token' => getSecurityToken()];
        }
        $event->data['script'][] = ['type' => 'text/javascript', '_data' => 'window.FokusButlerEditorConfig=' . json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'];
        $src = DOKU_BASE . 'lib/plugins/fokusbutler/core/editor.js';
        if (!array_filter($event->data['script'] ?? [], static function ($asset) use ($src) { return ($asset['src'] ?? '') === $src; })) {
            $event->data['script'][] = ['type' => 'text/javascript', 'defer' => true, 'src' => $src];
        }
    }

    public function addCacheDependencies(Doku_Event $event, $param)
    {
        $cache = $event->data;
        if (!is_object($cache)) return;
        if (!isset($cache->depends['files'])) $cache->depends['files'] = [];
        $cache->depends['files'][] = dirname(__DIR__) . '/modules.php';
        $cache->depends['files'][] = dirname(__DIR__) . '/conf/default.php';
        $cache->depends['files'][] = DOKU_CONF . 'local.php';
    }
}
