# Page Health module

Action.php owns classification, ACL-filtered AJAX, incremental page-save updates, and scan data. Syntax.php renders `~~PAGEHEALTH~~` and `~~LAST30~~`. Admin.php owns scans and link styling. Tag links with rel="tag" are excluded from unhealthy-link treatment.

Defaults and metadata live in conf/. Runtime state uses `<cachedir>/fokusbutler/pagehealth`. AJAX endpoints are `fokusbutler_pagehealth`, `fokusbutler_pagehealth_stats`, and `fokusbutler_pagehealth_scan`.

This is an internal Fokus Butler module, not a separately installable DokuWiki plugin. See [the companion README](../../README.md) for installation, module switches, asset loading, and development checks.
