<?php

if (!defined('DOKU_INC')) die();

/** Render a Todoist-backed task embedded in a wiki page. */
class FokusButler_Module_Todoist_Syntax extends DokuWiki_Syntax_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'todoist';
    public function getType() { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort() { return 170; }

    public function connectTo($mode)
    {
        if ($mode === 'base') {
            $this->Lexer->addSpecialPattern('\\{\\{todoistcompanion>[^}]*\\}\\}', $mode, 'plugin_fokusbutler_todoist');
            $this->Lexer->addSpecialPattern('~~TODOS~~', $mode, 'plugin_fokusbutler_todoist');
        }
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        if ($match === '~~TODOS~~') return ['type' => 'list'];
        preg_match('/^\\{\\{todoistcompanion>(.*?)\\}\\}$/s', $match, $matched);
        $body = $matched[1] ?? '';
        if (!preg_match('/^(.*?)\\|id=((?:[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|[a-z0-9]{8}))(?:\\|(?:due|reminder)=(\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2})\\|tz=([A-Za-z0-9_+.-]+(?:\\/[A-Za-z0-9_+.-]+)*))?$/is', trim($body), $parts)) {
            return ['type' => 'task', 'key' => '', 'title' => trim($body)];
        }
        return [
            'type' => 'task',
            'key' => strtolower($parts[2]),
            'title' => trim($parts[1]),
            'due' => $parts[3] ?? '',
        ];
    }

    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format === 'metadata' && ($data['type'] ?? '') === 'list') {
            $renderer->meta['todoistcompanion_todos'] = true;
            unset($renderer->persistent['todoistcompanion_todos']);
            return true;
        }
        if ($format !== 'xhtml') return false;
        if (($data['type'] ?? '') === 'list') return $this->renderTaskList($renderer);
        global $ID;
        $key = (string)($data['key'] ?? '');
        $title = (string)($data['title'] ?? '');
        if ($key === '' || $title === '') {
            $renderer->doc .= '<span class="todoistcompanion-task-error">' . hsc($title ?: 'Invalid TodoistCompanion task syntax') . '</span>';
            return true;
        }

        $renderer->doc .= '<div class="todoistcompanion-task" data-todoistcompanion-task="' . hsc($key) . '" data-page="' . hsc($ID) . '">';
        $renderer->doc .= $this->taskToggleButton();
        $renderer->doc .= '<span class="todoistcompanion-task-heading"><span class="todoistcompanion-task-title">' . hsc($title) . '</span>';
        $renderer->doc .= $this->renderDueDateTime($data['due'] ?? '') . '</span>';
        $renderer->doc .= '<span class="todoistcompanion-task-status" aria-live="polite">' . (FokusButler_Modules::enabled('todoist') ? 'Loading task status…' : 'Todoist integration disabled.') . '</span></div>';
        return true;
    }

    private function renderTaskList(Doku_Renderer $renderer)
    {
        $renderer->info['cache'] = false;
        $action = plugin_load('action', 'fokusbutler_todoist');
        $renderer->doc .= '<section class="todoistcompanion-todos"><h3>' . hsc($this->getLang('todo_list_heading')) . '</h3>';
        global $ID;
        if ($action && $action->canReadImportedTasks($ID)) {
            // Todoist is the aggregate list's source of truth; load it after render.
            $renderer->doc .= '<ul class="todoistcompanion-incoming" data-page="' . hsc($ID) . '"><li class="todoistcompanion-todos-empty">Loading Todoist tasks…</li></ul>';
        } else {
            $renderer->doc .= '<ul class="todoistcompanion-incoming" hidden></ul>';
        }
        $renderer->doc .= '</section>';
        return true;
    }

    private function taskToggleButton()
    {
        if (!FokusButler_Modules::enabled('todoist')) return '<span class="todoistcompanion-task-icon" aria-label="Todoist integration disabled">' . file_get_contents(__DIR__ . '/icons/circle-duotone.svg') . '</span>';
        $uncheckedIcon = @file_get_contents(__DIR__ . '/icons/circle-duotone.svg');
        $checkedIcon = @file_get_contents(__DIR__ . '/icons/check-circle-duotone.svg');
        return '<button type="button" class="todoistcompanion-task-toggle" aria-checked="false" aria-busy="true" role="checkbox" disabled aria-label="Loading task status">'
            . '<span class="todoistcompanion-task-icon todoistcompanion-task-icon-unchecked" aria-hidden="true">' . $uncheckedIcon . '</span>'
            . '<span class="todoistcompanion-task-icon todoistcompanion-task-icon-checked" aria-hidden="true">' . $checkedIcon . '</span>'
            . '</button>';
    }

    private function renderTaskDate($value, $label, $timezone = null)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}.*)?$/', $value)) return '';
        try {
            $zone = new DateTimeZone($timezone ?: date_default_timezone_get());
            $date = (new DateTimeImmutable($value, $zone))->setTimezone($zone);
            $formatted = $date->format(strlen($value) === 10 ? 'y-m-d' : 'y-m-d H:i');
        } catch (Exception $error) {
            return '';
        }
        return '<time class="todoistcompanion-task-date" datetime="' . hsc($value) . '" title="' . hsc($label . ': ' . $value . ' (' . $zone->getName() . ')') . '">'
            . hsc($label . ' ' . $formatted) . '</time>';
    }

    private function renderDueDateTime($value)
    {
        return $this->renderTaskDate($value, 'Due');
    }
}
