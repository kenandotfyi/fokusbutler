# Snips module

Admin.php manages insert and wrap snippets. Helper.php normalizes entries and resolves icons. Action.php defines the toolbar picker and live tag endpoint; script.js and style.css own the picker.

Data lives in `conf/plugin/fokusbutler/snips/snippets.json`. Wrap snippets use selected text, or the snippet label as placeholder when no text is selected. The tag endpoint is `fokusbutler_snips_tags`. The exported toolbar function is `addBtnActionFokusbutlersnips`.

For a fold shortcut use opening text `;;;; Title |` followed by a newline and closing text consisting of a newline followed by `;;;;`. A duotone caret icon is available at `lib/plugins/fokusbutler/modules/folding/icons/caret-down-duotone.svg`.

This is an internal Fokus Butler module, not a separately installable DokuWiki plugin. See [the companion README](../../README.md) for installation, module switches, asset loading, and development checks.
