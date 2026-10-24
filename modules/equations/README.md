# Equations

Use **Build equation** in the page editor toolbar. Choose structures and fill their slots, or open **LaTeX source**. Choose inline or display placement and insert into the page. Put the cursor inside an existing `$…$`, `$$…$$`, `\(…\)` or `\[…\]` equation to reopen it. Selected bare LaTeX can also be imported.

The module preserves the saved selection, checks the editor has not changed, and supports the visible DokuWiki textarea and CodeMirror. Page rendering requires the external KaTeX plugin with the standard dollar delimiters enabled. MathLive is loaded only on first use, from locally bundled assets. No external service or equation storage is used.

MathLive 0.109.0 is bundled unchanged in `mathlive/` (MIT; see `mathlive/LICENSE.txt`). Fonts retain their bundled license. Toolbar icon: Phosphor duotone, covered by Butler's Phosphor license.

Fokus popup editing runs in an iframe. The loader initializes MathLive’s virtual keyboard in that browsing context before placing it inside the dialog; it must not assign a container to MathLive’s parent-window keyboard proxy.

The virtual keyboard toggle is hidden, and requests to open the on-screen keyboard are canceled. Physical keyboard input and the structure palette remain available.

Use **Find syntax** above the palette to search all categories by name or LaTeX command (with or without the backslash). Clear the search to return to the selected category; Escape clears a nonempty search.
