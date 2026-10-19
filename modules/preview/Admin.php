<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Settings.php';

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\plugin\config\core\Configuration;

class FokusButler_Module_Preview_Admin extends AdminPlugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'preview';
    private $submitted = [];

    public function forAdminOnly() { return true; }

    public function handle()
    {
        global $INPUT;
        if (!auth_isadmin() || !$INPUT->post->bool('preview_save')) return;
        if (!checkSecurityToken()) { msg('The security token expired. Reload and try again.', -1); return; }
        try {
            $configuration = new Configuration();
            if ($configuration->isLocked()) throw new RuntimeException('Configuration is locked.');
            $settings = $configuration->getSettings();
            $updates = [];
            foreach (FokusButler_Preview_Settings::fields() as $key => $field) {
                $setting = $settings['plugin____fokusbutler____preview_' . $key] ?? null;
                if (!$setting) throw new RuntimeException('A preview setting is unavailable. Reload Configuration Manager.');
                if ($setting->isProtected()) continue;
                $this->submitted[$key] = $INPUT->post->str($key);
            }
            // Validate every submitted value before updating or saving any field.
            foreach ($this->submitted as $key => $value) $updates[$key] = FokusButler_Preview_Settings::validate($key, $value);
            $changed = false;
            foreach ($updates as $key => $value) {
                $setting = $settings['plugin____fokusbutler____preview_' . $key];
                $changed = $setting->update($value) || $changed;
                if ($setting->hasError()) throw new RuntimeException('Invalid preview setting.');
            }
            if ($changed) $configuration->save();
            msg('Preview settings saved. Reload open wiki tabs to apply changes.', 1);
            send_redirect(FokusButler_Modules::adminUrl('preview'));
        } catch (Throwable $error) { msg($error->getMessage(), -1); }
    }

    public function html()
    {
        if (!auth_isadmin()) return;
        $configuration = new Configuration();
        $settings = $configuration->getSettings();
        $values = FokusButler_Preview_Settings::values();
        echo '<div class="fokus-preview-settings"><h2>Page previews</h2><p>Set the size and timing of wiki and Wikipedia hover windows.</p>';
        echo '<form method="post" action="' . hsc(FokusButler_Modules::adminUrl('preview')) . '"><input type="hidden" name="module" value="preview"><input type="hidden" name="preview_save" value="1">';
        formSecurityToken();
        echo '<div class="fokus-preview-settings-fields">';
        foreach (FokusButler_Preview_Settings::fields() as $key => $field) {
            $setting = $settings['plugin____fokusbutler____preview_' . $key] ?? null;
            $disabled = !$setting || $setting->isProtected() || $configuration->isLocked();
            echo '<div class="fokus-preview-setting"><label for="fokus-preview-setting-' . hsc($key) . '">' . hsc($field['label']) . '</label><div class="fokus-preview-setting-input">';
            echo '<input type="number" id="fokus-preview-setting-' . hsc($key) . '" name="' . hsc($key) . '" value="' . hsc($this->submitted[$key] ?? $values[$key]) . '" min="' . $field['min'] . '" max="' . $field['max'] . '" step="1" required aria-describedby="fokus-preview-help-' . hsc($key) . '"' . ($disabled ? ' disabled' : '') . '><span>' . $field['unit'] . '</span></div>';
            echo '<p id="fokus-preview-help-' . hsc($key) . '">' . hsc($field['help']) . '<br><span>Default: ' . $field['default'] . ' ' . $field['unit'] . ' · Range: ' . $field['min'] . '–' . $field['max'] . ' ' . $field['unit'] . ($setting && $setting->isProtected() ? ' · Protected setting' : '') . '</span></p></div>';
        }
        echo '</div><p><button class="button" type="submit"' . ($configuration->isLocked() ? ' disabled' : '') . '>Save preview settings</button></p></form></div>';
    }
}
