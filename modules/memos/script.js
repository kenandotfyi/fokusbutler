(function () {
    'use strict';
    const config = window.FokusButlerJotsConfig;
    if (!config) return;
    let snapshot = { memos: [], files: {}, pending: [], syncedAt: 0 };
    let refreshing = null;
    let loading = null;
    let syncMessage = '';
    let errorMessage = '';
    const views = new Set();
    const node = (tag, className = '', text = '') => {
        const el = document.createElement(tag); el.className = className; el.textContent = text; return el;
    };
    const button = (text, action, className = '') => {
        const el = node('button', className, text); el.type = 'button'; el.addEventListener('click', action); return el;
    };
    function iconButton(label, iconUrl, action, className = 'fokus-jots-action') {
        const el = button('', action, className); el.setAttribute('aria-label', label); el.title = label;
        const image = node('img'); image.src = iconUrl; image.alt = ''; image.setAttribute('aria-hidden', 'true'); el.append(image); return el;
    }
    function icon() { const image = node('img', 'fokus-jots-icon'); image.src = config.icon; image.alt = ''; image.setAttribute('aria-hidden', 'true'); return image; }
    async function request(action, values = {}) {
        const response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json' }, body: new URLSearchParams({ action, sectok: config.token, ...values }), signal: AbortSignal.timeout(45000) });
        let data;
        try { data = await response.json(); } catch (_) { throw new Error('The wiki returned an unexpected response. Your draft is retained.'); }
        if (!response.ok || !data.ok) throw new Error(data.error || 'Jots could not complete this request.');
        return data;
    }
    function publish(statusOnly = false) { for (const view of views) { if (view.root.isConnected) statusOnly ? view.updateStatus() : view.render(); else views.delete(view); } }
    function publishJotsStatus(syncing = !!refreshing) {
        document.dispatchEvent(new CustomEvent('fokus:jots-status', {detail: {
            total: snapshot.syncedAt || snapshot.memos.length ? snapshot.memos.length : null,
            syncedAt: snapshot.syncedAt,
            syncing: syncing || (!!snapshot.syncing && !errorMessage && !snapshot.syncError),
            stale: !syncing && (!!errorMessage || !!snapshot.syncError),
        }}));
    }
    async function readLocal() {
        if (loading) return loading;
        loading = request('list').then(data => {
            const unchanged = JSON.stringify([snapshot.memos, snapshot.files, snapshot.pending]) === JSON.stringify([data.memos, data.files, data.pending]);
            snapshot = data; publish(unchanged);
            publishJotsStatus();
            return data;
        }).finally(() => { loading = null; });
        return loading;
    }
    async function refresh(force = false) {
        if (!config.configured) return;
        if (refreshing) return refreshing;
        refreshing = (async () => {
            syncMessage = 'Refreshing from Memos…'; errorMessage = ''; publish(true); publishJotsStatus(true);
            try {
                let more = true, first = true;
                while (more) {
                    const step = await request('sync', { force: first && force ? '1' : '0' });
                    first = false; more = step.more; syncMessage = more ? (step.message || 'Syncing…') : '';
                    publish(true);
                }
                await readLocal();
            } catch (error) { errorMessage = error.message; throw error; }
            finally { syncMessage = ''; refreshing = null; publish(true); publishJotsStatus(false); }
        })();
        return refreshing;
    }
    function startReading() {
        if (!config.configured) { publish(); return; }
        readLocal().then(() => refresh()).catch(error => { errorMessage = error.message; publish(); });
    }
    function timestamp(value) { const date = new Date(value); return Number.isNaN(date.getTime()) ? '' : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); }
    function statusText() {
        if (!config.configured) return 'Connect your Memos account to start jotting.';
        if (syncMessage) return syncMessage;
        if (errorMessage || snapshot.syncError) return errorMessage || snapshot.syncError;
        const pending = snapshot.pending.length ? ` · ${snapshot.pending.length} pending` : '';
        return snapshot.syncedAt ? `Synced ${timestamp(snapshot.syncedAt * 1000)}${pending}` : `Not synced yet${pending}`;
    }
    function fileUrl(file) { const url = new URL(config.endpoint, location.href); url.searchParams.set('action', 'file'); url.searchParams.set('key', file.key); return url.href; }
    function remoteUrl(memo) { return (snapshot.url || '').replace(/\/$/, '') + '/' + memo.name; }
    function attachmentForUrl(memo, value) {
        let target;
        try { target = new URL(value, (snapshot.url || location.origin) + '/'); } catch (_) { return null; }
        return (memo.attachments || []).find(a => {
            if (a.externalLink) { try { if (target.href === new URL(a.externalLink, snapshot.url).href) return true; } catch (_) { /* Ignore malformed attachment URLs. */ } }
            return target.origin === new URL(snapshot.url || location.origin).origin && target.pathname.startsWith('/file/' + a.name + '/');
        });
    }
    function renderMarkdown(memo) {
        const body = node('div', 'fokus-jots-markdown');
        if (!window.marked || !window.DOMPurify) { body.textContent = memo.content; return body; }
        const fragment = DOMPurify.sanitize(marked.parse(memo.content || '', { gfm: true, breaks: true }), {
            RETURN_DOM_FRAGMENT: true,
            ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'del', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'img', 'code', 'pre', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'input', 'details', 'summary'],
            ALLOWED_ATTR: ['href', 'src', 'alt', 'title', 'type', 'checked', 'disabled', 'start', 'colspan', 'rowspan', 'open']
        });
        const used = new Set();
        fragment.querySelectorAll('img').forEach(image => {
            const attachment = attachmentForUrl(memo, image.getAttribute('src') || '');
            const file = attachment && snapshot.files[attachment.name];
            if (!file?.cached) { image.removeAttribute('src'); image.alt = (image.alt || 'Image') + ' · not cached'; return; }
            image.src = fileUrl(file); image.classList.add('media'); image.loading = 'lazy'; used.add(attachment.name);
        });
        fragment.querySelectorAll('input').forEach(input => { input.type = 'checkbox'; input.disabled = true; });
        fragment.querySelectorAll('a').forEach(link => {
            const attachment = attachmentForUrl(memo, link.getAttribute('href') || '');
            const file = attachment && snapshot.files[attachment.name];
            if (file?.cached) link.href = fileUrl(file);
            else { try { const url = new URL(link.getAttribute('href'), (snapshot.url || location.origin) + '/'); if (!/^https?:$/.test(url.protocol)) link.removeAttribute('href'); else link.href = url.href; } catch (_) { link.removeAttribute('href'); } }
            link.target = '_blank'; link.rel = 'noopener noreferrer';
        });
        body.append(fragment);
        const gallery = node('div', 'fokus-jots-attachments');
        for (const attachment of memo.attachments || []) {
            const file = snapshot.files[attachment.name];
            const item = node('div', 'fokus-jots-attachment');
            if (file?.cached) {
                const link = node('a', '', attachment.filename); link.href = fileUrl(file); link.target = '_blank'; link.rel = 'noopener noreferrer';
                if (/^image\/(?:png|jpeg|gif|webp|avif)$/.test(attachment.type) && !used.has(attachment.name)) {
                    const image = node('img', 'media'); image.src = fileUrl(file); image.alt = attachment.filename; image.loading = 'lazy';
                    link.replaceChildren(image, node('span', '', attachment.filename));
                } else if (used.has(attachment.name)) continue;
                item.append(link);
            } else {
                item.append(node('span', '', attachment.filename), node('small', '', file?.error || 'Attachment will be available after synchronization.'));
            }
            gallery.append(item);
        }
        if (gallery.children.length) body.append(gallery);
        return body;
    }
    let composer;
    let draft = {};
    let previousFocus;
    let lastWikiContext = null;
    let draftStorageFailed = false;
    function readDraft() { try { return JSON.parse(localStorage.getItem(config.storageKey + ':draft') || '{}'); } catch (_) { return {}; } }
    function persistDraft() {
        if (!composer) return;
        draft.content = composer.text.value;
        try { localStorage.setItem(config.storageKey + ':draft', JSON.stringify(draft)); draftStorageFailed = false; }
        catch (_) { draftStorageFailed = true; composer.status.textContent = 'Draft recovery is unavailable in this browser. Keep this window open until saved.'; }
    }
    function pageContext() {
        const pane = document.activeElement?.closest?.('.infinitepanels-pane') || document.querySelector('.infinitepanels-pane-current');
        if (lastWikiContext) return lastWikiContext;
        if (pane?.classList.contains('infinitepanels-wikipedia-only-host') || (pane?.dataset.provider && pane.dataset.provider !== 'wiki')) return null;
        if (!pane && !document.querySelector('#dokuwiki__top.mode_show')) return null;
        return { id: pane?.dataset.pageId || config.pageId, url: pane?.dataset.pageUrl || config.pageUrl };
    }
    function updateContextButton() {
        const linked = Boolean(draft.context && composer.text.value.includes(draft.context.url));
        composer.attach.textContent = draft.context ? (linked ? 'Page linked · ' : 'Attach page · ') + draft.context.id : '';
        composer.attach.setAttribute('aria-pressed', String(linked));
        composer.attach.disabled = linked;
    }
    function ensureComposer() {
        if (composer) return;
        const dialog = node('dialog', 'fokus-jots-dialog'); dialog.setAttribute('aria-labelledby', 'fokus-jots-title');
        const form = node('form');
        const header = node('header', 'fokus-jots-composer-header fokus-popup-header');
        const title = node('h2', '', 'Quick jot'); title.id = 'fokus-jots-title';
        const browse = iconButton('Browse jots', new URL('notepad-duotone.svg', new URL(config.icon, window.location.href)).href, () => { dialog.close(); openPane(); }, 'fokus-popup-close');
        const close = iconButton('Close Quick jot', new URL('x-square-duotone.svg', new URL(config.icon, window.location.href)).href, () => dialog.close(), 'fokus-popup-close');
        header.append(icon(), title, browse, close);
        const text = node('textarea'); text.placeholder = 'What’s on your mind?'; text.setAttribute('aria-label', 'Jot text'); text.rows = 9; text.maxLength = 1000000;
        const attach = button('', () => {
            if (!draft.context) return;
            const separator = text.value && !text.value.endsWith('\n\n') ? '\n\n' : '';
            const label = draft.context.id.replace(/[\[\]\\]/g, '\\$&');
            text.value += separator + `[${label}](${draft.context.url})`;
            persistDraft(); updateContextButton(); text.focus(); text.setSelectionRange(text.value.length, text.value.length);
            status.textContent = 'Page link inserted into your draft';
        }, 'fokus-jots-context');
        attach.setAttribute('aria-pressed', 'false');
        const status = node('p', 'fokus-jots-composer-status'); status.setAttribute('role', 'status');
        const footer = node('footer', 'fokus-jots-composer-footer');
        const hint = node('small', '', 'Ctrl / ⌘ + Enter to save · Esc to keep draft');
        const save = node('button', 'fokus-jots-primary', 'Save jot'); save.type = 'submit';
        footer.append(hint, save); form.append(header, text, attach, status, footer); dialog.append(form); document.body.append(dialog);
        composer = { dialog, text, attach, status, save, title };
        text.addEventListener('input', () => { persistDraft(); updateContextButton(); if (!draftStorageFailed) status.textContent = 'Draft saved in this browser'; });
        dialog.addEventListener('close', () => { persistDraft(); previousFocus?.focus?.({ preventScroll: true }); });
        text.addEventListener('keydown', event => { if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); form.requestSubmit(); } });
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (save.disabled || !text.value.trim()) { text.focus(); return; }
            if (!config.configured) { status.textContent = 'Connect Memos in Fokus Butler → Jots before saving.'; return; }
            persistDraft(); save.disabled = true; text.disabled = true; attach.disabled = true; status.textContent = 'Saving…';
            const content = text.value;
            try {
                const result = await request('save', { operation: draft.operation, name: draft.name || '', content, baseUpdateTime: draft.baseUpdateTime || '' });
                draft = { operation: crypto.randomUUID(), content: '' }; text.value = ''; attach.hidden = true; persistDraft();
                composer.title.textContent = 'Quick jot'; save.textContent = 'Save jot';
                status.textContent = result.saved ? 'Saved to Memos' : 'Saved locally · sync pending. You can retry it in Jots.';
                await readLocal();
                if (result.saved) { dialog.close(); announce('Jot saved to Memos'); }
            } catch (error) { status.textContent = error.message + ' Your draft is retained.'; }
            finally { save.disabled = false; text.disabled = false; updateContextButton(); text.focus(); }
        });
    }
    function openComposer(memo = null) {
        const context = pageContext(); previousFocus = document.activeElement;
        ensureComposer();
        const saved = readDraft();
        if (memo) {
            if (saved.content?.trim() && saved.name !== memo.name && !confirm('Replace the current unsaved draft with this note?')) return;
            draft = { operation: crypto.randomUUID(), name: memo.name, content: memo.content, baseUpdateTime: memo.updateTime || '' };
        } else draft = saved.content ? saved : { operation: crypto.randomUUID(), content: '', context, attach: false };
        draft.operation ||= crypto.randomUUID();
        // Recover drafts made before page links were inserted visibly.
        if (draft.attach && draft.context && !draft.content.includes(draft.context.url)) draft.content += `\n\n[${draft.context.id}](${draft.context.url})`;
        draft.attach = false;
        composer.text.value = draft.content || '';
        composer.title.textContent = draft.name ? 'Edit jot' : 'Quick jot';
        composer.save.textContent = draft.name ? 'Save changes' : 'Save jot';
        composer.attach.hidden = !draft.context || Boolean(draft.name);
        updateContextButton();
        composer.status.textContent = draft.content ? 'Recovered draft' : config.configured ? 'New notes are private in Memos' : 'Connect Memos in Fokus Butler → Jots to save notes.';
        if (!composer.dialog.open) composer.dialog.showModal(); composer.text.focus(); persistDraft();
    }
    let toast;
    function announce(text) {
        if (!toast) { toast = node('div', 'fokus-jots-toast'); toast.setAttribute('role', 'status'); document.body.append(toast); }
        toast.textContent = text; toast.hidden = false; clearTimeout(toast._hide); toast._hide = setTimeout(() => { toast.hidden = true; }, 3500);
    }
    async function retryPending(op) {
        try { const result = await request('retry', { operation: op.id }); await readLocal(); announce(result.saved ? 'Pending jot saved to Memos' : result.error); }
        catch (error) { announce(error.message); }
    }
    async function deleteMemo(memo, control) {
        const title = (memo.content || '').split('\n').find(line => line.trim())?.replace(/^#+\s*/, '').slice(0, 90) || 'this jot';
        if (!confirm(`Delete “${title}” from Memos? Its attachments owned by this note will also be deleted.`)) return;
        control.disabled = true;
        try {
            await request('delete', { name: memo.name });
            snapshot.memos = snapshot.memos.filter(item => item.name !== memo.name);
            publish();
            announce('Jot deleted from Memos');
        } catch (error) { control.disabled = false; announce(error.message); }
    }
    function promoteMemo(memo) {
        const dialog = node('dialog', 'fokus-jots-dialog fokus-jots-promote-dialog');
        dialog.setAttribute('aria-labelledby', 'fokus-jots-promote-title');
        const form = node('form');
        const header = node('header', 'fokus-jots-composer-header fokus-popup-header');
        const title = node('h2', '', 'Turn jot into wiki page'); title.id = 'fokus-jots-promote-title';
        header.append(icon(), title, iconButton('Close promotion', new URL('x-square-duotone.svg', new URL(config.icon, window.location.href)).href, () => dialog.close(), 'fokus-popup-close'));
        const label = node('label', 'fokus-jots-field-label', 'Page ID'); label.htmlFor = 'fokus-jots-page-id';
        const target = node('input', 'fokus-jots-page-id'); target.id = 'fokus-jots-page-id'; target.required = true; target.maxLength = 512;
        const slug = (memo.content.split('\n').find(line => line.trim()) || memo.name.slice(6)).normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().replace(/[^\p{L}\p{N}_-]+/gu, '_').replace(/^_+|_+$/g, '').slice(0, 70);
        target.value = 'jots:' + (slug || memo.name.slice(6));
        const help = node('p', 'fokus-jots-promote-help', 'Creates a separate wiki page using its normal wiki permissions. Existing pages are never overwritten. The original jot stays in Memos.');
        const textLabel = node('label', 'fokus-jots-field-label', 'Page content'); textLabel.htmlFor = 'fokus-jots-promote-content';
        const text = node('textarea'); text.id = 'fokus-jots-promote-content'; text.value = memo.content; text.rows = 8; text.maxLength = 1000000;
        const attachments = memo.attachments || [];
        const copyLabel = node('label', 'fokus-jots-copy-attachments');
        const copy = node('input'); copy.type = 'checkbox'; copy.checked = true;
        copyLabel.append(copy, document.createTextNode(` Copy ${attachments.length} attachment${attachments.length === 1 ? '' : 's'} into wiki media`));
        copyLabel.hidden = !attachments.length;
        const mediaHelp = node('p', 'fokus-jots-promote-help', 'Copied files use the destination namespace’s media permissions and remain available independently of Memos.'); mediaHelp.hidden = !attachments.length;
        const status = node('p', 'fokus-jots-composer-status'); status.setAttribute('role', 'status');
        const footer = node('footer', 'fokus-jots-composer-footer');
        const submit = node('button', 'fokus-jots-primary', 'Create wiki page'); submit.type = 'submit';
        footer.append(node('small', '', 'Markdown is preserved · source link included'), submit);
        const updateCopy = () => {
            const missing = attachments.some(a => !snapshot.files[a.name]?.cached);
            submit.disabled = copy.checked && missing;
            status.textContent = submit.disabled ? 'Refresh Jots to cache the missing attachments, or uncheck copying attachments.' : '';
        };
        copy.addEventListener('change', updateCopy); updateCopy();
        form.append(header, label, target, help, textLabel, text, copyLabel, mediaHelp, status, footer); dialog.append(form); document.body.append(dialog);
        const returnFocus = document.activeElement;
        dialog.addEventListener('close', () => { dialog.remove(); returnFocus?.focus?.({ preventScroll: true }); });
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (submit.disabled) return;
            submit.disabled = true; status.textContent = 'Creating wiki page…';
            try {
                const result = await request('promote', { name: memo.name, target: target.value.trim(), content: text.value, version: memo.updateTime || '', attachments: copy.checked ? '1' : '0' });
                dialog.close(); announce('Wiki page created · ' + result.id);
                document.dispatchEvent(new CustomEvent('fokus:jots-page-created', { detail: result }));
                if (window.infinitepanelsOpenPage) window.infinitepanelsOpenPage(result.url, true, result.id);
                else location.href = result.url;
            } catch (error) { status.textContent = error.message; submit.disabled = false; }
        });
        dialog.showModal(); target.focus(); target.select();
    }
    function buildPane(memoName = null) {
        const parsedDocument = document.implementation.createHTMLDocument('Jots');
        const content = node('div', 'pad group fokus-jots-provider');
        const banner = node('div', 'topBanner');
        const heading = node('div', 'fokus-page-heading'); heading.append(icon(), node('span', 'fokus-page-title', 'Jots')); banner.append(heading);
        const body = node('div', 'page group fokus-jots-page');
        const inner = node('div', 'fokus-jots-inner');
        const controls = node('div', 'fokus-jots-controls');
        const create = button('New jot', () => openComposer(), 'fokus-jots-primary');
        const refreshButton = button('Refresh', () => refresh(true).catch(error => announce(error.message)), 'fokus-jots-quiet');
        refreshButton.title = 'Refresh from Memos now. Automatic checks run every two minutes while this pane is visible.';
        const search = node('input'); search.type = 'search'; search.placeholder = 'Search jots…'; search.setAttribute('aria-label', 'Search jots');
        const tags = node('select'); tags.setAttribute('aria-label', 'Filter jots by tag');
        const state = node('select'); state.setAttribute('aria-label', 'Filter active or archived jots');
        for (const [value, label] of [['all', 'All notes'], ['NORMAL', 'Active'], ['ARCHIVED', 'Archived']]) { const option = node('option', '', label); option.value = value; state.append(option); }
        if (memoName) controls.append(button('All jots', () => openPane(), 'fokus-jots-quiet'), create, refreshButton);
        else controls.append(create, search, tags, state, refreshButton);
        const status = node('p', 'fokus-jots-sync-status'); status.setAttribute('role', 'status');
        const pending = node('div', 'fokus-jots-pending');
        const list = node('div', 'fokus-jots-list');
        const more = button('Show more', () => { limit += 30; render(); }, 'fokus-jots-quiet');
        let limit = 30;
        inner.append(controls, status, pending, list, more); body.append(inner); content.append(banner, body);
        function updateStatus() {
            status.textContent = statusText(); refreshButton.disabled = !config.configured || Boolean(syncMessage); create.disabled = !config.configured;
        }
        function render() {
            updateStatus();
            const selectedTag = tags.value;
            const counts = new Map();
            for (const memo of snapshot.memos) for (const tag of new Set(memo.tags || [])) counts.set(tag, (counts.get(tag) || 0) + 1);
            tags.replaceChildren(); const all = node('option', '', 'All tags'); all.value = ''; tags.append(all);
            for (const [tag, count] of [...counts].sort((a, b) => a[0].localeCompare(b[0]))) { const option = node('option', '', '#' + tag + ' · ' + count); option.value = tag; tags.append(option); }
            tags.value = counts.has(selectedTag) ? selectedTag : '';
            pending.replaceChildren();
            for (const op of snapshot.pending.filter(op => !memoName || op.name === memoName)) {
                const card = node('article', 'fokus-jots-pending-card');
                card.append(node('strong', '', op.conflict ? 'Changes need review' : 'Save pending'), node('p', '', op.error || 'This jot is saved locally and waiting for Memos.'), node('pre', '', op.content));
                if (!op.conflict) card.append(button('Retry save', () => retryPending(op), 'fokus-jots-quiet'));
                else card.append(button('Review latest version', async () => {
                    try { await refresh(true); const latest = snapshot.memos.find(m => m.name === op.name); if (latest) openComposer(latest); else announce('The remote note is no longer available. Copy the pending text into a new jot.'); }
                    catch (error) { announce(error.message); }
                }, 'fokus-jots-quiet'));
                card.append(button('Copy pending text', async () => { try { await navigator.clipboard.writeText(op.content); announce('Pending text copied'); } catch (_) { announce('Select the pending text and copy it manually.'); } }, 'fokus-jots-quiet'));
                card.append(button('Discard pending copy', async () => {
                    if (!confirm('Discard this local pending copy? This does not delete a note already saved in Memos.')) return;
                    try { await request('discard', { operation: op.id }); await readLocal(); } catch (error) { announce(error.message); }
                }, 'fokus-jots-quiet'));
                pending.append(card);
            }
            const query = search.value.trim().toLocaleLowerCase();
            const notes = snapshot.memos.filter(m => (!memoName || m.name === memoName) && (!tags.value || (m.tags || []).includes(tags.value)) && (state.value === 'all' || (m.state || 'NORMAL') === state.value) && (!query || (m.content || '').toLocaleLowerCase().includes(query)))
                .sort((a, b) => Number(Boolean(b.pinned)) - Number(Boolean(a.pinned)) || String(b.createTime || '').localeCompare(String(a.createTime || '')));
            list.replaceChildren();
            if (!config.configured) {
                list.append(node('p', 'fokus-jots-empty', 'Connect Memos to bring your notes and attachments into this pane.'));
                if (config.settingsUrl) { const link = node('a', '', 'Open Jots settings'); link.href = config.settingsUrl; list.append(link); }
            } else if (!notes.length) list.append(node('p', 'fokus-jots-empty', snapshot.syncedAt ? (memoName ? 'This jot is no longer in your local mirror.' : 'No jots match these filters.') : 'Your notes will appear after the first synchronization.'));
            for (const memo of notes.slice(0, limit)) {
                const card = node('article', 'fokus-jots-card');
                const header = node('header', 'fokus-jots-card-header');
                const time = node('time', '', timestamp(memo.createTime)); time.dateTime = memo.createTime || '';
                const open = iconButton('Open jot in Memos', config.actionIcons.memos, () => window.open(remoteUrl(memo), '_blank', 'noopener,noreferrer'));
                const edit = iconButton('Edit jot', config.actionIcons.edit, () => openComposer(memo)); edit.disabled = snapshot.pending.some(op => op.name === memo.name);
                const promote = iconButton('Turn jot into wiki page', config.actionIcons.promote, () => promoteMemo(memo)); promote.disabled = edit.disabled;
                const remove = iconButton('Delete jot from Memos', config.actionIcons.delete, () => deleteMemo(memo, remove), 'fokus-jots-delete'); remove.disabled = edit.disabled;
                header.append(time);
                if (memo.pinned) header.append(node('span', 'fokus-jots-badge', 'Pinned'));
                if (memo.state === 'ARCHIVED') header.append(node('span', 'fokus-jots-badge', 'Archived'));
                header.append(open, edit, promote, remove); card.append(header);
                const markdown = renderMarkdown(memo); card.append(markdown);
                if (memo.content.length > 2500) { markdown.classList.add('fokus-jots-collapsed'); const expand = button('Show full note', () => { const collapsed = markdown.classList.toggle('fokus-jots-collapsed'); expand.textContent = collapsed ? 'Show full note' : 'Collapse note'; }, 'fokus-jots-quiet'); card.append(expand); }
                const tagList = node('div', 'fokus-jots-tags');
                for (const tag of memo.tags || []) tagList.append(button('#' + tag, () => { tags.value = tag; limit = 30; render(); }, 'fokus-jots-tag'));
                card.append(tagList); list.append(card);
            }
            more.hidden = notes.length <= limit;
        }
        for (const field of [search, tags, state]) field.addEventListener(field === search ? 'input' : 'change', () => { limit = 30; render(); });
        const view = { root: content, render, updateStatus }; views.add(view); render();
        setTimeout(startReading, 0);
        return { content, parsedDocument, provider: 'memos', title: memoName ? 'Jot' : 'Jots', url: paneUrl(memoName ? 'jots.memo.' + memoName.slice(6) : 'jots.inbox') };
    }
    function paneUrl(id = 'jots.inbox') { const url = new URL(config.homeUrl, location.origin); url.searchParams.set('p', id); return url.href; }
    function openPane() {
        if (window.infinitepanelsOpenPage) window.infinitepanelsOpenPage(paneUrl(), true, 'jots.inbox');
        else location.href = paneUrl();
    }
    const jotPaneId = id => /^jots\.memo\.[a-zA-Z0-9][a-zA-Z0-9-]{0,35}$/.test(id);
    function jotTarget(value) {
        try {
            const url = new URL(value, location.href), id = url.searchParams.get('p');
            if (url.origin !== location.origin || url.username || url.password || url.hash || url.pathname !== new URL(config.homeUrl).pathname || !jotPaneId(id)) return null;
            return {id, url:paneUrl(id)};
        } catch (_) { return null; }
    }
    if (window.FokusPaneProviders) window.FokusPaneProviders.register({
        name: 'memos', matches: id => id === 'jots.inbox' || jotPaneId(id), urlForId: paneUrl,
        fromUrl: jotTarget, fromLink: link => jotTarget(link.href),
        async load(id) { return buildPane(jotPaneId(id) ? 'memos/' + id.slice('jots.memo.'.length) : null); }
    });
    window.FokusButlerJots = { open: openComposer, browse: openPane, refresh };
    function boot() {
        document.querySelectorAll('[data-fokus-jot]').forEach(button => {
            button.title = 'Quick jot (' + config.shortcut + ')'; button.addEventListener('click', () => openComposer());
        });
        document.querySelectorAll('[data-fokus-jots-open]').forEach(button => button.addEventListener('click', () => openPane()));
        const rememberWiki = event => {
            const pane = event.target instanceof Element ? event.target.closest('.infinitepanels-pane') : null;
            if (!pane || pane.classList.contains('infinitepanels-wikipedia-only-host') || (pane.dataset.provider && pane.dataset.provider !== 'wiki')) return;
            lastWikiContext = { id: pane.dataset.pageId, url: pane.dataset.pageUrl || new URL(encodeURIComponent(pane.dataset.pageId).replace(/%3A/gi, ':'), new URL(config.homeUrl).origin + (window.DOKU_BASE || '/')).href };
        };
        document.addEventListener('pointerdown', rememberWiki, true);
        document.addEventListener('focusin', rememberWiki, true);
        document.addEventListener('fokus:jot', () => openComposer());
        document.addEventListener('keydown', event => {
            const keys = config.shortcut.toLowerCase().split('+');
            if (!event.repeat && !event.isComposing && event.key.toLowerCase() === keys.at(-1) && event.altKey === keys.includes('alt') && event.shiftKey === keys.includes('shift') && event.ctrlKey === keys.includes('ctrl') && event.metaKey === keys.includes('meta')) {
                event.preventDefault(); openComposer();
            }
        });
        const refreshVisible = () => {
            if (config.configured && document.visibilityState === 'visible' && [...views].some(view => {
                if (!view.root.isConnected || !view.root.getClientRects().length) return false;
                const rect = view.root.getBoundingClientRect();
                return rect.right > 0 && rect.left < innerWidth && rect.bottom > 0 && rect.top < innerHeight;
            })) {
                readLocal().then(() => refresh()).catch(error => { errorMessage = error.message; publish(); });
            }
        };
        document.addEventListener('visibilitychange', refreshVisible);
        setInterval(refreshVisible, 120000);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
