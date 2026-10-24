<?php
if (!defined('DOKU_INC')) die();
class FokusButler_Module_Equations_Action extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        if (!FokusButler_Modules::enabled('equations')) return;
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'addToolbarButton');
    }
    public function addToolbarButton(Doku_Event $event, $param)
    {
        $event->data = array_values(array_filter($event->data, static function ($entry) {
            return ($entry['type'] ?? '') !== 'fokusbutlerequations';
        }));
        $event->data[] = ['type' => 'fokusbutlerequations', 'title' => 'Build equation',
            'icon' => '../../plugins/fokusbutler/core/icons/function-duotone.svg'];
    }
}
