(function () {
    'use strict';
    const windows = new Set();
    let sequence = 0;
    let layer = 15000;
    let pending = null;
    let config;

    function targetFor(link) {
        if (!link || link.closest('[data-preview-action], [data-pane-original], .fokus-preview-title')) return null;
        if (link.closest('.mw-ref, sup.reference')) return null;
        if (link.getAttribute('href')?.startsWith('#')) return null;
        let url;
        try { url = new URL(link.href, location.href); } catch (_) { return null; }
        if (/^[a-z][a-z0-9-]{0,30}(?:\.m)?\.wikipedia\.org$/.test(url.hostname)
            && (url.pathname.startsWith('/wiki/') || url.pathname === '/w/index.php')
            && (link.classList.contains('interwiki') || link.closest('[data-provider="wikipedia"]'))) {
            const title = url.pathname.startsWith('/wiki/') ? decodeURIComponent(url.pathname.slice(6)) : url.searchParams.get('title');
            if (!title || link.querySelector('img') || /^(?:File|Special|Category|Template|Help|Wikipedia|Talk):/i.test(title)) return null;
            return { provider: 'wikipedia', url: url.href, title: title.replaceAll('_', ' '), key: 'wikipedia:' + url.hostname.replace('.m.', '.') + ':' + title };
        }
        if (!link.classList.contains('wikilink1') || url.origin !== location.origin) return null;
        let id = link.dataset.wikiId || url.searchParams.get('id');
        if (!id) {
            const base = new URL(DOKU_BASE, location.href).pathname;
            if (url.pathname.startsWith(base)) id = decodeURIComponent(url.pathname.slice(base.length).replace(/^doku\.php\/?/, '').replaceAll('/', ':'));
        }
        if (!id) return null;
        return { provider: 'wiki', id, url: url.href, title: link.textContent.trim() || id, key: 'wiki:' + id };
    }

    function hovered(record) {
        const focusedSource = record.source === document.activeElement || record.source.getRootNode()?.activeElement === record.source;
        if (record.element.matches(':hover') || record.source.matches(':hover')
            || (record.keyboard && (record.element.contains(document.activeElement) || focusedSource))) return true;
        return Array.from(windows).some(child => child.parent === record && hovered(child));
    }

    function keep(record) {
        if (!record) return;
        clearTimeout(record.closeTimer);
        if (record.parent) keep(record.parent);
    }

    function dismissLater(record) {
        if (!record || record.pinned) return;
        clearTimeout(record.closeTimer);
        record.closeTimer = setTimeout(() => { if (!record.pinned && !hovered(record)) remove(record); }, config.closeDelay ?? 300);
    }

    function remove(record) {
        clearTimeout(record.closeTimer);
        windows.delete(record);
        record.element.remove();
        for (const child of Array.from(windows)) {
            if (child.parent !== record) continue;
            if (child.pinned) child.parent = null;
            else remove(child);
        }
        if (record.source.getAttribute('aria-describedby') === record.element.id) record.source.removeAttribute('aria-describedby');
        if (record.parent) dismissLater(record.parent);
    }

    function icon(name) {
        const span = document.createElement('span');
        span.className = 'fokus-preview-icon';
        span.setAttribute('aria-hidden', 'true');
        span.style.maskImage = 'url(' + DOKU_BASE + 'lib/plugins/fokusbutler/modules/preview/icons/' + name + '-duotone.svg)';
        span.style.webkitMaskImage = span.style.maskImage;
        return span;
    }

    function position(record) {
        const rect = record.source.getBoundingClientRect();
        const width = record.element.offsetWidth;
        const height = record.element.offsetHeight;
        const margin = 12;
        let left = rect.left;
        let top = rect.bottom + 8;
        if (record.parent) left = rect.right + 10;
        if (left + width > innerWidth - margin) left = innerWidth - width - margin;
        if (top + height > innerHeight - margin) top = Math.max(margin, rect.top - height - 8);
        record.element.style.left = Math.max(margin, left) + 'px';
        record.element.style.top = Math.max(margin, Math.min(top, innerHeight - height - margin)) + 'px';
    }

    async function fill(record, refresh = false) {
        record.body.textContent = 'Loading preview…';
        record.body.setAttribute('aria-busy', 'true');
        try {
            const result = await window.FokusButlerContent.load(record.target, refresh);
            if (!windows.has(record)) return;
            record.title.textContent = result.title;
            record.title.href = result.url;
            window.FokusButlerContent.render(record.body, result, record.target.provider, record.element.id);
        } catch (error) {
            if (!windows.has(record)) return;
            const message = document.createElement('p');
            message.className = 'fokus-preview-error'; message.textContent = error.message || 'The preview could not be loaded.';
            const retry = document.createElement('button');
            retry.type = 'button'; retry.textContent = 'Retry'; retry.addEventListener('click', () => fill(record, true));
            record.body.replaceChildren(message, retry);
        } finally { record.body.removeAttribute('aria-busy'); }
    }

    function open(source, keyboard = false, providedTarget = null) {
        const target = providedTarget || targetFor(source);
        if (!target) return;
        const existing = Array.from(windows).find(record => record.source === source);
        if (existing) { keep(existing); existing.element.style.zIndex = ++layer; return existing; }
        const parent = Array.from(windows).find(record => record.element.contains(source)) || null;
        for (const record of windows) if (!record.pinned && record !== parent) dismissLater(record);
        const element = document.createElement('section');
        element.className = 'fokus-preview-window dokuwiki';
        element.style.setProperty('--fokus-preview-width', (config.width ?? 560) + 'px');
        element.style.setProperty('--fokus-preview-height', (config.height ?? 480) + 'px');
        element.id = 'fokus-preview-' + (++sequence); element.dataset.provider = target.provider;
        element.setAttribute('role', 'dialog'); element.setAttribute('aria-modal', 'false');
        element.style.zIndex = ++layer;
        const bar = document.createElement('header'); bar.className = 'fokus-preview-titlebar';
        const pin = icon('push-pin'); pin.classList.add('fokus-preview-pin'); pin.title = 'Pinned — use the trash button to close';
        const title = document.createElement('a'); title.className = 'fokus-preview-title'; title.textContent = target.title; title.href = target.url;
        title.draggable = false;
        title.id = element.id + '-title'; element.setAttribute('aria-labelledby', title.id);
        if (target.provider === 'wikipedia') {
            title.classList.add('interwiki', 'iw_wp');
            title.target = '_blank'; title.rel = 'noopener noreferrer';
        }
        else if (target.provider === 'wiki') { title.classList.add('wikilink1'); title.dataset.wikiId = target.id; }
        const trash = document.createElement('button'); trash.type = 'button'; trash.className = 'fokus-preview-trash';
        trash.setAttribute('aria-label', 'Close preview'); trash.title = 'Close preview'; trash.dataset.previewAction = 'close'; trash.appendChild(icon('trash'));
        bar.append(pin, title, trash);
        const body = document.createElement('div'); body.className = 'fokus-preview-body fokus-content-body'; body.tabIndex = 0;
        element.append(bar, body); document.body.appendChild(element);
        const record = { source, target, element, title, body, parent, keyboard, pinned: false, suppressClick: false, closeTimer: null };
        windows.add(record); source.setAttribute('aria-describedby', element.id);
        position(record); keep(record);
        trash.addEventListener('click', () => remove(record));
        element.addEventListener('pointerenter', () => { record.keyboard = false; keep(record); });
        element.addEventListener('pointerleave', () => dismissLater(record));
        element.addEventListener('focusin', () => keep(record));
        element.addEventListener('focusout', () => dismissLater(record));
        element.addEventListener('pointerdown', () => { element.style.zIndex = ++layer; });
        element.addEventListener('click', event => {
            if (record.suppressClick) { event.preventDefault(); event.stopImmediatePropagation(); record.suppressClick = false; return; }
            const link = event.target.closest('a');
            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (link.closest('.mw-ref, sup.reference')) {
                event.preventDefault(); return;
            }
            const provided = window.FokusPaneProviders?.fromLink(link);
            if (provided && typeof window.infinitepanelsOpenPage === 'function') {
                event.preventDefault();
                window.infinitepanelsOpenPage(provided.url, true, provided.id);
                if (!record.pinned) remove(record);
                return;
            }
            const href = link.getAttribute('href') || '';
            if (href.startsWith('#')) {
                const destination = document.getElementById(href.slice(1));
                if (destination && body.contains(destination)) { event.preventDefault(); body.scrollTop += destination.getBoundingClientRect().top - body.getBoundingClientRect().top; }
            } else if (link.classList.contains('wikilink1') && typeof window.infinitepanelsOpenPage === 'function') {
                event.preventDefault(); window.infinitepanelsOpenPage(link.href, true, link.dataset.wikiId || null);
                if (!record.pinned) remove(record);
            }
        }, true);
        bar.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.target.closest('button')) return;
            const start = { x: event.clientX, y: event.clientY, left: element.offsetLeft, top: element.offsetTop };
            let moved = false;
            const move = pointer => {
                if (pointer.pointerId !== event.pointerId) return;
                const dx = pointer.clientX - start.x, dy = pointer.clientY - start.y;
                if (!moved && Math.hypot(dx, dy) < 4) return;
                if (!moved) bar.setPointerCapture(event.pointerId);
                moved = true; record.pinned = true; record.suppressClick = true; keep(record); element.classList.add('is-pinned', 'is-dragging');
                element.style.left = Math.max(0, Math.min(innerWidth - element.offsetWidth, start.left + dx)) + 'px';
                element.style.top = Math.max(0, Math.min(innerHeight - bar.offsetHeight, start.top + dy)) + 'px';
                pointer.preventDefault();
            };
            const end = pointer => {
                if (pointer.pointerId !== event.pointerId) return;
                window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', end); window.removeEventListener('pointercancel', end);
                element.classList.remove('is-dragging');
                setTimeout(() => { record.suppressClick = false; }, 0);
                if (bar.hasPointerCapture(event.pointerId)) bar.releasePointerCapture(event.pointerId);
                if (!moved) dismissLater(record);
            };
            window.addEventListener('pointermove', move); window.addEventListener('pointerup', end); window.addEventListener('pointercancel', end);
        });
        fill(record);
        return record;
    }

    function begin(source, target, keyboard = false) {
        if (!source || !target) return;
        if (pending?.source === source) return;
        clearTimeout(pending?.timer);
        const record = Array.from(windows).find(item => item.source === source);
        if (record) { keep(record); return; }
        pending = { source, timer: setTimeout(() => { pending = null; if (source.isConnected) open(source, keyboard, target); }, config.openDelay ?? 750) };
    }

    function end(source, relatedTarget = null) {
        if (!source || (relatedTarget && source.contains(relatedTarget))) return;
        if (pending?.source === source) { clearTimeout(pending.timer); pending = null; }
        const record = Array.from(windows).find(item => item.source === source);
        if (record) dismissLater(record);
    }

    function enter(event) {
        if (event.pointerType === 'touch') return;
        const link = event.target.closest?.('a[href]');
        if (!link || !link.closest('.dokuwiki') || link.contains(event.relatedTarget)) return;
        let target;
        try { target = targetFor(link); } catch (_) { return; }
        if (!target) return;
        link.removeAttribute('title');
        begin(link, target, event.type === 'focusin');
    }

    function leave(event) {
        const link = event.target.closest?.('a[href]');
        if (!link) return;
        end(link, event.relatedTarget);
    }

    function init() {
        config = window.FokusButlerPreviewConfig;
        if (!config?.enabled || window.FokusButlerPreviews) return;
        document.body.classList.add('fokus-preview-active');
        document.addEventListener('pointerover', enter);
        document.addEventListener('pointerout', leave);
        document.addEventListener('focusin', enter);
        document.addEventListener('focusout', leave);
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            clearTimeout(pending?.timer); pending = null;
            for (const record of Array.from(windows)) if (!record.pinned) remove(record);
        });
        document.addEventListener('pointerdown', event => {
            for (const record of Array.from(windows)) {
                record.keyboard = false;
                if (!record.pinned && !record.element.contains(event.target) && !record.source.contains(event.target)) dismissLater(record);
            }
        });
        window.addEventListener('resize', () => {
            for (const record of windows) {
                if (!record.pinned) position(record);
                else {
                    record.element.style.left = Math.max(0, Math.min(record.element.offsetLeft, innerWidth - record.element.offsetWidth)) + 'px';
                    record.element.style.top = Math.max(0, Math.min(record.element.offsetTop, innerHeight - 30)) + 'px';
                }
            }
        });
        window.FokusButlerPreviews = {
            open,
            hover(source, target, entering, keyboard = false, relatedTarget = null) {
                if (entering) {
                    if (relatedTarget && source.contains(relatedTarget)) return;
                    begin(source, target, keyboard);
                } else end(source, relatedTarget);
            },
        };
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
