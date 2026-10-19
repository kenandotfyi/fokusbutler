# Page previews

Hover an existing wiki link or Wikipedia interwiki link to open a full, scrollable page preview. The opening delay defaults to 750 ms. Reference tooltips use the configured opening delay when page previews are enabled and otherwise default to 750 ms. Links inserted later by Todoist, Fokus panes, and other previews use the same delegated behavior. Keyboard focus also opens page previews.

After leaving a link or window, a configurable grace period (300 ms by default) lets the pointer enter the preview. Leaving both dismisses an unpinned window. Drag its titlebar to pin it; the pin indicator appears and only its trash button closes it. Multiple pinned windows can stay open during pane navigation. Escape dismisses unpinned previews. Windows remain within the viewport; interactive resizing and persistence across reloads are intentionally deferred.

The Page previews admin panel saves preferred window width (default 560 px, range 320–1600), height (480 px, range 200–1200), opening delay (750 ms, range 0–5000), and closing grace period (300 ms, range 0–3000). Configuration Manager exposes the same `preview_width`, `preview_height`, `preview_open_delay`, and `preview_close_delay` settings. Values are validated on the server; protected settings remain read-only. Open wiki tabs must reload after saving. Preferred dimensions shrink to fit smaller screens. Reference tooltips keep their compact size and immediate dismissal.

Article fetching, sanitization, caching, presentation, and reference tooltips belong to the shared **Content providers** service (`modules/content`). This module consumes `FokusButlerContent.load` and `FokusButlerContent.render` and only manages full-page hover windows.

While enabled, this module excludes the standalone Preview plugin's script through `JS_SCRIPT_LIST`; no vendor files or plugin settings are changed. It handles main pages, Fokus panes, and dynamic Todoist links directly. The former Fokus Preview adapter and Todoist-specific hover fallback have been removed. Turning off **Page previews** restores the standalone plugin's original behavior on reload, without the former pane adapter.

Wikipedia previews use muted short descriptions, right-aligned article images, and collapsed series/navigation boxes beneath the images. Article links have subtle underlines. Hovering a citation number shows a compact tooltip using reference text already in the article; it does not fetch another article. Reference tooltips show only the footnote content, with no titlebar or pinning. They disappear immediately when the pointer leaves the citation or its article scrolls; clicking or keyboard focus does not open one.

Fokus panes use the content service directly. Disabling **Page previews** leaves Wikipedia panes, article styling, and reference tooltips available.

Run focused provider, sanitization, and ACL checks with `php tests/preview.php` from the plugin root. Wikipedia article HTML uses the [MediaWiki REST API](https://www.mediawiki.org/wiki/API:REST_API/Reference#Get_page).
