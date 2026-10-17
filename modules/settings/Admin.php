<?php
if (!defined('DOKU_INC')) die();
use dokuwiki\Extension\AdminPlugin;
use dokuwiki\plugin\config\core\Configuration;

class FokusButler_Module_Settings_Admin extends AdminPlugin {
    use FokusButler_ModuleContext;
    protected const MODULE = 'settings';
    private $submitted = [];
    private $errors = [];
    private $notice = '';
    public function forAdminOnly() { return true; }
    public function getMenuSort() { return 140; }
    public function getMenuIcon() { return __DIR__ . '/admin.svg'; }
    public function getMenuText($language) { return 'Fokus Settings'; }
    private function schema() {
        $file = DOKU_INC . 'lib/tpl/fokus/settings-schema.php';
        if (!is_file($file)) return [];
        require_once $file;
        return fokus_settings_schema();
    }
    public function handle() {
        global $INPUT, $conf;
        if (!$INPUT->post->has('fokus_settings_save') && !$INPUT->post->has('fokus_settings_reset')) return;
        if (!auth_isadmin() || !checkSecurityToken()) { $this->errors[] = 'The security token expired. Reload this page before saving.'; return; }
        if (($conf['template'] ?? '') !== 'fokus') { $this->errors[] = 'Activate the Fokus template before changing its settings.'; return; }
        $schema = $this->schema();
        if (!$schema || !class_exists(Configuration::class)) { $this->errors[] = 'Fokus or Configuration Manager is unavailable.'; return; }
        $reset = $INPUT->post->str('fokus_settings_reset');
        $posted = $INPUT->post->arr('fokus');
        $values = [];
        foreach ($schema as $name => $field) {
            if ($reset !== '' && $field['group'] !== $reset) continue;
            if ($reset === '' && !array_key_exists($name, $posted)) continue;
            $value = $reset !== '' ? $field['default'] : $posted[$name];
            if (is_string($value)) $value = trim($value);
            $this->submitted[$name] = is_scalar($value) ? (string)$value : '';
            if (!fokus_settings_valid($field, $value)) { $this->errors[] = $field['label'] . ': enter a valid ' . $field['type'] . ' value.'; continue; }
            $values[$name] = $field['type'] === 'number' ? 0 + $value : $value;
        }
        if ($this->errors) return;
        try {
            $configuration = new Configuration();
            if ($configuration->isLocked()) throw new RuntimeException('Configuration is locked or not writable.');
            $settings = $configuration->getSettings();
            $changed = false;
            foreach ($values as $name => $value) {
                $setting = $settings['tpl____fokus____' . $name] ?? null;
                if (!$setting) throw new RuntimeException('A Fokus setting is unavailable. Reload Configuration Manager.');
                if ($setting->isProtected()) {
                    if ($reset !== '') continue; // Leave protected values in place when resetting a section.
                    throw new RuntimeException('A protected setting cannot be changed: ' . $schema[$name]['label']);
                }
                $changed = $setting->update($value) || $changed;
                if ($setting->hasError()) throw new RuntimeException('Invalid setting: ' . $schema[$name]['label']);
            }
            // Validate every value before writing any configuration.
            if ($changed) $configuration->save();
            $this->notice = $changed ? 'Fokus settings saved.' : 'Settings are already up to date.';
            // A redirect ensures the newly saved settings and appearance are loaded.
            global $ID;
            if ($changed) {
                $section = $INPUT->post->str('fokus_settings_section');
                if (!in_array($section, ['appearance','workspace'], true)) $section = 'appearance';
                $params = ['do'=>'admin', 'page'=>'fokusbutler', 'module'=>'settings', 'saved'=>1];
                $interwiki = $INPUT->post->str('fokus_interwiki_selected');
                if (preg_match('/^[a-z0-9_.:-]{1,100}$/iD', $interwiki)) $params['iw'] = $interwiki;
                send_redirect(wl($ID, $params, true, '&') . '#fokus-settings-' . $section);
            }
        } catch (Throwable $error) { $this->errors[] = $error->getMessage(); }
    }
    public function html() {
        global $conf, $ID, $INPUT;
        $schema = $this->schema();
        echo '<div class="fokus-settings"><h1>Fokus Settings</h1><p>Shape your workspace. Changes apply after saving and reloading open wiki tabs.</p>';
        if (!$schema) { echo '<p>Install the Fokus template to use these settings.</p></div>'; return; }
        $active = ($conf['template'] ?? '') === 'fokus';
        if (!$active) echo '<p class="fokus-settings-message">Fokus is not the active template. Settings are read-only until it is activated.</p>';
        if ($INPUT->get->bool('saved') || $this->notice) echo '<p class="fokus-settings-message" role="status">' . hsc($this->notice ?: 'Fokus settings saved.') . '</p>';
        foreach ($this->errors as $error) echo '<p class="fokus-settings-error" role="alert">' . hsc($error) . '</p>';
        $settings = class_exists(Configuration::class) ? (new Configuration())->getSettings() : [];
        if (!$settings) echo '<p class="fokus-settings-message">Enable Configuration Manager to save Fokus settings.</p>';
        echo '<nav class="fokus-settings-nav" aria-label="Settings sections">';
        foreach (['Appearance','Workspace'] as $group) echo '<a href="#fokus-settings-' . strtolower($group) . '">' . $group . '</a>';
        echo '</nav><form method="post" action="' . hsc(wl($ID, ['do'=>'admin','page'=>'fokusbutler', 'module'=>'settings'], true, '&')) . '"><input type="hidden" name="sectok" value="' . hsc(getSecurityToken()) . '">';
        echo '<input type="hidden" name="fokus_settings_section" value="appearance">';
        foreach (['Appearance','Workspace'] as $group) {
            echo '<section id="fokus-settings-' . strtolower($group) . '" class="fokus-settings-section"><h2>' . $group . '</h2>';
            if ($group === 'Appearance') echo '<p>Blank color fields use the template defaults. Missing page links have separate colors and underline styling; tag links have their own appearance controls, and Page Health keeps its own no-content link appearance. The wiki search popup shares the selected colors, typography, and corner radius; its width, results area height, and light/dark search-match highlight colors are set below.</p>';
            $sections = [];
            foreach ($schema as $name => $field) if ($field['group'] === $group) $sections[$field['section'] ?? $group][$name] = $field;
            if ($group === 'Appearance') {
                echo '<div class="fokus-settings-color-themes">';
                foreach (['Light colors', 'Dark colors'] as $themeSection) {
                    if (isset($sections[$themeSection])) $this->fieldset($themeSection, $sections[$themeSection], $settings, $active, true);
                    unset($sections[$themeSection]);
                }
                echo '</div>';
            }
            foreach ($sections as $section => $fields) {
                $this->fieldset($section, $fields, $settings, $active);
            }
            echo '<div class="fokus-settings-actions"><button type="submit" name="fokus_settings_save" value="1"' . (!$active || !$settings ? ' disabled' : '') . '>Save settings</button><button type="submit" name="fokus_settings_reset" value="' . $group . '"' . (!$active || !$settings ? ' disabled' : '') . ' formnovalidate>Restore ' . strtolower($group) . ' defaults</button></div></section>';
        }
        echo '</form></div>';
    }
    private function fieldset($section, $fields, $settings, $active, $colors = false) {
        global $conf;
        echo '<fieldset' . ($colors ? ' class="fokus-settings-color-theme"' : '') . '><legend>' . hsc($section) . '</legend><div class="fokus-settings-fields' . ($colors ? ' fokus-settings-fields--colors' : '') . '">';
        foreach ($fields as $name => $field) {
            $protected = isset($settings['tpl____fokus____' . $name]) && $settings['tpl____fokus____' . $name]->isProtected();
            $disabled = !$active || !$settings || $protected;
            $value = $protected ? ($conf['tpl']['fokus'][$name] ?? $field['default']) : ($this->submitted[$name] ?? ($conf['tpl']['fokus'][$name] ?? $field['default']));
            if ($field['type'] === 'interwiki') $this->interwikiFields($name, $value, $disabled, $protected);
            else $this->field($name, $field, $value, $disabled, $protected);
        }
        echo '</div></fieldset>';
    }
    private function field($name, $field, $value, $disabled, $protected, $inputName = true) {
        $id = 'fokus-setting-' . $name;
        $attrs = ' id="' . $id . '"' . ($inputName ? ' name="fokus[' . $name . ']"' : '') . ($disabled ? ' disabled' : '');
        echo '<div class="fokus-settings-field fokus-settings-field--' . hsc($field['type']) . '"><label for="' . $id . '">' . hsc($field['label']) . ($protected ? ' (protected)' : '') . '</label><div class="fokus-settings-control">';
        if ($field['type'] === 'bool') {
            if (!$disabled) echo '<input type="hidden" name="fokus[' . $name . ']" value="0">';
            echo '<input type="checkbox"' . $attrs . ' value="1"' . ($value ? ' checked' : '') . '>';
        } elseif ($field['type'] === 'choice') {
            echo '<select' . $attrs . '>';
            foreach ($field['choices'] as $key => $label) echo '<option value="' . hsc($key) . '"' . ((string)$value === (string)$key ? ' selected' : '') . '>' . hsc($label) . '</option>';
            echo '</select>';
        } else {
            $type = $field['type'] === 'number' ? 'number' : 'text';
            echo '<div class="fokus-settings-input"><input type="' . $type . '"' . $attrs . ' value="' . hsc($value) . '"';
            if ($type === 'number') echo ' min="' . $field['min'] . '" max="' . $field['max'] . '" step="' . ($field['step'] ?? 1) . '" required';
            if ($field['type'] === 'color') echo ' placeholder="' . $field['placeholder'] . '" pattern="#[a-fA-F0-9]{6}" spellcheck="false"';
            echo '>';
            if ($field['type'] === 'color') echo '<input type="color" aria-label="Choose ' . hsc(strtolower($field['section'] . ' ' . $field['label'])) . '" data-fokus-color="' . $id . '" value="' . hsc(preg_match('/^#[a-fA-F0-9]{6}$/D', (string)$value) ? $value : $field['placeholder']) . '"' . ($disabled ? ' disabled' : '') . '>';
            echo '</div>';
        }
        if (!empty($field['help'])) echo '<small>' . hsc($field['help']) . '</small>';
        echo '</div></div>';
    }
    private function interwikiFields($name, $value, $disabled, $protected) {
        global $INPUT;
        if (!fokus_interwiki_styles_valid((string)$value)) $value = '{}';
        $styles = fokus_interwiki_effective_styles($value);
        $definitions = getInterwiki();
        $prefixes = array_unique(array_merge(array_keys($definitions), array_keys($styles)));
        $prefixes = array_values(array_filter($prefixes, static fn($prefix) => is_string($prefix) && preg_match('/^[a-z0-9_.:-]{1,100}$/iD', $prefix)));
        usort($prefixes, 'strnatcasecmp');
        $selected = $INPUT->get->str('iw');
        if (!in_array($selected, $prefixes, true)) $selected = in_array('zotero', $prefixes, true) ? 'zotero' : ($prefixes[0] ?? '');
        echo '<div class="fokus-settings-interwiki"><p>Choose a configured interwiki prefix, including built-in definitions and your local shortcuts. Blank fields use the prefix defaults, then the existing stylesheet appearance. Zotero defaults are shown below; favicons stay supplied by DokuWiki.</p>';
        if ($protected) echo '<p>Interwiki appearance is protected and cannot be changed here.</p>';
        echo '<noscript><p>Enable JavaScript to edit interwiki styles here, or edit the interwiki appearance JSON in Configuration Manager.</p></noscript>';
        echo '<input type="hidden" data-fokus-interwiki-styles' . ($disabled ? ' disabled' : '') . ' name="fokus[' . $name . ']" value="' . hsc($value) . '">';
        echo '<label for="fokus-interwiki-prefix">Interwiki prefix</label><select id="fokus-interwiki-prefix" name="fokus_interwiki_selected">';
        foreach ($prefixes as $prefix) echo '<option value="' . hsc($prefix) . '"' . ($selected === $prefix ? ' selected' : '') . '>' . hsc($prefix . ' · iw_' . preg_replace('/[^_\-a-z0-9]+/i', '_', $prefix)) . '</option>';
        echo '</select>';
        foreach ($prefixes as $prefix) {
            echo '<div class="fokus-interwiki-panel" data-fokus-interwiki-prefix="' . hsc($prefix) . '"' . ($prefix !== $selected ? ' hidden' : '') . '><h4>' . hsc($prefix) . '</h4>';
            $interwikiFields = fokus_interwiki_style_fields();
            echo '<div class="fokus-interwiki-color-themes">';
            foreach (['light'=>'Light colors', 'dark'=>'Dark colors'] as $theme => $label) {
                echo '<fieldset class="fokus-interwiki-color-theme"><legend>' . $label . '</legend><div class="fokus-settings-fields fokus-settings-fields--colors">';
                foreach ($interwikiFields as $key => $field) {
                    if (($field['theme'] ?? '') !== $theme) continue;
                    $this->interwikiField($prefix, $key, $field, $styles[$prefix][$key] ?? '', $disabled);
                }
                echo '</div></fieldset>';
            }
            echo '</div>';
            echo '<fieldset class="fokus-interwiki-style-theme"><legend>Link styling</legend><div class="fokus-settings-fields">';
            foreach ($interwikiFields as $key => $field) {
                if (isset($field['theme'])) continue;
                $this->interwikiField($prefix, $key, $field, $styles[$prefix][$key] ?? '', $disabled);
            }
            echo '</div></fieldset></div>';
        }
        echo '</div>';
    }
    private function interwikiField($prefix, $key, $field, $value, $disabled) {
        echo '<div data-fokus-interwiki-field="' . hsc($key) . '">';
        $this->field('iw_' . substr(sha1($prefix), 0, 12) . '_' . $key, $field, $value, $disabled, false, false);
        echo '</div>';
    }
}
