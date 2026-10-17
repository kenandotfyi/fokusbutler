<?php

if (!defined('DOKU_INC')) die();

/** Load the standalone imagepop assets on rendered wiki pages. */
class FokusButler_Module_Imagepop_Action extends DokuWiki_Action_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'imagepop';
    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('imagepop')) return;
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'addAssets');
    }

    public function addAssets(Doku_Event $event, $param)
    {
        global $ACT;
        if (!isset($ACT) || $ACT !== 'show') return;


        $selectors = trim((string)$this->getConf('image_selector'));
        if ($selectors === '') $selectors = 'img.media, img.medialeft, img.mediaright, img.mediacenter';
        $payload = json_encode(['imageSelector' => $selectors], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!empty($event->data['script'])) {
            foreach ($event->data['script'] as &$scriptTag) {
                if (isset($scriptTag['_data']) && strpos($scriptTag['_data'], 'var JSINFO') !== false) {
                    $scriptTag['_data'] .= 'JSINFO.imagepop=' . $payload . ';';
                    break;
                }
            }
            unset($scriptTag);
        }
    }
}
