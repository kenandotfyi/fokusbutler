<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/bootstrap.php';

/** Public integration interface for the Fokus template. */
class helper_plugin_fokusbutler extends DokuWiki_Plugin
{
    public function moduleEnabled($module) { return FokusButler_Modules::enabled($module); }
    public function modules() { return FokusButler_Modules::all(); }
    public function adminUrl($module = 'modules') { return FokusButler_Modules::adminUrl($module); }
}
