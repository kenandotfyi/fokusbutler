<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/bootstrap.php';

use dokuwiki\plugin\config\core\Configuration;

/** The only DokuWiki admin-menu entry; feature panels remain module-owned. */
class admin_plugin_fokusbutler extends DokuWiki_Admin_Plugin
{
    private $panel;
    public function forAdminOnly() { return true; }
    public function getMenuSort() { return 140; }
    public function getMenuIcon() { return __DIR__ . '/admin.svg'; }
    public function getMenuText($language) { return 'Fokus Butler'; }

    private function selected()
    {
        global $INPUT;
        $module = $INPUT->str('module', 'modules');
        return $module === 'modules' || isset(FokusButler_Modules::all()[$module]) ? $module : 'settings';
    }

    public function handle()
    {
        global $INPUT;
        if (!auth_isadmin()) return;
        if ($this->selected() === 'modules') {
            if (!$INPUT->post->bool('fokusbutler_save_modules') && !$INPUT->post->bool('fokusbutler_save_adapters')) return;
            if (!checkSecurityToken()) { msg('The security token expired. Reload and try again.', -1); return; }
            try {
                $configuration = new Configuration();
                if ($configuration->isLocked()) throw new RuntimeException('Configuration is locked.');
                $settings = $configuration->getSettings();
                $updates = [];
                if ($INPUT->post->bool('fokusbutler_save_modules')) {
                    $submitted = $INPUT->post->arr('modules');
                    foreach (FokusButler_Modules::all() as $id => $entry) {
                        if (!$entry['toggle']) continue;
                        $setting = $settings['plugin____fokusbutler____enabled_' . $id] ?? null;
                        if (!$setting) throw new RuntimeException('A module setting is unavailable. Reload Configuration Manager.');
                        if ($setting->isProtected()) continue;
                        $updates[] = [$setting, empty($submitted[$id]) ? 0 : 1];
                    }
                } else {
                    $schema = $this->templateSettingsSchema();
                    if (!$schema) throw new RuntimeException('Fokus settings are unavailable.');
                    $active = $INPUT->post->arr('adapters');
                    foreach (['annotations', 'katex'] as $id) {
                        $key = 'adapter_' . $id;
                        $setting = $settings['tpl____fokus____' . $key] ?? null;
                        if (!$setting || !isset($schema[$key])) throw new RuntimeException('An adapter setting is unavailable. Reload Configuration Manager.');
                        if ($setting->isProtected()) continue;
                        $updates[] = [$setting, empty($active[$id]) ? 0 : 1];
                    }
                }
                $changed = false;
                foreach ($updates as [$setting, $value]) {
                    $changed = $setting->update($value) || $changed;
                    if ($setting->hasError()) throw new RuntimeException('Invalid module setting.');
                }
                if ($changed) $configuration->save();
                msg(($INPUT->post->bool('fokusbutler_save_modules') ? 'Module settings' : 'Adapter settings') . ' saved. Reload other open wiki tabs to apply changes.', 1);
                send_redirect(FokusButler_Modules::adminUrl('modules'));
            } catch (Throwable $error) { msg($error->getMessage(), -1); }
            return;
        }
        $this->panel = FokusButler_Modules::admin($this->selected());
        if ($this->panel) $this->panel->handle();
    }

    public function html()
    {
        if (!auth_isadmin()) { echo '<p>Administrator access required.</p>'; return; }
        $selected = $this->selected();
        echo '<div class="fokusbutler-admin"><aside class="fokusbutler-sidebar"><header class="fokusbutler-admin-header"><h1>Fokus Butler</h1><p>Settings &amp; modules</p></header>';
        echo '<nav class="fokusbutler-nav" aria-label="Fokus Butler modules">';
        $modules = FokusButler_Modules::all();
        $groups = [
            'General' => ['modules' => ['name' => 'Overview'], 'settings' => $modules['settings']],
            'Features' => array_filter($modules, static function ($entry) { return $entry['toggle']; }),
            'Services' => array_filter($modules, static function ($entry, $id) { return !$entry['toggle'] && $id !== 'settings'; }, ARRAY_FILTER_USE_BOTH),
        ];
        foreach ($groups as $label => $entries) {
            echo '<div class="fokusbutler-nav-group"><p class="fokusbutler-nav-label">' . hsc($label) . '</p>';
            foreach ($entries as $id => $entry) $this->navigationItem($id, $entry, $selected);
            echo '</div>';
        }
        if (is_dir(DOKU_PLUGIN . 'zoterobridge') && !plugin_isdisabled('zoterobridge')) {
            echo '<div class="fokusbutler-nav-group"><p class="fokusbutler-nav-label">Integrations</p><a href="' . hsc(wl('', ['do'=>'admin', 'page'=>'zoterobridge'], true, '&')) . '">' . $this->navigationIcon('content') . '<span>Zotero</span></a></div>';
        }
        echo '</nav></aside><div class="fokusbutler-panel" data-fokusbutler-module="' . hsc($selected) . '">';
        if ($selected === 'modules') $this->modulesPanel();
        else {
            if (!FokusButler_Modules::enabled($selected)) echo '<p class="fokusbutler-notice">This module is disabled. You can still edit its settings. Existing syntax remains readable.</p>';
            $panel = $this->panel ?: FokusButler_Modules::admin($selected);
            if ($panel) $panel->html();
            else $this->informationPanel($selected);
        }
        echo '</div></div>';
    }

    private function navigationItem($id, $entry, $selected)
    {
        echo '<a href="' . hsc(FokusButler_Modules::adminUrl($id)) . '"' . ($selected === $id ? ' aria-current="page"' : '') . '>';
        echo $this->navigationIcon($id) . '<span class="fokusbutler-nav-name">' . hsc($entry['name']) . '</span>';
        if (!empty($entry['toggle']) && !FokusButler_Modules::enabled($id)) echo '<span class="fokusbutler-nav-status">Off</span>';
        echo '</a>';
    }

    private function navigationIcon($module)
    {
        $icons = ['modules'=>'dots-six', 'settings'=>'gear-fine', 'content'=>'books',
            'folding'=>'arrows-out-line-vertical', 'pagehealth'=>'heartbeat', 'imagepop'=>'image',
            'equations'=>'function', 'notegraph'=>'graph', 'preview'=>'browser', 'snips'=>'exam', 'memos'=>'pen-nib'];
        $file = $module === 'todoist' ? __DIR__ . '/modules/todoist/icons/todoist.svg'
            : __DIR__ . '/core/icons/' . ($icons[$module] ?? 'dots-six') . '-duotone.svg';
        return '<span class="fokusbutler-nav-icon' . ($module === 'todoist' ? ' fokusbutler-nav-icon-todoist' : '') . '" aria-hidden="true">' . file_get_contents($file) . '</span>';
    }

    private function modulesPanel()
    {
        $configuration = new Configuration();
        $settings = $configuration->getSettings();
        echo '<h2>Modules</h2><p>Each module keeps its own PHP, scripts, styles, and icons. Disabling a module stops its optional behavior without deleting its data.</p>';
        echo '<form method="post" action="' . hsc(FokusButler_Modules::adminUrl('modules')) . '"><input type="hidden" name="fokusbutler_save_modules" value="1">';
        formSecurityToken();
        echo '<div class="fokusbutler-module-grid">';
        foreach (FokusButler_Modules::all() as $id => $entry) {
            $setting = $settings['plugin____fokusbutler____enabled_' . $id] ?? null;
            $locked = !$entry['toggle'] || !$setting || $setting->isProtected() || $configuration->isLocked();
            echo '<article class="fokusbutler-module-card"><h3>' . hsc($entry['name']) . '</h3><p>' . hsc($entry['description']) . '</p>';
            echo '<label><input type="checkbox" name="modules[' . hsc($id) . ']" value="1"' . (FokusButler_Modules::enabled($id) ? ' checked' : '') . ($locked ? ' disabled' : '') . '> ' . ($entry['toggle'] ? 'Enabled' : 'Always available') . '</label>';
            echo '<a href="' . hsc(FokusButler_Modules::adminUrl($id)) . '">Open module</a></article>';
        }
        echo '</div><p><button class="button" type="submit"' . ($configuration->isLocked() ? ' disabled' : '') . '>Save modules</button></p></form>';

        $this->adaptersPanel($configuration, $settings);
        $this->dependenciesPanel();
    }

    private function templateSettingsSchema()
    {
        $file = DOKU_INC . 'lib/tpl/fokus/settings-schema.php';
        if (!is_file($file)) return [];
        require_once $file;
        return function_exists('fokus_settings_schema') ? fokus_settings_schema() : [];
    }

    private function adaptersPanel($configuration, $settings)
    {
        global $conf;
        $schema = $this->templateSettingsSchema();
        $active = ($conf['template'] ?? '') === 'fokus';
        echo '<section class="fokusbutler-module-section"><h2>Adapters</h2><p>These switches control the Fokus adapters for optional plugins inside additional panes. Manage the plugins themselves through Extension Manager.</p>';
        echo '<form method="post" action="' . hsc(FokusButler_Modules::adminUrl('modules')) . '"><input type="hidden" name="fokusbutler_save_adapters" value="1">';
        formSecurityToken();
        echo '<div class="fokusbutler-integration-grid">';
        foreach (['annotations'=>['Annotations','Annotations in panes'], 'katex'=>['KaTeX','Math rendering in panes']] as $id => $info) {
            $key = 'adapter_' . $id;
            $setting = $settings['tpl____fokus____' . $key] ?? null;
            $protected = $setting && $setting->isProtected();
            $value = $conf['tpl']['fokus'][$key] ?? ($schema[$key]['default'] ?? 0);
            $disabled = !$active || !$setting || $protected || $configuration->isLocked();
            echo '<article><h3>' . hsc($info[0]) . ' <span>Adapter</span></h3><p>' . hsc($info[1]) . '</p><label class="fokusbutler-adapter-toggle"><input type="checkbox" name="adapters[' . hsc($id) . ']" value="1"' . ($value ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> Enable ' . hsc($info[0]) . ' adapter' . ($protected ? ' (protected)' : '') . '</label></article>';
        }
        echo '</div><p><button class="button" type="submit"' . (!$active || !$settings || $configuration->isLocked() ? ' disabled' : '') . '>Save adapters</button></p></form></section>';
    }

    private function dependenciesPanel()
    {
        echo '<section class="fokusbutler-module-section"><h2>Dependencies</h2><p>Plugin availability is read-only here. Install, enable, or disable these plugins through Extension Manager.</p>';
        $file = DOKU_INC . 'lib/tpl/fokus/dependencies.json';
        $manifest = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        $plugins = $manifest['required'] ?? [];
        $plugins['tag'] = ['name'=>'Tags', 'features'=>['Tag browser'], 'optional'=>true];
        $plugins['zoterobridge'] = ['name'=>'Zotero Bridge', 'features'=>['Local document and annotation index'], 'optional'=>true];
        echo '<div class="fokusbutler-integration-grid">';
        foreach ($plugins as $id => $plugin) {
            $this->pluginCardStart($id, $plugin, empty($plugin['optional']) ? 'Required' : 'Optional');
            if ($id === 'zoterobridge' && is_dir(DOKU_PLUGIN . $id) && !plugin_isdisabled($id)) {
                echo '<p><a href="' . hsc(wl('', ['do'=>'admin', 'page'=>'zoterobridge'], true, '&')) . '">Zotero settings</a></p>';
            }
            echo '</article>';
        }
        echo '</div></section>';
    }

    private function pluginCardStart($id, $plugin, $badge)
    {
        $installed = is_dir(DOKU_PLUGIN . $id);
        $enabled = $installed && !plugin_isdisabled($id);
        $ready = $enabled;
        foreach ($plugin['components'] ?? [] as $component) {
            [$type, $name] = explode(':', $component, 2);
            if (!plugin_load($type, $name)) $ready = false;
        }
        $status = !$installed ? 'Not installed' : (!$enabled ? 'Disabled' : ($ready ? 'Ready' : 'Required components unavailable'));
        $statusClass = !$installed ? 'missing' : ($ready ? 'ready' : 'unavailable');
        $url = wl('', ['do'=>'admin','page'=>'extension','tab'=>'search','q'=>'ext:' . $id], true, '&');
        echo '<article><h3>' . hsc($plugin['name']) . ' <span>' . hsc($badge) . '</span></h3><p>' . hsc(implode(', ', $plugin['features'] ?? [])) . '</p><p><strong class="fokusbutler-integration-status fokusbutler-integration-status-' . $statusClass . '">' . hsc($status) . '</strong> · <a href="' . hsc($url) . '">Manage plugin</a></p>';
    }

    private function informationPanel($module)
    {
        $entry = FokusButler_Modules::all()[$module];
        echo '<h2>' . hsc($entry['name']) . '</h2><p>' . hsc($entry['description']) . '</p>';
        if ($module === 'folding') {
            echo '<p>Use four semicolons for closed sections or four plus signs for open sections. Titles accept wiki formatting and headings.</p><pre>' . hsc(";;;; Title |\nSection content\n;;;;\n\n++++ Title |\nSection content\n++++") . '</pre><p>Add these as wrap snippets in the Snips module if you want editor shortcuts.</p>';
        } elseif ($module === 'notegraph') {
            echo '<p>Open the local graph from a page’s banner. The graph follows incoming and outgoing wiki links, respects read permissions, and opens pages in Fokus panes.</p>';
        } elseif ($module === 'content') {
            echo '<p>Shared content providers supply sanitized wiki and Wikipedia articles to panes and hover previews. This service remains available when Page previews is disabled. Wikipedia reference tooltips are part of article rendering.</p>';
        } elseif ($module === 'preview') {
            echo '<p>Hover a wiki link or Wikipedia interwiki link to read the complete page in a scrollable window. You have 300 ms to move into the window after leaving the link. Drag its titlebar to pin it; pinned previews stay open until their trash button is clicked. Multiple previews can stay pinned. Resizing is not included yet.</p><p>The module replaces the standalone Preview plugin’s hover script while enabled, including links added by Todoist and Fokus panes. Disabling this module restores the standalone plugin on reload.</p>';
        }
    }
}
