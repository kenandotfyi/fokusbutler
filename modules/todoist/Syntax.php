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
        $tasks = $action && method_exists($action, 'getAllWikiTasks') ? $action->getAllWikiTasks() : [];
        $renderer->doc .= '<section class="todoistcompanion-todos"><h3>' . hsc($this->getLang('todo_list_heading')) . '</h3><ul>';
        if (!$tasks) {
            $renderer->doc .= '<li class="todoistcompanion-todos-empty">' . hsc($this->getLang('no_tasks')) . '</li>';
        } else {
            foreach ($tasks as $task) {
                $key = (string)$task['key'];
                $page = (string)$task['page'];
                $renderer->doc .= '<li class="todoistcompanion-todos-item"><div class="todoistcompanion-task" data-todoistcompanion-task="' . hsc($key) . '" data-page="' . hsc($page) . '">';
                $renderer->doc .= $this->taskToggleButton();
                $renderer->doc .= '<span class="todoistcompanion-task-heading"><span class="todoistcompanion-task-title">' . hsc($task['title']) . '</span>';
                $renderer->doc .= '<a class="todoistcompanion-task-page" href="' . hsc(wl($page)) . '">' . hsc($page) . '</a></span>';
                $renderer->doc .= '<span class="todoistcompanion-task-status" aria-live="polite">' . (FokusButler_Modules::enabled('todoist') ? 'Loading task status…' : 'Todoist integration disabled.') . '</span></div></li>';
            }
        }
        $renderer->doc .= '</ul>';
        $this->renderImportedTasks($renderer, $action);
        $renderer->doc .= '</section>';
        return true;
    }

    private function renderImportedTasks(Doku_Renderer $renderer, $action)
    {
        global $ID;
        if (!$action || !$action->canReadImportedTasks($ID)) return;
        // Personal project contents must never enter a shared parser cache.
        $renderer->info['cache'] = false;
        $snapshot = $action->getProjectTasks($ID);
        if ($snapshot['error'] !== '') {
            $renderer->doc .= '<p class="todoistcompanion-task-error" role="status">' . hsc($snapshot['error']) . ' The list may be out of date.</p>';
        }
        $renderer->doc .= '<ul class="todoistcompanion-incoming">';
        foreach ($snapshot['tasks'] as $key => $task) {
            $detailsId = 'todoist-details-' . substr(hash('sha256', (string)$task['id']), 0, 16);
            $renderer->doc .= '<li class="todoistcompanion-todos-item todoistcompanion-incoming-item"><div class="todoistcompanion-task" data-todoistcompanion-task="' . hsc($key) . '" data-page="' . hsc($ID) . '">';
            $renderer->doc .= $this->taskToggleButton();
            $renderer->doc .= '<span class="todoistcompanion-task-heading"><button type="button" class="todoistcompanion-expand" aria-expanded="false" aria-controls="' . hsc($detailsId) . '">' . hsc((string)($task['content'] ?? '')) . '</button></span>';
            $renderer->doc .= '<a class="todoistcompanion-origin" href="https://app.todoist.com/app/task/' . rawurlencode((string)$task['id']) . '" target="_blank" rel="noopener noreferrer" title="Added in Todoist — open task" aria-label="Added in Todoist — open task">' . file_get_contents(__DIR__ . '/icons/todoist.svg') . '</a>';
            $renderer->doc .= '<span class="todoistcompanion-task-status" aria-live="polite">Loading task status…</span></div>';
            $renderer->doc .= '<div class="todoistcompanion-task-details" id="' . hsc($detailsId) . '" hidden>';
            $dates = $this->renderTaskDate($task['due']['datetime'] ?? $task['due']['date'] ?? '', 'Due', $task['due']['timezone'] ?? null)
                . $this->renderTaskDate($task['added_at'] ?? '', 'Added');
            if ($dates !== '') $renderer->doc .= '<p class="todoistcompanion-task-metadata">' . $dates . '</p>';
            if (!empty($task['description'])) {
                $renderer->doc .= '<p class="todoistcompanion-task-description">' . hsc((string)$task['description']) . '</p>';
            }
            $metadata = [];
            if (!empty($task['due']['is_recurring'])) $metadata[] = 'Repeats: ' . ($task['due']['string'] ?? 'Recurring');
            if (!empty($task['deadline']['date'])) $metadata[] = 'Deadline: ' . $task['deadline']['date'];
            foreach ($task['labels'] ?? [] as $label) $metadata[] = '@' . $label;
            if ($metadata) $renderer->doc .= '<p class="todoistcompanion-task-metadata">' . hsc(implode(' · ', $metadata)) . '</p>';
            $renderer->doc .= '</div></li>';
        }
        $renderer->doc .= '</ul>';
    }

    private function renderTaskDate($value, $label, $timezone = null)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}.*)?$/', $value)) return '';
        try {
            $zone = new DateTimeZone($timezone ?: date_default_timezone_get());
            $date = new DateTimeImmutable($value, $zone);
            // Convert explicit UTC/offset timestamps to the configured display timezone.
            $date = $date->setTimezone($zone);
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
}
