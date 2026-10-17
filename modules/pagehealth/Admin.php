<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\plugin\config\core\Configuration;

/** Admin overview and one-time backfill for Page Health. */
class FokusButler_Module_Pagehealth_Admin extends AdminPlugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'pagehealth';
    private const CLASSIFICATION_VERSION = 2;
    private const RESULTS_LIMIT = 100;

    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuSort()
    {
        return 950;
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
        if (!$INPUT->post->bool('pagehealth_save_style')) return;
        if (!checkSecurityToken()) return;

        if (!class_exists(Configuration::class)) {
            msg($this->getLang('config_unavailable'), -1);
            return;
        }

        $values = [
            'empty_color' => $INPUT->post->str('empty_color'),
            'empty_hover_color' => $INPUT->post->str('empty_hover_color'),
            'empty_opacity' => $INPUT->post->str('empty_opacity'),
            'empty_decoration' => $INPUT->post->str('empty_decoration'),
            'empty_decoration_style' => $INPUT->post->str('empty_decoration_style'),
            'empty_italic' => $INPUT->post->bool('empty_italic') ? 1 : 0,
        ];
        if (
            !preg_match('/^#[0-9a-fA-F]{6}$/', $values['empty_color']) ||
            !preg_match('/^#[0-9a-fA-F]{6}$/', $values['empty_hover_color']) ||
            !is_numeric($values['empty_opacity']) ||
            (float)$values['empty_opacity'] < 0.2 || (float)$values['empty_opacity'] > 1.0 ||
            !in_array($values['empty_decoration'], ['none', 'underline', 'overline', 'line-through'], true) ||
            !in_array($values['empty_decoration_style'], ['solid', 'dotted', 'dashed', 'wavy'], true)
        ) {
            msg($this->getLang('invalid_style'), -1);
            return;
        }

        try {
            $configuration = new Configuration();
            if ($configuration->isLocked()) {
                msg($this->getLang('config_locked'), -1);
                return;
            }

            $settings = $configuration->getSettings();
            $changed = false;
            foreach ($values as $name => $value) {
                $key = 'plugin____fokusbutler____pagehealth_' . $name;
                if (!isset($settings[$key])) {
                    msg($this->getLang('config_unavailable'), -1);
                    return;
                }
                if ($settings[$key]->isProtected()) {
                    msg($this->getLang('config_locked'), -1);
                    return;
                }
                if ($name === 'empty_opacity') $value = (float)$value;
                $changed = $settings[$key]->update($value) || $changed;
                if ($settings[$key]->hasError()) {
                    throw new RuntimeException($this->getLang('invalid_style'));
                }
            }

            if ($changed) {
                $configuration->save();
                msg($this->getLang('saved'), 1);
            } else {
                msg($this->getLang('unchanged'), 1);
            }
            send_redirect(wl($ID, ['do' => 'admin', 'page' => 'fokusbutler', 'module'=>'pagehealth'], true, '&'));
        } catch (Exception $exception) {
            msg($this->getLang('save_failed') . ' ' . hsc($exception->getMessage()), -1);
        }
    }

    public function html()
    {
        global $conf, $ID;

        $cacheDir = $conf['cachedir'] . '/fokusbutler/pagehealth';
        $index = $this->readJson($cacheDir . '/index.json');
        $scan = $this->readJson($cacheDir . '/scan.json');
        $validIndex = is_array($index)
            && ($index['version'] ?? null) === self::CLASSIFICATION_VERSION
            && is_array($index['pages'] ?? null);
        $validScan = is_array($scan)
            && ($scan['version'] ?? null) === self::CLASSIFICATION_VERSION
            && is_array($scan['ids'] ?? null);

        $pageStates = $validIndex ? $index['pages'] : [];
        $emptyPages = [];
        $contentCount = 0;
        foreach ($pageStates as $id => $empty) {
            $id = (string)$id;
            if (!is_bool($empty) || !page_exists($id)) continue;
            if ($empty) {
                $emptyPages[] = $id;
            } else {
                $contentCount++;
            }
        }
        sort($emptyPages, SORT_STRING);

        $total = $validScan ? count($scan['ids']) : 0;
        $processed = $validScan ? min(max(0, (int)($scan['offset'] ?? 0)), $total) : 0;
        $complete = $validScan && !empty($scan['done']) && $validIndex;
        if (!$validIndex) $complete = false;
        $classifiedCount = count($emptyPages) + $contentCount;

        echo '<div class="plugin_pagehealth_admin">';
        echo '<h1>' . hsc($this->getLang('heading')) . '</h1>';
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';

        echo '<table class="inline"><tbody>';
        echo '<tr><th>' . hsc($this->getLang('classified')) . '</th><td>' . $classifiedCount . '</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('empty_pages')) . '</th><td>' . count($emptyPages) . '</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('content_pages')) . '</th><td>' . $contentCount . '</td></tr>';
        echo '</tbody></table>';

        if ($complete) {
            $initialStatus = sprintf($this->getLang('scan_complete'), $total);
        } elseif ($validScan) {
            $initialStatus = sprintf($this->getLang('scan_progress'), $processed, $total);
        } else {
            $initialStatus = $this->getLang('scan_not_started');
        }

        echo '<form id="plugin_pagehealth_scan_form" action="' . hsc(script()) . '" method="post">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="fokusbutler"><input type="hidden" name="module" value="pagehealth">';
        formSecurityToken();
        echo '<button class="button" id="plugin_pagehealth_scan" type="button"' . (!FokusButler_Modules::enabled('pagehealth') ? ' disabled' : '') . '>';
        echo hsc($this->getLang($complete ? 'scan_again' : ($validScan ? 'scan_continue' : 'scan_start')));
        echo '</button> ';
        echo '<span id="plugin_pagehealth_scan_status" aria-live="polite">' . hsc($initialStatus) . '</span>';
        echo '</form>';

        echo '<h2>' . hsc($this->getLang('appearance')) . '</h2>';
        echo '<form action="' . hsc(script()) . '" method="post">';
        echo '<input type="hidden" name="id" value="' . hsc($ID) . '">';
        echo '<input type="hidden" name="do" value="admin">';
        echo '<input type="hidden" name="page" value="fokusbutler"><input type="hidden" name="module" value="pagehealth">';
        echo '<input type="hidden" name="pagehealth_save_style" value="1">';
        formSecurityToken();
        echo '<table class="inline"><tbody>';
        $this->renderColorSetting('empty_color');
        $this->renderColorSetting('empty_hover_color');
        echo '<tr><th><label for="pagehealth-empty-opacity">' . hsc($this->getLang('empty_opacity')) . '</label></th><td>';
        echo '<input id="pagehealth-empty-opacity" type="number" name="empty_opacity" min="0.2" max="1" step="0.05" value="';
        echo hsc($this->getConf('empty_opacity')) . '"></td></tr>';
        $this->renderSelectSetting('empty_decoration', ['none', 'underline', 'overline', 'line-through']);
        $this->renderSelectSetting('empty_decoration_style', ['solid', 'dotted', 'dashed', 'wavy']);
        echo '<tr><th><label for="pagehealth-empty-italic">' . hsc($this->getLang('empty_italic')) . '</label></th><td>';
        echo '<input id="pagehealth-empty-italic" type="checkbox" name="empty_italic" value="1"';
        if ($this->getConf('empty_italic')) echo ' checked="checked"';
        echo '></td></tr></tbody></table>';
        echo '<p><button class="button" type="submit">' . hsc($this->getLang('save_style')) . '</button></p>';
        echo '</form>';

        echo '<h2>' . hsc($this->getLang('empty_results')) . '</h2>';
        if (!$emptyPages) {
            echo '<p>' . hsc($this->getLang('no_empty_pages')) . '</p>';
        } else {
            echo '<ul>';
            foreach (array_slice($emptyPages, 0, self::RESULTS_LIMIT) as $id) {
                echo '<li><a href="' . hsc(wl($id)) . '">' . hsc($id) . '</a></li>';
            }
            echo '</ul>';
            if (count($emptyPages) > self::RESULTS_LIMIT) {
                echo '<p>' . sprintf(hsc($this->getLang('results_limited')), self::RESULTS_LIMIT, count($emptyPages)) . '</p>';
            }
        }

        $endpoint = json_encode(DOKU_BASE . 'lib/exe/ajax.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <script>
        (() => {
            const button = document.getElementById('plugin_pagehealth_scan');
            const form = document.getElementById('plugin_pagehealth_scan_form');
            const status = document.getElementById('plugin_pagehealth_scan_status');
            if (!button || !form || !status) return;

            button.addEventListener('click', async () => {
                const token = form.querySelector('input[name="sectok"]')?.value || '';
                const restart = <?php echo $complete ? 'true' : 'false'; ?>;
                let firstRequest = true;
                button.disabled = true;
                status.textContent = <?php echo json_encode($this->getLang('scan_running')); ?>;

                while (true) {
                    const body = new URLSearchParams();
                    body.set('call', 'fokusbutler_pagehealth_scan');
                    body.set('sectok', token);
                    body.set('restart', firstRequest && restart ? '1' : '0');
                    firstRequest = false;
                    try {
                        const response = await fetch(<?php echo $endpoint; ?>, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body
                        });
                        if (!response.ok) throw new Error('Scan request failed.');
                        const result = await response.json();
                        status.textContent = result.done
                            ? <?php echo json_encode($this->getLang('scan_finished')); ?>
                            : <?php echo json_encode($this->getLang('scan_progress_live')); ?>.replace('%1$d', result.processed).replace('%2$d', result.total);
                        if (result.error) throw new Error('Scan could not save its progress.');
                        if (result.done) {
                            window.location.reload();
                            return;
                        }
                    } catch (error) {
                        status.textContent = <?php echo json_encode($this->getLang('scan_failed')); ?>;
                        button.disabled = false;
                        return;
                    }
                }
            });
        })();
        </script>
        <?php
        echo '</div>';
    }

    private function readJson($file)
    {
        if (!is_file($file)) return null;
        $value = json_decode((string)file_get_contents($file), true);
        return is_array($value) ? $value : null;
    }

    private function renderColorSetting($name)
    {
        echo '<tr><th><label for="pagehealth-' . hsc($name) . '">' . hsc($this->getLang($name)) . '</label></th><td>';
        echo '<input id="pagehealth-' . hsc($name) . '" type="color" name="' . hsc($name) . '" value="';
        echo hsc($this->getConf($name)) . '"></td></tr>';
    }

    private function renderSelectSetting($name, $choices)
    {
        echo '<tr><th><label for="pagehealth-' . hsc($name) . '">' . hsc($this->getLang($name)) . '</label></th><td>';
        echo '<select id="pagehealth-' . hsc($name) . '" name="' . hsc($name) . '">';
        foreach ($choices as $choice) {
            echo '<option value="' . hsc($choice) . '"';
            if ($this->getConf($name) === $choice) echo ' selected="selected"';
            echo '>' . hsc($this->getLang($name . '_' . $choice)) . '</option>';
        }
        echo '</select></td></tr>';
    }
}
