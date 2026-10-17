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
                    foreach (['preview', 'annotations', 'katex'] as $id) {
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
        echo '<div class="fokusbutler-admin"><header class="fokusbutler-admin-header"><h1>Fokus Butler</h1><p>Your Fokus companion, organized into independent feature modules.</p></header>';
        echo '<nav class="fokusbutler-nav" aria-label="Fokus Butler modules">';
        $tabs = ['modules' => ['name' => 'Modules']] + FokusButler_Modules::all();
        foreach ($tabs as $id => $entry) {
            echo '<a href="' . hsc(FokusButler_Modules::adminUrl($id)) . '"' . ($selected === $id ? ' aria-current="page"' : '') . '>' . hsc($entry['name']) . '</a>';
        }
        echo '</nav><div class="fokusbutler-panel" data-fokusbutler-module="' . hsc($selected) . '">';
        if ($selected === 'modules') $this->modulesPanel();
        else {
            if (!FokusButler_Modules::enabled($selected)) echo '<p class="fokusbutler-notice">This module is disabled. You can still edit its settings. Existing syntax remains readable.</p>';
            $panel = $this->panel ?: FokusButler_Modules::admin($selected);
            if ($panel) $panel->html();
            else $this->informationPanel($selected);
        }
        echo '</div></div>';
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
        foreach (['preview'=>['Preview','Link previews in panes'], 'annotations'=>['Annotations','Annotations in panes'], 'katex'=>['KaTeX','Math rendering in panes']] as $id => $info) {
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
        echo '<div class="fokusbutler-integration-grid">';
        foreach ($plugins as $id => $plugin) {
            $this->pluginCardStart($id, $plugin, empty($plugin['optional']) ? 'Required' : 'Optional');
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
        }
    }
}
