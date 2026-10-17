# Folding module

SyntaxBase.php owns block behavior, formatted titles, and rendering. Closed.php and Open.php define the delimiters. script.js delegates toggle clicks and style.css controls the module UI.

Use `;;;; Title |` and a closing `;;;;` on another line for closed folds, or `++++` delimiters for open folds. Titles accept wiki formatting and headings. Reloading restores the syntax-defined default. Disabling the module expands all fold content. Add wrap shortcuts through Snips if desired.

This is an internal Fokus Butler module, not a separately installable DokuWiki plugin. See [the companion README](../../README.md) for installation, module switches, asset loading, and development checks.
