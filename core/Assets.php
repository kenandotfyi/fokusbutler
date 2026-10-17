<?php
if (!defined('DOKU_INC')) die();

/** Keep assets separate, load each file once, and never append version parameters. */
final class FokusButler_Assets
{
    public static function add($event, $module, $script = true)
    {
        if (!isset(FokusButler_Modules::all()[$module]) && $module !== 'core') return;
        $directory = $module === 'core' ? '/core/' : '/modules/' . $module . '/';
        $url = DOKU_BASE . 'lib/plugins/fokusbutler' . $directory;
        if (is_file(FOKUSBUTLER_ROOT . $directory . 'style.css')) {
            $href = $url . 'style.css';
            $exists = array_filter($event->data['link'] ?? [], static function ($asset) use ($href) { return ($asset['href'] ?? '') === $href; });
            if (!$exists) $event->data['link'][] = ['rel' => 'stylesheet', 'type' => 'text/css', 'href' => $href];
        }
        if ($script && is_file(FOKUSBUTLER_ROOT . $directory . 'script.js')) {
            $src = $url . 'script.js';
            $exists = array_filter($event->data['script'] ?? [], static function ($asset) use ($src) { return ($asset['src'] ?? '') === $src; });
            if (!$exists) $event->data['script'][] = ['type' => 'text/javascript', 'defer' => true, 'src' => $src];
        }
    }
}
