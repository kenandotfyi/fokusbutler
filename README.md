# Fokus Butler

Fokus Butler is the modular companion plugin for the Fokus DokuWiki template. One installation contains Fokus settings, folding, Page Health, ImagePop, NoteGraph, Snips, and TodoistCompanion.

## Installation and administration

Install the `fokusbutler` folder under `lib/plugins/` and use the accompanying updated Fokus template. Open **Admin → Fokus Butler**, or the **Fokus Butler** entry in the sidebar's wiki actions menu.

The **Modules** panel controls optional behavior and shows separate **Adapters** and **Dependencies** sections. All feature modules start enabled. Fokus settings is always available. Every module has its own admin panel or information page under the same admin entry. Existing fold and task syntax remains readable when its integration is disabled; disabled folds expand their contents.

This prototype replaces the standalone fokussettings, fokusfold, pagehealth, imagepop, notegraph, snips, and todoistcompanion plugins. Do not run those originals alongside Butler: their hooks and syntax would run twice. Existing plugin configuration and stored state are not imported. Template appearance/workspace settings still belong to Fokus and retain their existing template configuration keys.

Tag, Move, MoaiEditor, Preview, Annotations, and KaTeX remain external plugins. Tag enables the tag browser and Snips tag picker. Move supplies renaming with link updates. MoaiEditor powers editing in the Fokus pane popup. The three pane adapters are controlled from the Fokus Butler Modules page.

## Modules and syntax

| Module | Features |
| --- | --- |
| `settings` | Template appearance, interwiki styles, and workspace |
| `folding` | `;;;; Title \| … ;;;;` closed folds and `++++ Title \| … ++++` open folds; formatted and heading titles |
| `pagehealth` | Empty-page classification, link styling, `~~PAGEHEALTH~~`, `~~LAST30~~` |
| `imagepop` | Image lightbox, gallery, zoom/pan, configurable image selectors |
| `notegraph` | Local graph from each page's banner, including additional panes |
| `preview` | Full wiki and Wikipedia hover previews, scrolling, and titlebar drag-to-pin |
| `snips` | Configurable insert/wrap snippets and existing-tag picker |
| `todoist` | `{{todoistcompanion>Task\|id=abcd1234}}`, due dates, completion, `~~TODOS~~` |
| `memos` | Jots capture, Memos v0.31 timeline, tag filters, and a private local note/attachment mirror. See [module setup](modules/memos/README.md). |

Fold delimiters belong on separate lines around block content. A toolbar shortcut can be added as a Snips wrap entry. Syntax strings remain the same; DokuWiki component names and AJAX endpoints belong to Fokus Butler.

## Structure

```text
fokusbutler/
  admin.php                 Single admin entry, delegates feature panels
  action/                   Thin DokuWiki action bridges
  syntax/                   Thin syntax bridges; no abstract base classes
  helper/                   Thin helper bridges
  bootstrap.php
  modules.php               Explicit module registry
  core/                     Module/config context, asset loader, admin shell
  modules/
    settings/
    content/
    folding/
    pagehealth/
    imagepop/
    notegraph/
    preview/
    snips/
    todoist/
```

Each module owns its PHP implementation, `script.js`, `style.css`, icons, translations, and defaults where applicable. Assets remain separate and load through the shared loader. There is no combined Butler stylesheet or feature script. The small `core/editor.js` only refreshes Butler toolbar definitions from the current page, so browser-cached DokuWiki toolbar definitions cannot hide newly saved snippets or retain disabled modules.

CSS uses existing feature prefixes (`fokus-fold`, `notegraph`, `imagepop`, `snips`, `pagehealth`, `todoistcompanion`) and shared Fokus appearance tokens. JavaScript internals use private scopes; only explicitly named integration APIs are exported. Existing pane-aware observers and delegated event handlers remain module-owned. Modules must not modify another module's DOM or style unqualified page elements.

## Configuration and storage

Optional switches use `$conf['plugin']['fokusbutler']['enabled_<module>']`. Module settings use prefixed keys, for example `imagepop_image_selector`, `pagehealth_empty_color`, and `todoist_project_name`. Edit them through the Butler admin panels or DokuWiki Configuration Manager.

Module defaults remain in `modules/<module>/conf/default.php`. After editing those defaults or the manifest, run:

```sh
php bin/build-config.php
```

This writes literal assignments to `conf/default.php`, which DokuWiki's Configuration Manager requires. It is a small configuration index, not consolidated feature code. Metadata and setting translations are collected from their module-local files.

Runtime state lives outside the installed plugin folder:

- Snippets: `conf/plugin/fokusbutler/snips/snippets.json`.
- Page Health index/scan: `<cachedir>/fokusbutler/pagehealth/`.
- Todoist registry/project state: `<metadir>/fokusbutler/todoist/`.
- Template pins and recents remain template-owned.

Todoist credentials retain the existing precedence: `/run/secrets/todoist_api_token`, then `TODOIST_API_TOKEN`, then Butler's `todoist_api_token` configuration. The admin page never prints a configured token. Production Docker secret/environment wiring does not change. Set any local configuration token again in the Todoist module; no legacy plugin configuration is read.

The optional standalone **Zotero Bridge** plugin appears in Dependencies, with a **Zotero** navigation link when installed and enabled. Its administration, credentials, synchronization, and index remain plugin-owned. Configure `ZOTERO_API_KEY` in the deployment environment or save the local fallback at **Admin → Zotero Bridge**.

## Development and verification

PHP module implementations are not placed inside DokuWiki's component discovery folders. Only the thin bridges belong there. Feature actions check their module switch before registering hooks. Keep read-only syntax fallbacks when disabling side effects.

Run integration checks against an isolated DokuWiki installation with this plugin and Fokus installed:

```sh
DOKUWIKI_TEST_ROOT=/path/to/isolated/dokuwiki php tests/integration.php
node tests/editor.cjs
php tests/todoist.php
php tests/preview.php
```

The integration fixture should provide a `start` page, `md+dw` syntax, the Fokus template, and an `authplain` admin group. The suite checks real component discovery, parsing, admin configuration keys, module fallbacks, asset loading, and toolbar exports. Use an isolated wiki: the tests may initialize DokuWiki metadata and configuration directories. They never call Todoist's API.

Scripts and stylesheets use plain URLs without version parameters. After developing asset changes, use a normal browser cache refresh as needed. Switching plugin components requires clearing affected DokuWiki instruction and rendered-output caches once; do not delete unrelated application state stored under the cache directory.

Phosphor duotone assets are covered by `PHOSPHOR-LICENSE.txt`; module-specific licenses are retained beside their modules.

The Butler admin page uses a grouped module sidebar with the selected settings panel on the right. The active section remains in the URL, so browser Back and direct links continue to work. Narrow windows place the navigation above the selected panel. Content providers is an always-available shared service; Page previews controls full-page hover windows independently.
