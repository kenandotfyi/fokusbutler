# NoteGraph module

Action.php builds an ACL-filtered graph using outgoing and incoming links, capped at depth 3 and 60 nodes. script.js owns graph interaction and pane triggers; style.css is module-scoped.

The endpoint is `fokusbutler_notegraph`. Runtime configuration uses `window.FokusButlerNoteGraphConfig`; the explicit public API is `window.FokusButlerNoteGraph`. Page activation uses Fokus panes where available.

This is an internal Fokus Butler module, not a separately installable DokuWiki plugin. See [the companion README](../../README.md) for installation, module switches, asset loading, and development checks.
