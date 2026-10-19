# ImagePop module

Action.php supplies configured image selectors. script.js owns the gallery/lightbox and style.css its presentation. Admin.php edits selectors. Existing delegated listeners support images in additional Fokus panes and Wikipedia article images rendered in previews or panes. Wikipedia gallery thumbnails remain small, while the main viewer resolves each file page or upload thumbnail to the original image. Navigation box images and LaTeX/MathML formula fallbacks are excluded.

Configure `imagepop_image_selector` through Admin → Fokus Butler → ImagePop. JavaScript exports only `window.FokusButlerImagePop`.

This is an internal Fokus Butler module, not a separately installable DokuWiki plugin. See [the companion README](../../README.md) for installation, module switches, asset loading, and development checks.
