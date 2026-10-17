<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\plugin\config\core\Configuration;

/** Admin settings for the ImagePop image gallery. */
class FokusButler_Module_Imagepop_Admin extends AdminPlugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'imagepop';
    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuSort()
    {
        return 930;
    }

    public function getMenuIcon()
    {
        return __DIR__ . '/admin.svg';
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    public function handle()
    {
        global $INPUT, $ID;
        if (!$INPUT->post->bool('imagepop_save')) return;
        if (!checkSecurityToken()) {
            msg($this->getLang('token_expired'), -1);
            return;
        }

        $selector = trim($INPUT->post->str('image_selector'));
        if ($selector === '' || strlen($selector) > 2048) {
            msg($this->getLang('invalid_selector'), -1);
            return;
        }
        if (!class_exists(Configuration::class)) {
            msg($this->getLang('config_unavailable'), -1);
            return;
        }

        try {
            $configuration = new Configuration();
            if ($configuration->isLocked()) {
                msg($this->getLang('config_locked'), -1);
                return;
            }
            $settings = $configuration->getSettings();
            $key = 'plugin____fokusbutler____imagepop_image_selector';
            if (!isset($settings[$key])) {
                msg($this->getLang('config_unavailable'), -1);
                return;
            }
            if ($settings[$key]->isProtected()) {
                msg($this->getLang('protected'), -1);
                return;
            }
            $changed = $settings[$key]->update($selector);
            if ($settings[$key]->hasError()) {
                msg($this->getLang('invalid_selector'), -1);
                return;
            }
            if ($changed) {
                $configuration->save();
                msg($this->getLang('saved'), 1);
            } else {
                msg($this->getLang('unchanged'), 1);
            }
            send_redirect(wl($ID, ['do' => 'admin', 'page' => 'fokusbutler', 'module'=>'imagepop'], true, '&'));
        } catch (Throwable $exception) {
            msg($this->getLang('save_failed') . ' ' . hsc($exception->getMessage()), -1);
        }
    }

    public function html()
    {
        global $ID;
        $selector = (string)$this->getConf('image_selector');
        $setting = null;
        if (class_exists(Configuration::class)) {
            $settings = (new Configuration())->getSettings();
            $setting = $settings['plugin____fokusbutler____imagepop_image_selector'] ?? null;
        }
        $disabled = !$setting || $setting->isProtected();

        echo '<div class="plugin_imagepop_admin">';
        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';
        if (!$setting) echo '<p class="error">' . hsc($this->getLang('config_unavailable')) . '</p>';
        elseif ($disabled) echo '<p class="info">' . hsc($this->getLang('protected')) . '</p>';

        echo '<form action="' . hsc(script()) . '" method="post">';
        echo '<input type="hidden" name="id" value="' . hsc($ID) . '">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="fokusbutler"><input type="hidden" name="module" value="imagepop">';
        echo '<input type="hidden" name="imagepop_save" value="1">';
        formSecurityToken();
        echo '<table class="inline"><tbody><tr><th><label for="imagepop-image-selector">' . hsc($this->getLang('selector_label')) . '</label></th><td>';
        echo '<textarea id="imagepop-image-selector" name="image_selector" rows="4" cols="72" maxlength="2048" required' . ($disabled ? ' disabled' : '') . '>' . hsc($selector) . '</textarea>';
        echo '<p>' . hsc($this->getLang('selector_help')) . '</p>';
        echo '</td></tr></tbody></table>';
        echo '<p><button class="button" type="submit"' . ($disabled ? ' disabled' : '') . '>' . hsc($this->getLang('save')) . '</button></p>';
        echo '</form></div>';
    }
}
