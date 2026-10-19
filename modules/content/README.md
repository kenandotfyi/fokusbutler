# Content providers

Always-available content service shared by Fokus panes and optional Page previews. It owns the `fokusbutler_content` AJAX endpoint, validates Wikipedia language editions and article redirects, checks wiki ACLs before rendering, sanitizes HTML, and caches successful Wikipedia articles for one hour. Existing caches remain under `<cachedir>/fokusbutler/preview/` for compatibility.

`FokusButlerContent.load(target, refresh)` loads wiki or Wikipedia content. `wikipedia(url)` loads an article directly. `render(body, result, provider)` applies shared presentation, unique DOM IDs and fragment links, images, collapsed series/navigation boxes, hatnotes, attribution, and math rendering. Article reference tooltips are hover-only, open after 750 ms, and dismiss immediately on leaving the citation.

This service has no dependency on the preview module or Fokus template. Page previews consumes it for hover windows; the Fokus pane provider consumes it directly for article panes. Disabling Page previews leaves article panes and reference tooltips working. Wikipedia article images resolve to their original files and scale down to fit their article layout; gallery thumbnails retain their thumbnail source. Figure captions use a compact muted callout style in both previews and panes. Future content providers can share this service without adding dependencies on hover UI.

Run endpoint, ACL, URL validation, caching, and module-independence checks with `php tests/preview.php` from the plugin root.
