<?php
if (!defined('DOKU_INC')) die();

final class FokusButler_Modules
{
    public static function all()
    {
        static $modules;
        if ($modules === null) $modules = require FOKUSBUTLER_ROOT . '/modules.php';
        return $modules;
    }

    public static function defaults()
    {
        static $defaults;
        if ($defaults === null) {
            $defaults = (static function () {
                $conf = [];
                require FOKUSBUTLER_ROOT . '/conf/default.php';
                return $conf;
            })();
        }
        return $defaults;
    }

    public static function enabled($module)
    {
        global $conf;
        $entry = self::all()[$module] ?? null;
        if (!$entry) return false;
        if (!$entry['toggle']) return true;
        return (bool)($conf['plugin']['fokusbutler']['enabled_' . $module] ?? self::defaults()['enabled_' . $module]);
    }

    public static function config($module, $key, $notset = false)
    {
        global $conf;
        $name = $module . '_' . $key;
        return $conf['plugin']['fokusbutler'][$name] ?? self::defaults()[$name] ?? $notset;
    }

    public static function language($module, $key)
    {
        global $conf;
        static $languages = [];
        $language = preg_replace('/[^a-zA-Z0-9_-]/', '', $conf['lang'] ?? 'en');
        $cacheKey = $module . ':' . $language;
        if (!isset($languages[$cacheKey])) {
            $languages[$cacheKey] = (static function () use ($module, $language) {
                $lang = [];
                foreach (array_unique(['en', $language]) as $code) {
                    $file = FOKUSBUTLER_ROOT . '/modules/' . $module . '/lang/' . $code . '/lang.php';
                    if (is_file($file)) include $file;
                }
                return $lang;
            })();
        }
        return $languages[$cacheKey][$key] ?? '';
    }

    public static function admin($module)
    {
        if (!isset(self::all()[$module])) return null;
        $file = FOKUSBUTLER_ROOT . '/modules/' . $module . '/Admin.php';
        if (!is_file($file)) return null;
        require_once $file;
        $class = 'FokusButler_Module_' . ucfirst($module) . '_Admin';
        return new $class();
    }

    public static function adminUrl($module = 'modules')
    {
        global $ID;
        return wl($ID, ['do' => 'admin', 'page' => 'fokusbutler', 'module' => $module], true, '&');
    }
}
