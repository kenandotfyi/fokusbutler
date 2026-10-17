<?php

if (!defined('DOKU_INC')) die();

/** Display Page Health summaries and a recent-changes list. */
class FokusButler_Module_Pagehealth_Syntax extends DokuWiki_Syntax_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'pagehealth';
    public function getType()
    {
        return 'substition';
    }

    public function getPType()
    {
        return 'normal';
    }

    public function getSort()
    {
        return 230;
    }

    public function connectTo($mode)
    {
        if ($mode === 'base') {
            $this->Lexer->addSpecialPattern('~~PAGEHEALTH~~', $mode, 'plugin_fokusbutler_pagehealth');
            $this->Lexer->addSpecialPattern('~~LAST30~~', $mode, 'plugin_fokusbutler_pagehealth');
        }
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return ['kind' => $match === '~~LAST30~~' ? 'last30' : 'summary'];
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format === 'metadata') {
            if (($data['kind'] ?? '') !== 'last30') return false;
            global $conf;
            $renderer->meta['relation']['depends']['rendering'][$conf['changelog']] = true;
            return true;
        }

        if ($format !== 'xhtml') return false;

        if (($data['kind'] ?? '') === 'last30') {
            $this->renderLast30($renderer);
            return true;
        }

        if (!FokusButler_Modules::enabled('pagehealth')) {
            $renderer->doc .= '<span class="plugin_pagehealth_summary">Page Health is disabled.</span>';
            return true;
        }
        $renderer->doc .= '<span class="plugin_pagehealth_summary" data-pagehealth-summary data-error="' .
            hsc($this->getLang('summary_unavailable')) . '">' .
            hsc($this->getLang('summary_loading')) .
            '</span>';
        return true;
    }

    private function renderLast30(Doku_Renderer $renderer)
    {
        global $conf;

        $changes = getRecents(0, 30);
        if (!$changes) {
            $renderer->doc .= '<span class="fokus-last30-empty">No recent page changes.</span>';
            return;
        }

        $renderer->doc .= '<span class="fokus-last30">';
        foreach ($changes as $change) {
            $id = $change['id'];
            $title = p_get_metadata($id, 'title');
            if (!is_string($title) || trim($title) === '') {
                $title = p_get_first_heading($id, false);
            }
            if (!is_string($title) || trim($title) === '') {
                $title = noNS($id);
            }

            $type = $this->changeLabel($change['type']);
            $typeClass = $this->changeClass($change['type']);
            $date = dformat((int) $change['date'], $conf['dformat']);
            $summary = trim((string) ($change['sum'] ?? ''));

            $renderer->doc .= '<span class="fokus-last30-item">';
            $renderer->doc .= '<span class="fokus-last30-type fokus-last30-type--' . hsc($typeClass) . '">' . hsc($type) . '</span>';
            $renderer->doc .= '<span class="fokus-last30-title">' . html_wikilink(':' . $id, $title) . '</span>';
            if ($summary !== '') {
                $renderer->doc .= '<span class="fokus-last30-summary">' . hsc($summary) . '</span>';
            }
            $renderer->doc .= '<time datetime="' . hsc(date('c', (int) $change['date'])) . '">' . hsc($date) . '</time>';
            $renderer->doc .= '<span class="fokus-last30-actions">';
            $renderer->doc .= '<a href="' . hsc(wl($id, ['do' => 'diff'])) . '" aria-label="Compare changes for ' . hsc($title) . '" title="Diff">' . $this->icon('git-diff', 'Diff') . '</a>';
            $renderer->doc .= '<a href="' . hsc(wl($id, ['do' => 'revisions'])) . '" aria-label="View revisions for ' . hsc($title) . '" title="Revisions">' . $this->icon('clock-counter-clockwise', 'Revisions') . '</a>';
            $renderer->doc .= '</span>';
            $renderer->doc .= '</span>';
        }
        $renderer->doc .= '</span>';
    }

    private function changeLabel($type)
    {
        switch ($type) {
            case DOKU_CHANGE_TYPE_CREATE: return 'Created';
            case DOKU_CHANGE_TYPE_DELETE: return 'Deleted';
            case DOKU_CHANGE_TYPE_MINOR_EDIT: return 'Minor';
            case DOKU_CHANGE_TYPE_REVERT: return 'Reverted';
            default: return 'Edited';
        }
    }

    private function changeClass($type)
    {
        switch ($type) {
            case DOKU_CHANGE_TYPE_DELETE: return 'deleted';
            case DOKU_CHANGE_TYPE_MINOR_EDIT: return 'minor';
            case DOKU_CHANGE_TYPE_CREATE: return 'created';
            case DOKU_CHANGE_TYPE_REVERT: return 'reverted';
            default: return 'edited';
        }
    }

    private function icon($name, $fallback)
    {
        $path = __DIR__ . '/icons/' . $name . '-duotone.svg';
        if (!is_readable($path)) return '<span>' . hsc($fallback) . '</span>';
        return str_replace('<svg ', '<svg aria-hidden="true" focusable="false" ', file_get_contents($path));
    }
}
