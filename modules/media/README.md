# Media Library

Enabled by default in **Admin → Fokus Butler → Modules**. The editor’s image button opens Media Library in a dialog. You can also open it from **Wiki actions → Media Library** or **Admin → Fokus Butler → Media Library**, without editing a page. Disable this module to restore DokuWiki’s standard media popup after reloading the editor.

- Browse media namespaces and select a file from the thumbnail grid. Each page contains up to 80 files.
- Search filenames and media IDs in the current namespace and its descendants. Searching from root covers all readable media.
- Choose **Upload files** or drop multiple files into the center panel. Uploads run sequentially with progress, a size limit, and per-file errors. Uploads target the selected namespace. Ordinary uploads do not overwrite existing files. Use **Upload new version** on a selected file to replace its contents explicitly.
- Preview images, inspect file size/dimensions, or open the original file. **Used on these pages** lists links to readable pages from DokuWiki’s reference index. New references appear after DokuWiki updates its index. Reference links open through Fokus’s pane navigation, focus an already-open page, and keep browser modifier clicks available. Editor iframes use their parent Fokus workspace when present.
- Drag the namespace divider to change its width (or use Left/Right, Home/End while focused). The width is remembered in your browser and scales with the dialog. Drag the dialog’s bottom-right corner to resize it on desktop.
- Create an empty media namespace with **New namespace**.
- Drag a file onto another namespace, or choose **Move / rename** and enter a full destination media ID. Confirm the proposed move. This uses the installed **Move** plugin to retain revisions and update referencing pages. It requires delete permission at the source and upload permission at the destination. Moves are blocked while any indexed referencing page is being edited.
- Choose **Delete file** to review and confirm deletion. DokuWiki’s configured reference protection applies, including references from private pages. **Undo deletion** remains available in the library after a successful deletion when media revisions are enabled.
- Use **Upload new version** to replace a selected file while retaining its ID. The extension must match. With media revisions enabled, DokuWiki retains the previous version; otherwise replacement requires delete permission and is permanent.
- Expand **Version history** to view, restore, or compare previous image versions with the current image. Older history loads in batches. Restoring retains the version it replaces.
- Expand **Image metadata** to edit DokuWiki’s configured JPEG fields, such as title, caption, photographer, copyright, and keywords. Supported fields and permissions follow the native manager.
- Set caption/link text, image width, alignment, and image-link behavior, then choose **Insert media**. Non-image files use a text link. Caption means DokuWiki’s media title/alternate text, rather than a separate visible figure caption.

Insertion uses absolute media IDs, preserves the opening selection, and supports the native textarea, MoaiEditor’s textarea, and CodeMirror. If the document changes while the dialog is open, reopen the library before inserting. Closing the dialog returns focus to its opener; inserting returns focus to the editor.

DokuWiki owns media storage, namespace ACLs, permitted extensions, content checks, upload hooks, and changelog entries. Read-only users can browse readable files; uploads require upload permission and DokuWiki’s session token. The token comes from the active editor form or the current page’s standalone library configuration and is never included in the combined JavaScript cache. File listing does not follow filesystem symlinks. No core or third-party plugin files are changed.

Outside the editor, the library provides the same file management and reference features without insertion controls. **Manage file** remains as a fallback into the selected file in DokuWiki’s standard manager. File actions use POST, a session token, fresh content fingerprints, namespace ACLs, and a shared operation lock. Changes to core or third-party plugin code are not required. No batch file selection or whole-namespace moves are provided.

Verification:

```sh
node tests/media.cjs
node tests/editor.cjs
DOKUWIKI_TEST_ROOT=/path/to/isolated/dokuwiki php tests/media.php
DOKUWIKI_TEST_ROOT=/path/to/isolated/dokuwiki php tests/media-management.php
```

The PHP suite uses a temporary media directory inside a disposable DokuWiki fixture. It checks real ACL behavior, search isolation, pagination, symlink exclusion, upload permissions, HTTP method enforcement, and CSRF rejection.

The management suite requires the Move plugin and PHP GD in the disposable fixture. It exercises real deletion/undo, replacement guards, revision restoration and pagination, JPEG metadata, namespace creation, move collisions, locked referencing pages, link rewriting, and revision preservation. Browser checks cover actual replacement uploads and drag-and-drop.
