(function () {
    'use strict';
    const config = window.FokusButlerEditorConfig;
    if (!config || !Array.isArray(window.toolbar)) return;

    // Keep DokuWiki and third-party toolbar entries, replacing only Butler's.
    // Module handlers are loaded separately before this integration runs.
    const types = new Set(['fokusbutlersnips', 'fokusbutlertodoist', 'fokusbutlermedia', 'fokusbutlerequations']);
    const mediaEnabled = (config.toolbar || []).some(entry => entry.type === 'fokusbutlermedia');
    const current = window.toolbar.filter(entry => !types.has(entry.type) && !(mediaEnabled && entry.type === 'mediapopup'));
    const definitions = Array.isArray(config.toolbar) ? config.toolbar : [];
    window.toolbar.splice(0, window.toolbar.length, ...current, ...definitions);
})();
