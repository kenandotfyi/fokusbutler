(function () {
    'use strict';
document.addEventListener('click', (event) => {
    const clickedElement = event.target instanceof Element ? event.target : event.target?.parentElement;
    const foldToggle = clickedElement?.closest('[data-fokus-fold-toggle]');
    if (!foldToggle) return;
    const interactive = clickedElement.closest('a, button, input, select, textarea');
    if (interactive && interactive !== foldToggle) return;

    const fold = foldToggle.closest('[data-fokus-fold]');
    const foldContent = fold?.querySelector(':scope > .fokus-fold__content');
    if (!fold || !foldContent) return;

    const willOpen = foldToggle.getAttribute('aria-expanded') !== 'true';
    foldToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    fold.classList.toggle('is-open', willOpen);
    foldContent.hidden = !willOpen;

    // Keep other page and pane click handlers from toggling the fold twice.
    event.stopImmediatePropagation();
}, true);

})();
