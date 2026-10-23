# Jots / Memos module

Quick capture and a locally mirrored Memos timeline inside Fokus. Targets Memos **v0.31.0** (`/api/v1`); this is part of Fokus Butler, not a separate plugin.

## Configure

Enable **Jots** in Butler's module overview, then open **Fokus Butler → Jots**:

- Instance URL: e.g. `https://jots.akinci.fyi`, without `/api/v1`.
- Create a dedicated personal access token in Memos and enter it in the masked token field. Leaving it blank preserves the saved token.
- Deployment precedence: `/run/secrets/memos_api_token` → `MEMOS_API_TOKEN` → saved Butler configuration. `MEMOS_URL` overrides the saved URL. The development Compose file passes both environment variables through.
- Optionally set a wiki username. Only that logged-in user may access this account's notes, attachments and writes. A blank username restricts access to wiki administrators. Anonymous readers never receive Jots configuration or data.
- The default shortcut is **Alt+Shift+J**, configurable with Ctrl, Meta, Alt and Shift modifiers.

The pen-nib capture button opens the jotter. **Browse jots** is also available directly in the sidebar navigation, and Jots uses a pen-nib shelf icon. The Jots pane uses pane ID `jots.inbox` and normal pane replacement, duplication, zoom, shelf and URL restoration behavior. The pane includes text search, tag counts/filters and All/Active/Archived filters. New notes are private. Edits patch only content, preserving attachments and visibility. **Attach page** inserts a visible Markdown link immediately and remembers the last wiki pane you interacted with. Markdown stays Markdown; no DokuWiki syntax conversion is performed. Cached image attachments use ImagePop when the module is enabled.

## Persistence and synchronization

Runtime data lives under `<metadir>/fokusbutler/memos/<SHA-256(instance URL + token)>/<SHA-256(wiki user)>/`:

- `snapshot.json`: published notes and attachment metadata.
- `files/`: private attachment bytes, addressed by attachment name and metadata fingerprint. Files are served only through the authenticated, owner-checked module endpoint. Active types such as HTML/SVG download as attachments.
- `stage.json`: resumable active/archived pagination and attachment work. One API page or new attachment is processed per request. Existing matching attachments are reused.
- `outbox.json`: durable pending writes. Every create has a stable client-generated memo ID; an uncertain response can be retried without creating another note. Completed operation IDs are retained in `completed.json` for duplicate-submit recovery.
- `status.json`: the last synchronization error. Interrupted page scans retain the prior mirror. A failed attachment remains listed with a retry notice; note text still becomes available.

The pane renders cached notes first, then refreshes in the background. It checks again every two minutes while visible and when the tab becomes visible. A snapshot younger than one minute needs no new scan; **Refresh** forces one. There is no background scheduler or webhook endpoint. A full successful scan reconciles active and archived notes, removals, and attachment changes. Orphaned cached files are removed only after publication.

The default attachment limit is 100 MiB per file and can be increased in settings. PHP cURL is required for streaming attachment downloads. Public external storage and HTTP redirects are supported; the Memos token is sent only to the configured instance origin, public storage addresses are validated/pinned, and HTTPS redirects cannot downgrade to HTTP. Remote inline images without a corresponding Memos attachment are not loaded automatically.

Unsent composer drafts are recovered from browser storage, scoped by wiki instance, user and Memos URL. After the backend accepts a save, it is retained in the server outbox until Memos confirms it. Failed saves appear in the Jots pane with retry/copy controls. Conflicting edits retain the local text and require review of the latest remote version. Revision checking compares Memos `updateTime` before PATCH; the Memos API does not provide an atomic conditional update here, so simultaneous writes during the check/PATCH interval cannot be fully excluded.

**Turn into wiki page** opens a dialog with an editable page ID and Markdown content. It creates a new page through core DokuWiki saving, appends a source link, and optionally copies cached attachments through the normal media upload API. Existing pages and different media files are never overwritten. Copied files live in `<destination page ID>:<filename>` and follow that namespace’s normal media permissions. Attachment references are rewritten to absolute DokuWiki media references; unattached-in-text resources are appended as images or download links. Exported files are independent of the Memos mirror, and the original memo is never deleted or changed. Pending saves must be resolved before promotion.

Uploading new attachments from the jotter and unified-search indexing are not included.

## Dependencies and checks

Bundled, unmodified browser packages: **Marked 18.1.1** (MIT), **DOMPurify 3.4.17** (Apache-2.0 OR MPL-2.0). Licenses are alongside the vendor files. Rendering uses an allowlist, disables embedded tasks, and replaces managed attachment URLs with authenticated local URLs before attaching the content to the page. Pen-nib is the official Phosphor duotone SVG under the existing MIT icon license.

Official API contracts: [memo service](https://github.com/usememos/memos/blob/v0.31.0/proto/api/v1/memo_service.proto), [attachment service](https://github.com/usememos/memos/blob/v0.31.0/proto/api/v1/attachment_service.proto), [attachment URLs](https://github.com/usememos/memos/blob/v0.31.0/web/src/utils/attachment.ts).

Run offline checks from the plugin root:

```sh
php tests/memos.php
php tests/memos-access.php
php tests/memos-promote.php
```

An isolated browser fixture is available with no real account or wiki data:

```sh
MEMOS_UI_DIR=/tmp/fokus-memos-ui php -S 127.0.0.1:8189 tests/memos-ui-router.php
```

Open `http://127.0.0.1:8189/`. The fixture uses the real Store and browser UI with simulated remote notes and an attachment. Its PHP router refuses requests outside PHP's development server.
