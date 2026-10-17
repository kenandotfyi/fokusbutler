# TodoistCompanion module

Action.php owns page-save synchronization, completion/status AJAX, and the local task registry. Syntax.php renders `{{todoistcompanion>Task|id=abcd1234}}` and `~~TODOS~~`. Admin.php owns connection configuration and the all-task list. script.js and style.css own the task UI and toolbar dialog.

Credentials resolve from `/run/secrets/todoist_api_token`, then `TODOIST_API_TOKEN`, then `todoist_api_token` under Butler configuration. Runtime state uses `<metadir>/fokusbutler/todoist`. AJAX endpoints use the `fokusbutler_todoist_` prefix. Disabling the module stops synchronization and leaves task text readable.

This is an internal Fokus Butler module, not a separately installable DokuWiki plugin. See [the companion README](../../README.md) for installation, module switches, asset loading, and development checks.
