<?php
if (!defined('DOKU_INC')) die();

/** Module-local configuration and translations behind DokuWiki's component bridges. */
trait FokusButler_ModuleContext
{
    public function getPluginName() { return 'fokusbutler'; }
    public function getPluginComponent()
    {
        if (preg_match('/^(?:action|syntax|helper)_plugin_fokusbutler_(.+)$/', static::class, $component)) return $component[1];
        return static::MODULE;
    }
    public function getInfo() { return confToHash(FOKUSBUTLER_ROOT . '/plugin.info.txt'); }
    public function getConf($setting, $notset = false)
    {
        return FokusButler_Modules::config(static::MODULE, $setting, $notset);
    }
    public function getLang($id)
    {
        return FokusButler_Modules::language(static::MODULE, $id);
    }
}
