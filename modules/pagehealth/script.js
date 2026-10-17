(function () {
    'use strict';
function pagehealthInit() {
    function pageIdFromLink(link) {
        try {
            const url = new URL(link.href, window.location.href);
            const baseUrl = new URL(DOKU_BASE, window.location.href);
            if (url.origin !== baseUrl.origin) return null;
            const queryId = url.searchParams.get('id');
            if (queryId) return queryId.replaceAll('/', ':');

            // Support DokuWiki's common PATH_INFO form: /doku.php/page:id
            const basePath = baseUrl.pathname;
            let path = decodeURIComponent(url.pathname);
            if (path.startsWith(basePath)) path = path.slice(basePath.length);
            path = path.replace(/^doku\.php\/?/, '').replace(/^\/+|\/+$/g, '');
            if (!path || path.includes('/lib/')) return null;
            return path.replaceAll('/', ':');
        } catch (_) {
            return null;
        }
    }

    const targets = new Map();
    const states = new Map();
    const requested = new Set();

    function markEmptyLink(link) {
        link.classList.add('pagehealth-empty-link');
        const item = link.closest('.plugin_nspages li');
        if (item) item.classList.add('pagehealth-empty');
    }

    // Batch to keep request sizes and server work bounded on large indexes.
    async function classifyBatches(entries) {
        for (let start = 0; start < entries.length; start += 500) {
            const body = new URLSearchParams();
            const ids = entries.slice(start, start + 500);
            body.set('call', 'fokusbutler_pagehealth');
            body.set('ids', JSON.stringify(ids));
            try {
                const response = await fetch(DOKU_BASE + 'lib/exe/ajax.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body
                });
                if (!response.ok) continue;
                const results = await response.json();
                for (const id of ids) {
                    if (typeof results[id] !== 'boolean') continue;
                    states.set(id, results[id]);
                    if (results[id]) {
                        for (const link of targets.get(id) || []) markEmptyLink(link);
                    }
                }
            } catch (error) {
                console.warn('Page Health could not classify an internal-link batch.', error);
            }
        }
    }

    function collectLinks(root) {
        if (!root || (root.nodeType !== Node.ELEMENT_NODE && root !== document)) return;
        const links = [];
        if (root.nodeType === Node.ELEMENT_NODE && root.matches('a.wikilink1[href]')) {
            links.push(root);
        }
        if (root.querySelectorAll) {
            links.push(...root.querySelectorAll('a.wikilink1[href]'));
        }

        const newIds = [];
        for (const link of links) {
            // Tag plugin pages are virtual endpoints (for example ?do=showtag),
            // not wiki pages whose content Page Health should classify.
            if (link.relList.contains('tag')) continue;
            const id = pageIdFromLink(link);
            if (!id) continue;
            if (!targets.has(id)) targets.set(id, new Set());
            targets.get(id).add(link);

            if (states.get(id) === true) {
                markEmptyLink(link);
            } else if (!states.has(id) && !requested.has(id)) {
                requested.add(id);
                newIds.push(id);
            }
        }
        if (newIds.length) classifyBatches(newIds);
    }

    function loadSummaries(root) {
        if (!root || (root.nodeType !== Node.ELEMENT_NODE && root !== document)) return;
        const summaries = [];
        if (root.nodeType === Node.ELEMENT_NODE && root.matches('[data-pagehealth-summary]')) {
            summaries.push(root);
        }
        if (root.querySelectorAll) {
            summaries.push(...root.querySelectorAll('[data-pagehealth-summary]'));
        }

        const pending = summaries.filter((summary) => summary.dataset.pagehealthLoaded !== '1');
        if (!pending.length) return;
        pending.forEach((summary) => { summary.dataset.pagehealthLoaded = '1'; });

        const body = new URLSearchParams();
        body.set('call', 'fokusbutler_pagehealth_stats');
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body
        }).then((response) => {
            if (!response.ok) throw new Error('Summary request failed.');
            return response.json();
        }).then((stats) => {
            for (const summary of pending) summary.textContent = stats.message;
        }).catch(() => {
            for (const summary of pending) {
                summary.textContent = summary.dataset.error || 'Page Health summary unavailable.';
            }
        });
    }

    collectLinks(document);
    loadSummaries(document);
    const linkObserver = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                collectLinks(node);
                loadSummaries(node);
            }
        }
    });
    linkObserver.observe(document.body, { childList: true, subtree: true });

}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pagehealthInit, { once: true });
} else {
    pagehealthInit();
}

})();
