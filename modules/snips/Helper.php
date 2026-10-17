<?php

if (!defined('DOKU_INC')) die();

/** Reads and normalizes snippets managed by the Snips admin page. */
class FokusButler_Module_Snips_Helper extends DokuWiki_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'snips';
    public function getSnippets()
    {
        $file = $this->getDataFile();
        if (!is_readable($file)) return [];
        $contents = file_get_contents($file);
        if ($contents === false) return [];
        $snippets = json_decode($contents, true);
        if (!is_array($snippets)) return [];

        $valid = [];
        foreach ($snippets as $snippet) {
            if (!is_array($snippet)) continue;
            $mode = $snippet['mode'] ?? '';
            $label = trim((string)($snippet['label'] ?? ''));
            if ($label === '' || !in_array($mode, ['insert', 'wrap'], true)) continue;

            $normalized = [
                'label' => $label,
                'icon' => trim((string)($snippet['icon'] ?? '')),
                'mode' => $mode,
                'enabled' => !empty($snippet['enabled']),
            ];
            if ($mode === 'wrap') {
                $normalized['open'] = (string)($snippet['open'] ?? '');
                $normalized['close'] = (string)($snippet['close'] ?? '');
                if ($normalized['open'] === '' && $normalized['close'] === '') continue;
            } else {
                $normalized['insert'] = (string)($snippet['insert'] ?? '');
                if ($normalized['insert'] === '') continue;
            }
            $valid[] = $normalized;
        }
        return $valid;
    }

    public function getDataFile()
    {
        return DOKU_CONF . 'plugin/fokusbutler/snips/snippets.json';
    }

    public function getIconUrl($icon)
    {
        $icon = trim((string)$icon);
        if ($icon === '') return DOKU_BASE . 'lib/plugins/fokusbutler/modules/snips/admin.svg';
        if (preg_match('#^(https?://|/)#i', $icon)) return $icon;
        if (strpos($icon, ':') !== false) return ml($icon);
        if (strpos($icon, 'lib/') === 0) return DOKU_BASE . $icon;
        return DOKU_BASE . 'lib/plugins/fokusbutler/modules/snips/' . ltrim($icon, '/');
    }

    /**
     * Return an icon path in the format expected by DokuWiki's toolbar code.
     * Toolbar icons are resolved relative to lib/images/toolbar unless the
     * path begins with a slash.
     */
    public function getToolbarIconPath($icon)
    {
        $icon = trim((string)$icon);
        if ($icon === '') return '../../plugins/fokusbutler/modules/snips/admin.svg';

        // DokuWiki's core toolbar supports root-relative and relative paths.
        if (preg_match('#^https?://#i', $icon)) {
            return '//' . preg_replace('#^https?://#i', '', $icon);
        }
        if (strpos($icon, '/') === 0) return $icon;
        if (strpos($icon, ':') !== false) {
            return '../../../lib/exe/fetch.php?media=' . rawurlencode($icon);
        }
        if (strpos($icon, 'lib/') === 0) return '../../../' . $icon;
        if (strpos($icon, '../../plugins/') === 0) return $icon;
        return '../../plugins/fokusbutler/modules/snips/' . ltrim($icon, '/');
    }
}
