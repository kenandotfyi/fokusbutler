<?php
if (!defined('DOKU_INC')) die();

/** Standalone entry inside Butler's existing administration shell. */
class FokusButler_Module_Media_Admin extends DokuWiki_Admin_Plugin
{
    public function handle() {}
    public function html()
    {
        echo '<h1>Media Library</h1><p>Browse namespaces, manage files and versions, and see which pages use each file.</p>';
        if (!FokusButler_Modules::enabled('media')) {
            echo '<p>Enable Media Library in the module overview to open the library.</p>';
            return;
        }
        echo '<form action="' . hsc(wl('', ['do' => 'admin', 'page' => 'fokusbutler'], true, '&')) . '"><input type="hidden" name="module" value="media" />';
        echo '<a data-fokus-media-open href="' . hsc(wl('', ['do' => 'media'], true, '&')) . '">Open Media Library</a></form>';
        echo '<p>Select a file for version uploads, deletion, history, restoration, and supported image metadata. Use Move / rename or drag a file onto a namespace; the Move plugin keeps page links up to date.</p>';
    }
}
