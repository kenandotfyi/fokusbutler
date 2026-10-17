<?php
/** Explicit module manifest. Private implementation files are never plugin components. */
return [
    'settings' => ['name' => 'Fokus settings', 'description' => 'Appearance, workspace, adapters, and dependency checks.', 'toggle' => false],
    'folding' => ['name' => 'Folding', 'description' => 'Pane-safe folding sections with formatted titles.', 'toggle' => true],
    'pagehealth' => ['name' => 'Page Health', 'description' => 'Empty-page classification, link styling, health summaries, and recent changes.', 'toggle' => true],
    'imagepop' => ['name' => 'ImagePop', 'description' => 'Zoomable image galleries with configurable image selectors.', 'toggle' => true],
    'notegraph' => ['name' => 'NoteGraph', 'description' => 'Explore incoming and outgoing page links in a local graph.', 'toggle' => true],
    'snips' => ['name' => 'Snips', 'description' => 'Editor snippets and a searchable picker for existing wiki tags.', 'toggle' => true],
    'todoist' => ['name' => 'TodoistCompanion', 'description' => 'Page-linked tasks, due dates, and optional Todoist synchronization.', 'toggle' => true],
];
