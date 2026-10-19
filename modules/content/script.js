(function () {
    'use strict';
    const cache = new Map();
    const requests = new Map();
    let sequence = 0;
    async function contentFor(target, refresh) {
        const existing = cache.get(target.key);
        if (!refresh && existing && Date.now() - existing.time < 30000) return existing.data;
        if (requests.has(target.key)) return requests.get(target.key);
        const params = new URLSearchParams({ provider: target.provider });
        if (target.provider === 'wiki') params.set('id', target.id);
        else params.set('url', target.url);
        const request = (async () => {
            const response = await fetch(window.FokusButlerContentConfig.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(15000) });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'The preview could not be loaded.');
            cache.set(target.key, { data: result, time: Date.now() });
            if (cache.size > 32) cache.delete(cache.keys().next().value);
            return result;
        })();
        requests.set(target.key, request);
        try { return await request; } finally { requests.delete(target.key); }
    }

    function layoutWikipedia(body) {
        const lead = body.querySelector('section') || body;
        const images = Array.from(lead.children).filter(element => element.matches('figure.mw-halign-right'));
        const boxes = Array.from(body.querySelectorAll('.sidebar, .navbox')).filter(element => !element.parentElement.closest('.sidebar, .navbox'));
        if (!images.length && !boxes.length) return;
        const rail = document.createElement('aside');
        rail.className = 'fokus-wikipedia-rail';
        rail.setAttribute('aria-label', 'Article images and related topics');
        images.forEach(image => rail.appendChild(image));
        boxes.forEach(box => {
            const heading = box.querySelector('.sidebar-title-with-pretitle, .sidebar-title, .navbox-title');
            const label = heading?.cloneNode(true);
            label?.querySelectorAll('.navbar, .noprint, .mw-editsection').forEach(element => element.remove());
            const details = document.createElement('details');
            details.className = 'fokus-wikipedia-related';
            const summary = document.createElement('summary');
            summary.textContent = 'Related topics · ' + (label?.textContent.trim().replace(/\s+/g, ' ') || 'Navigation');
            const content = document.createElement('div');
            content.className = 'fokus-wikipedia-related-content';
            details.append(summary, content);
            content.appendChild(box);
            rail.appendChild(details);
        });
        const description = Array.from(lead.children).find(element => element.classList.contains('shortdescription'));
        if (description) description.after(rail);
        else lead.prepend(rail);
    }

    function renderContent(body, result, provider, scope = "fokus-content-" + (++sequence)) {
        body.classList.toggle("fokus-wikipedia-content", provider === "wikipedia");
        body.innerHTML = result.html;
        if (provider === 'wikipedia') layoutWikipedia(body);
        const ids = new Map();
        body.querySelectorAll('[id]').forEach(element => {
            const old = element.id;
            element.dataset.originalId = old;
            element.id = scope + '-' + old;
            ids.set(old, element.id);
        });
        const article = new URL(result.url, location.href);
        body.querySelectorAll('a[href]').forEach(link => {
            const href = link.getAttribute('href');
            const url = new URL(href, article);
            if (!href.startsWith('#') && (provider !== 'wikipedia'
                || url.origin !== article.origin || decodeURIComponent(url.pathname) !== decodeURIComponent(article.pathname))) return;
            let fragment = url.hash.slice(1);
            try { fragment = decodeURIComponent(fragment); } catch (_) { /* Keep literal fragments. */ }
            if (ids.has(fragment)) {
                link.setAttribute('href', '#' + ids.get(fragment));
                link.removeAttribute('target');
            }
        });
        if (result.attribution) {
            const attribution = document.createElement('a');
            attribution.className = 'fokus-content-attribution';
            attribution.href = result.url; attribution.target = '_blank'; attribution.rel = 'noopener noreferrer';
            attribution.textContent = result.attribution;
            attribution.dataset.paneOriginal = 'true';
            body.appendChild(attribution);
        }
        if (typeof renderMathInElement === 'function') renderMathInElement(body, {
            delimiters: [
                { left: '$$', right: '$$', display: true },
                { left: '$', right: '$', display: false },
                { left: '\\(', right: '\\)', display: false },
                { left: '\\[', right: '\\]', display: true }
            ],
            throwOnError: false
        });
    }


    // References belong to article content, including panes without page hovers.
    let pendingReference = null;
    let reference = null;
    function dismissReference() {
        clearTimeout(pendingReference?.timer);
        pendingReference = null;
        if (!reference) return;
        reference.element.remove();
        if (reference.previousDescription) reference.source.setAttribute('aria-describedby', reference.previousDescription);
        else reference.source.removeAttribute('aria-describedby');
        reference = null;
    }
    function citationFor(target) {
        const link = target.closest?.('.fokus-wikipedia-content .mw-ref a[href], .fokus-wikipedia-content sup.reference a[href]');
        if (!link) return null;
        const content = link.closest('.fokus-wikipedia-content');
        let id;
        try { id = decodeURIComponent(new URL(link.href).hash.slice(1)); } catch (_) { return null; }
        const note = document.getElementById(id);
        return note && content.contains(note) ? { source: link, note: note.querySelector('.reference-text, .mw-reference-text') || note } : null;
    }
    function showReference(citation) {
        if (!citation.source.isConnected) return;
        const element = document.createElement('div');
        element.id = 'fokus-reference-' + (++sequence);
        element.className = 'dokuwiki fokus-reference-tooltip';
        element.dataset.provider = 'wikipedia';
        element.setAttribute('role', 'tooltip');
        const body = document.createElement('div'); body.className = 'fokus-content-body';
        body.innerHTML = citation.note.innerHTML;
        body.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
        body.querySelectorAll('.mw-cite-backlink').forEach(node => node.remove());
        element.appendChild(body); document.body.appendChild(element);
        reference = { ...citation, element, previousDescription: citation.source.getAttribute('aria-describedby') };
        citation.source.setAttribute('aria-describedby', element.id);
        const rect = citation.source.getBoundingClientRect();
        element.style.left = Math.max(12, Math.min(rect.left, innerWidth - element.offsetWidth - 12)) + 'px';
        const top = rect.bottom + 8;
        element.style.top = Math.max(12, top + element.offsetHeight > innerHeight - 12 ? rect.top - element.offsetHeight - 8 : top) + 'px';
    }
    document.addEventListener('pointerover', event => {
        if (event.pointerType === 'touch') return;
        const citation = citationFor(event.target);
        if (!citation || citation.source.contains(event.relatedTarget)) return;
        dismissReference();
        pendingReference = { source: citation.source, timer: setTimeout(() => {
            pendingReference = null; showReference(citation);
        }, window.FokusButlerPreviewConfig?.openDelay ?? 750) };
    });
    document.addEventListener('pointerout', event => {
        const source = reference?.source || pendingReference?.source;
        if (source?.contains(event.target) && !source.contains(event.relatedTarget)) dismissReference();
    });
    document.addEventListener('click', event => {
        if (!event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && citationFor(event.target)) event.preventDefault();
    }, true);
    document.addEventListener('scroll', dismissReference, true);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') dismissReference(); });
    new MutationObserver(() => {
        if ((reference && !reference.source.isConnected) || (pendingReference && !pendingReference.source.isConnected)) dismissReference();
    }).observe(document.body, { childList: true, subtree: true });

    window.FokusButlerContent = {
        load: contentFor, render: renderContent,
        wikipedia(url) {
            const parsed = new URL(url);
            return contentFor({ provider: 'wikipedia', url, key: 'wikipedia:' + parsed.hostname + ':' + decodeURIComponent(parsed.pathname.slice(6)) }, false);
        }
    };
})();
