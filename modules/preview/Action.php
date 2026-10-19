<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Settings.php';

class FokusButler_Module_Preview_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'preview';

    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('preview')) return;
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'configure');
        $controller->register_hook('JS_SCRIPT_LIST', 'BEFORE', $this, 'replaceLegacyScript');
    }

    public function configure(Doku_Event $event, $param)
    {
        global $ACT, $ID;
        if (!in_array($ACT, ['show', 'preview'], true)) return;
        $settings = FokusButler_Preview_Settings::values();
        $event->data['script'][] = ['type' => 'text/javascript', '_data' => 'window.FokusButlerPreviewConfig=' . json_encode([
            'enabled' => true,
            'pageId' => $ID,
            'width' => $settings['width'],
            'height' => $settings['height'],
            'openDelay' => $settings['open_delay'],
            'closeDelay' => $settings['close_delay'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'];
    }

    /** Keep the standalone plugin installed; restore it when this module is off. */
    public function replaceLegacyScript(Doku_Event $event, $param)
    {
        $event->data = array_values(array_filter($event->data, static function ($file) {
            return str_replace('\\', '/', $file) !== str_replace('\\', '/', DOKU_PLUGIN . 'preview/script.js');
        }));
    }

}
