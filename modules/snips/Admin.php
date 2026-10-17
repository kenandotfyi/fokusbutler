<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;

/** Admin interface for managing toolbar snippets. */
class FokusButler_Module_Snips_Admin extends AdminPlugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'snips';
    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuSort()
    {
        return 165;
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
        if (!$INPUT->post->bool('snips_save')) return;
        if (!checkSecurityToken()) return;

        $submitted = $INPUT->post->arr('snippets');
        $snippets = [];
        foreach ($submitted as $snippet) {
            if (!is_array($snippet)) continue;
            $label = trim((string)($snippet['label'] ?? ''));
            if ($label === '') continue;
            $mode = (string)($snippet['mode'] ?? '');
            if (!in_array($mode, ['insert', 'wrap'], true)) continue;

            $item = [
                'label' => $label,
                'icon' => trim((string)($snippet['icon'] ?? '')),
                'mode' => $mode,
                'enabled' => !empty($snippet['enabled']),
            ];
            if ($mode === 'wrap') {
                $item['open'] = (string)($snippet['open'] ?? '');
                $item['close'] = (string)($snippet['close'] ?? '');
                if ($item['open'] === '' && $item['close'] === '') continue;
            } else {
                $item['insert'] = (string)($snippet['insert'] ?? '');
                if ($item['insert'] === '') continue;
            }
            $snippets[] = $item;
            if (count($snippets) >= 100) break;
        }

        /** @var FokusButler_Module_Snips_Helper|null $helper */
        $helper = plugin_load('helper', 'fokusbutler_snips');
        if (!$helper) {
            msg($this->getLang('save_failed'), -1);
            return;
        }
        $file = $helper->getDataFile();
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            msg($this->getLang('save_failed'), -1);
            return;
        }
        $json = json_encode($snippets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || !io_saveFile($file, $json . "\n")) {
            msg($this->getLang('save_failed'), -1);
            return;
        }
        msg($this->getLang('saved'), 1);
        send_redirect(wl($ID, ['do' => 'admin', 'page' => 'fokusbutler', 'module'=>'snips'], true, '&'));
    }

    public function html()
    {
        global $ID;
        /** @var FokusButler_Module_Snips_Helper|null $helper */
        $helper = plugin_load('helper', 'fokusbutler_snips');
        $snippets = $helper ? $helper->getSnippets() : [];

        echo '<div class="plugin_snips_admin">';
        echo '<h1>' . hsc($this->getLang('heading')) . '</h1>';
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';
        echo '<p>' . hsc($this->getLang('storage_note')) . '</p>';
        echo '<form action="' . hsc(script()) . '" method="post" id="plugin_snips_form">';
        echo '<input type="hidden" name="id" value="' . hsc($ID) . '">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="fokusbutler"><input type="hidden" name="module" value="snips">';
        echo '<input type="hidden" name="snips_save" value="1">';
        formSecurityToken();
        echo '<div id="plugin_snips_list">';
        foreach ($snippets as $index => $snippet) $this->renderSnippet($index, $snippet);
        echo '</div>';
        echo '<p><button class="button" type="button" id="plugin_snips_add">' . hsc($this->getLang('add_snippet')) . '</button> ';
        echo '<button class="button" type="submit">' . hsc($this->getLang('save')) . '</button></p>';
        echo '</form>';
        echo '<template id="plugin_snips_template">';
        $this->renderSnippet('__INDEX__', [
            'label' => '', 'icon' => '', 'mode' => 'insert', 'enabled' => true,
            'insert' => '', 'open' => '', 'close' => '',
        ]);
        echo '</template></div>';
        $this->renderScript();
    }

    private function renderSnippet($index, array $snippet)
    {
        $prefix = 'snippets[' . $index . ']';
        echo '<fieldset class="plugin_snips_item"><legend>' . hsc($this->getLang('snippet')) . '</legend>';
        echo '<div class="plugin_snips_controls"><button type="button" class="button plugin_snips_up" title="' . hsc($this->getLang('move_up')) . '">↑</button> ';
        echo '<button type="button" class="button plugin_snips_down" title="' . hsc($this->getLang('move_down')) . '">↓</button> ';
        echo '<button type="button" class="button plugin_snips_remove">' . hsc($this->getLang('remove')) . '</button></div>';
        echo '<table class="inline"><tbody>';
        echo '<tr><th><label>' . hsc($this->getLang('enabled')) . '</label></th><td><input type="checkbox" name="' . hsc($prefix) . '[enabled]" value="1"';
        if (!empty($snippet['enabled'])) echo ' checked="checked"';
        echo '></td></tr>';
        echo '<tr><th><label>' . hsc($this->getLang('label')) . '</label></th><td><input type="text" name="' . hsc($prefix) . '[label]" value="' . hsc($snippet['label'] ?? '') . '" required></td></tr>';
        echo '<tr><th><label>' . hsc($this->getLang('icon')) . '</label></th><td><input class="plugin_snips_icon_input" type="text" name="' . hsc($prefix) . '[icon]" value="' . hsc($snippet['icon'] ?? '') . '" placeholder="wiki:snips:icons:info.svg">';
        $icon = trim((string)($snippet['icon'] ?? ''));
        echo '<img class="plugin_snips_icon_preview" src="' . hsc($this->getIconPreviewUrl($icon)) . '" alt=""';
        if ($icon === '') echo ' hidden';
        echo '>';
        echo '<div class="plugin_snips_help">' . hsc($this->getLang('icon_help')) . '</div></td></tr>';
        echo '<tr><th><label>' . hsc($this->getLang('mode')) . '</label></th><td><select class="plugin_snips_mode" name="' . hsc($prefix) . '[mode]">';
        foreach (['insert', 'wrap'] as $mode) {
            echo '<option value="' . $mode . '"';
            if (($snippet['mode'] ?? 'insert') === $mode) echo ' selected="selected"';
            echo '>' . hsc($this->getLang('mode_' . $mode)) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr class="plugin_snips_insert_row"><th><label>' . hsc($this->getLang('insert_text')) . '</label></th><td><textarea name="' . hsc($prefix) . '[insert]" rows="4">' . hsc($snippet['insert'] ?? '') . '</textarea></td></tr>';
        echo '<tr class="plugin_snips_wrap_row"><th><label>' . hsc($this->getLang('open_text')) . '</label></th><td><textarea name="' . hsc($prefix) . '[open]" rows="3">' . hsc($snippet['open'] ?? '') . '</textarea></td></tr>';
        echo '<tr class="plugin_snips_wrap_row"><th><label>' . hsc($this->getLang('close_text')) . '</label></th><td><textarea name="' . hsc($prefix) . '[close]" rows="3">' . hsc($snippet['close'] ?? '') . '</textarea></td></tr>';
        echo '</tbody></table></fieldset>';
    }

    private function getIconPreviewUrl($icon)
    {
        /** @var FokusButler_Module_Snips_Helper|null $helper */
        $helper = plugin_load('helper', 'fokusbutler_snips');
        return $helper ? $helper->getIconUrl($icon) : DOKU_BASE . 'lib/plugins/fokusbutler/modules/snips/admin.svg';
    }

    private function renderScript()
    {
        ?>
        <script>
        (() => {
            const list = document.getElementById('plugin_snips_list');
            const template = document.getElementById('plugin_snips_template');
            const addButton = document.getElementById('plugin_snips_add');
            const snippetLabel = <?php echo json_encode($this->getLang('snippet'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            if (!list || !template || !addButton) return;

            const updateRows = () => {
                list.querySelectorAll('.plugin_snips_item').forEach((item, index) => {
                    const mode = item.querySelector('.plugin_snips_mode').value;
                    item.querySelectorAll('.plugin_snips_insert_row').forEach(row => row.hidden = mode !== 'insert');
                    item.querySelectorAll('.plugin_snips_wrap_row').forEach(row => row.hidden = mode !== 'wrap');
                    item.querySelector('legend').textContent = `${index + 1}. ${snippetLabel}`;
                });
                list.querySelectorAll('.plugin_snips_up').forEach((button, index) => button.disabled = index === 0);
                list.querySelectorAll('.plugin_snips_down').forEach((button, index, buttons) => button.disabled = index === buttons.length - 1);
            };

            addButton.addEventListener('click', () => {
                const index = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
                list.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', index));
                updateRows();
                list.lastElementChild?.querySelector('input[type="text"]')?.focus();
            });
            list.addEventListener('click', event => {
                const item = event.target.closest('.plugin_snips_item');
                if (!item) return;
                if (event.target.closest('.plugin_snips_remove')) item.remove();
                if (event.target.closest('.plugin_snips_up') && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
                if (event.target.closest('.plugin_snips_down') && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
                updateRows();
            });
            list.addEventListener('change', event => {
                if (event.target.matches('.plugin_snips_mode')) updateRows();
            });
            list.addEventListener('input', event => {
                if (!event.target.matches('.plugin_snips_icon_input')) return;
                const icon = event.target.value.trim();
                const preview = event.target.parentElement.querySelector('.plugin_snips_icon_preview');
                if (!preview) return;
                preview.hidden = !icon;
                if (!icon) return;
                if (/^(https?:\/\/|\/)/i.test(icon)) preview.src = icon;
                else if (icon.includes(':')) preview.src = `<?php echo DOKU_BASE; ?>lib/exe/fetch.php?media=${encodeURIComponent(icon)}`;
                else if (icon.startsWith('lib/')) preview.src = `<?php echo DOKU_BASE; ?>${icon}`;
                else preview.src = `<?php echo DOKU_BASE; ?>lib/plugins/fokusbutler/modules/snips/${icon.replace(/^\/+/, '')}`;
            });
            updateRows();
        })();
        </script>
        <?php
    }
}
