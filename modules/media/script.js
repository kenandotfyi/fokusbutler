(function () {
    'use strict';
    const api = window.FokusButlerMedia = {};
    api.syntax = function (item, options) {
        const label = String(options.caption || '').replace(/[|{}\r\n]/g, ' ');
        const width = /^\d{1,4}$/.test(String(options.width)) && Number(options.width) > 0 ? String(Number(options.width)) : '';
        const parameters = [];
        if (item.image && width && options.mode !== 'linkonly') parameters.push(width);
        if (!item.image || options.mode === 'linkonly') parameters.push('linkonly');
        else if (options.mode === 'nolink') parameters.push('nolink');
        const left = item.image && ['right', 'center'].includes(options.align) ? ' ' : '';
        const right = item.image && ['left', 'center'].includes(options.align) ? ' ' : '';
        return '{{' + left + ':' + item.id + (parameters.length ? '?' + parameters.join('&') : '') + (label ? '|' + label : '') + right + '}}';
    };
    api.capture = function (edid) {
        const textarea = document.getElementById(edid);
        if (!textarea || textarea.disabled || textarea.readOnly) return null;
        const cm = window.plugin_codemirror?.cm;
        if (cm && cm.getTextArea() === textarea && cm.getWrapperElement().getClientRects().length) {
            if (cm.getOption('readOnly')) return null;
            return { textarea, cm, value: cm.getValue(), start: cm.indexFromPos(cm.getCursor('from')), end: cm.indexFromPos(cm.getCursor('to')), scroll: cm.getScrollInfo() };
        }
        if (!textarea.getClientRects().length) return null;
        return { textarea, value: textarea.value, start: textarea.selectionStart, end: textarea.selectionEnd, scroll: textarea.scrollTop };
    };
    api.insert = function (saved, text) {
        const current = api.capture(saved.textarea.id);
        if (!saved.textarea.isConnected || !current || current.textarea !== saved.textarea || current.cm !== saved.cm || current.value !== saved.value) throw new Error('The editor changed. Close and reopen Media Library before inserting.');
        const target = saved.textarea;
        if (saved.cm) {
            saved.cm.focus();
            saved.cm.setSelection(saved.cm.posFromIndex(saved.start), saved.cm.posFromIndex(saved.end));
            saved.cm.replaceSelection(text, 'end', '+input');
            saved.cm.save();
            saved.cm.scrollTo(saved.scroll.left, saved.scroll.top);
        } else {
            target.focus({ preventScroll: true });
            target.setSelectionRange(saved.start, saved.end);
            if (!document.execCommand('insertText', false, text)) target.setRangeText(text, saved.start, saved.end, 'end');
            target.scrollTop = saved.scroll;
        }
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    };
    api.openReference = function (event, page, close) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey) return false;
        let host = window;
        try {
            if (typeof host.infinitepanelsOpenPage !== 'function' && window.parent !== window && window.parent.location.origin === window.location.origin) host = window.parent;
        } catch (_) {}
        if (typeof host.infinitepanelsOpenPage !== 'function') return false;
        event.preventDefault(); event.stopPropagation(); close();
        host.infinitepanelsOpenPage(page.url, true, page.id);
        return true;
    };
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const button = (text, handler, className) => {
        const node = element('button', className, text);
        node.type = 'button';
        node.addEventListener('click', handler);
        const icons = {'Upload files':'file-plus', 'Upload new version':'file-plus', 'Move / rename':'arrow-square-out', 'Delete file':'trash', 'Restore this version':'clock-counter-clockwise', 'Insert media':'file-plus', 'New namespace':'folder'};
        if (icons[text]) actionIcon(node, icons[text]);
        return node;
    };
    const iconBase = (window.DOKU_BASE || '/') + 'lib/plugins/fokusbutler/core/icons/';
    function actionIcon(node, name, iconOnly = false) {
        const label = node.textContent;
        const image = element('img', 'fokus-media-action-icon'); image.src = iconBase + name + '-duotone.svg'; image.alt = ''; image.setAttribute('aria-hidden', 'true');
        if (iconOnly) { node.textContent = ''; node.setAttribute('aria-label', label); node.title = label; }
        node.prepend(image); return node;
    }
    const bytes = size => size >= 1048576 ? (size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(size / 1024)) + ' KB';
    api.sidebarWidth = (available, ratio) => Math.round(Math.max(130, Math.min(available * ratio, available * 0.4, available - 466)));
    api.namespaceBranches = function (values) {
        const branches = new Map();
        [...values].forEach(folder => {
            let parts = folder.split(':');
            while (parts.length > 1) { parts.pop(); const parent = parts.join(':'); if (!branches.has(parent)) branches.set(parent, []); }
            if (folder && !branches.has(folder)) branches.set(folder, []);
        });
        branches.set('', []);
        [...branches.keys()].filter(Boolean).sort((a,b) => a.localeCompare(b, undefined, {numeric:true})).forEach(folder => {
            const parent = folder.split(':').slice(0,-1).join(':'); branches.get(parent).push(folder);
        });
        return branches;
    };
    let active = null;
    api.open = function (props, edid) {
        if (active) return;
        const saved = edid ? api.capture(edid) : null;
        if (edid && !saved) return;
        const returnFocus = document.activeElement;
        let pendingInsertion = null;
        let ns = saved?.textarea.form?.querySelector('[name="id"]')?.value;
        ns = ns ? ns.split(':').slice(0, -1).join(':') : props.namespace || '';
        const expandedFolders = new Set();
        let data = null, request = 0, timer, uploading = false, dragItem = null, currentOffset = 0;
        const dialog = element('dialog', 'fokus-media');
        active = dialog;
        dialog.setAttribute('aria-labelledby', 'fokus-media-heading');
        const header = element('header', 'fokus-media-header fokus-popup-header');
        const title = element('h2', '', 'Media Library'); title.id = 'fokus-media-heading';
        const close = actionIcon(button('Close Media Library', () => dialog.close(), 'fokus-popup-close'), 'x-square', true);
        const headingIcon = element('img'); headingIcon.src = iconBase + 'image-duotone.svg'; headingIcon.alt = '';
        header.append(headingIcon, title, close);
        const body = element('div', 'fokus-media-body');
        const aside = element('nav', 'fokus-media-folders'); aside.setAttribute('aria-label', 'Media namespaces');
        const center = element('section', 'fokus-media-center');
        const bar = element('div', 'fokus-media-bar');
        const search = element('input'); search.type = 'search'; search.placeholder = 'Search this namespace and its folders…'; search.setAttribute('aria-label', 'Search media');
        const uploadInput = element('input'); uploadInput.type = 'file'; uploadInput.multiple = true; uploadInput.hidden = true;
        const uploadButton = button('Upload files', () => uploadInput.click(), 'fokus-media-primary'); uploadButton.disabled = true;
        bar.append(search, uploadButton, uploadInput);
        const location = element('div', 'fokus-media-location');
        const status = element('div', 'fokus-media-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        const uploadStatus = element('div', 'fokus-media-upload-status'); uploadStatus.setAttribute('role', 'status');
        const progress = element('progress'); progress.max = 100; progress.hidden = true;
        const grid = element('div', 'fokus-media-grid'); grid.setAttribute('aria-label', 'Media files');
        const paging = element('div', 'fokus-media-paging');
        const detail = element('section', 'fokus-media-detail'); detail.setAttribute('aria-label', 'Selected media');
        const detailEmpty = () => detail.replaceChildren(element('p', 'fokus-media-muted', saved ? 'Select a file to preview and insert it.' : 'Select a file to preview it and see where it is used.'));
        detailEmpty();
        center.append(bar, location, status, uploadStatus, progress, grid, paging);
        const splitter = element('div', 'fokus-media-splitter');
        splitter.setAttribute('role', 'separator'); splitter.setAttribute('aria-label', 'Namespace sidebar width');
        splitter.setAttribute('aria-orientation', 'vertical'); splitter.tabIndex = 0;
        body.append(aside, splitter, center, detail);
        const footer = element('footer', 'fokus-media-footer', 'Drop files into the library to upload to the current namespace.');
        dialog.append(header, body, footer); document.body.append(dialog);
        dialog.addEventListener('close', () => {
            request++; clearTimeout(timer); sizeObserver.disconnect(); active = null; dialog.remove();
            if (pendingInsertion !== null) {
                api.insert(saved, pendingInsertion);
            } else if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
        });
        dialog.addEventListener('cancel', event => { if (uploading) event.preventDefault(); });
        const sizeKey = 'fokus-media-sidebar:' + new URL(props.ajaxUrl, window.location.href).pathname;
        let sideRatio = 0.18, dragging = false;
        try { const ratio = Number(localStorage.getItem(sizeKey)); if (ratio >= 0.1 && ratio <= 0.5) sideRatio = ratio; } catch (_) {}
        function resizeSidebar() {
            const available = body.getBoundingClientRect().width;
            const width = api.sidebarWidth(available, sideRatio);
            body.style.setProperty('--fokus-media-sidebar-width', width + 'px');
            splitter.setAttribute('aria-valuemin', '130');
            splitter.setAttribute('aria-valuemax', String(api.sidebarWidth(available, 1)));
            splitter.setAttribute('aria-valuenow', String(width));
        }
        function setSidebar(width) {
            const available = body.getBoundingClientRect().width;
            if (!available) return;
            sideRatio = api.sidebarWidth(available, width / available) / available;
            resizeSidebar();
            try { localStorage.setItem(sizeKey, String(sideRatio)); } catch (_) {}
        }
        splitter.addEventListener('pointerdown', event => { if (event.button !== 0) return; event.preventDefault(); dragging = true; splitter.setPointerCapture(event.pointerId); });
        splitter.addEventListener('pointermove', event => { if (dragging) setSidebar(event.clientX - body.getBoundingClientRect().left); });
        const stopResize = () => { dragging = false; };
        splitter.addEventListener('pointerup', stopResize); splitter.addEventListener('pointercancel', stopResize); splitter.addEventListener('lostpointercapture', stopResize);
        splitter.addEventListener('keydown', event => {
            const current = aside.getBoundingClientRect().width;
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            setSidebar(event.key === 'Home' ? 130 : event.key === 'End' ? body.getBoundingClientRect().width : current + (event.key === 'ArrowLeft' ? -20 : 20));
        });
        splitter.addEventListener('dblclick', () => { sideRatio = 0.18; setSidebar(body.getBoundingClientRect().width * sideRatio); });
        const sizeObserver = new ResizeObserver(resizeSidebar);
        dialog.showModal(); sizeObserver.observe(body); resizeSidebar(); search.focus();
        function pick(item, card) {
            grid.querySelectorAll('button').forEach(node => node.setAttribute('aria-pressed', node === card ? 'true' : 'false'));
            detail.replaceChildren();
            if (item.image) {
                const image = element('img', 'fokus-media-preview'); image.src = item.thumbnail; image.alt = item.name;
                const preview = element('a', 'fokus-media-preview-link'); preview.href = item.url; preview.target = '_blank'; preview.rel = 'noopener'; preview.setAttribute('aria-label', 'Enlarge ' + item.name);
                preview.append(image); detail.append(preview);
                preview.addEventListener('click', event => {
                    if (event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !window.FokusButlerImagePop?.openImage) return;
                    event.preventDefault(); window.FokusButlerImagePop.openImage(item.url, item.name, preview, item.thumbnail);
                });
            } else detail.append(element('div', 'fokus-media-filetype', item.name.split('.').pop().toUpperCase()));
            const name = element('h3', '', item.name);
            const id = element('code', 'fokus-media-id', item.id);
            const meta = element('p', 'fokus-media-muted', bytes(item.size) + (item.width ? ' · ' + item.width + ' × ' + item.height : '') + ' · ' + item.mime);
            const original = element('a', '', 'Open original'); original.href = item.url; original.target = '_blank'; original.rel = 'noopener';
            detail.append(name, id, meta, original);
            if (!saved) {
                const manage = element('a', 'fokus-media-manage', 'Manage file'); manage.href = item.manageUrl; manage.target = '_blank'; manage.rel = 'noopener';
                detail.append(manage);
            }
            const management = element('section', 'fokus-media-management'); management.setAttribute('aria-label', 'File management');
            management.append(element('p', 'fokus-media-muted', 'Loading file actions…'));
            detail.append(management);
            const usage = element('section', 'fokus-media-usage'); usage.setAttribute('aria-label', 'Media references');
            usage.append(element('h3', '', 'Used on these pages'));
            const usageStatus = element('p', 'fokus-media-muted', 'Loading references…'); usageStatus.setAttribute('role', 'status'); usage.append(usageStatus);
            detail.append(usage);
            async function references() {
                const url = new URL(props.ajaxUrl, window.location.href);
                Object.entries({call: 'fokusbutler_media', operation: 'details', id: item.id}).forEach(([key, value]) => url.searchParams.set(key, value));
                try {
                    const response = await fetch(url, { credentials: 'same-origin' });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Could not load references.');
                    if (!usage.isConnected) return;
                    usageStatus.textContent = result.references.length ? result.references.length + ' page' + (result.references.length === 1 ? '' : 's') : 'No indexed references on pages you can read.';
                    const list = element('ul');
                    result.references.forEach(page => {
                        const row = element('li'), link = element('a', '', page.title);
                        link.href = page.url; if (saved) link.target = '_blank'; link.rel = 'noopener'; link.title = page.id;
                        link.addEventListener('click', event => { if (!uploading) api.openReference(event, page, () => dialog.close()); else event.preventDefault(); });
                        row.append(link, element('span', 'fokus-media-muted', page.id)); list.append(row);
                    });
                    usage.append(list);
                    management.replaceChildren();
                    renderManagement(item, result, management);
                } catch (error) { if (usage.isConnected) usageStatus.replaceChildren(document.createTextNode(error.message + ' '), button('Retry', references)); }
            }
            references();
            if (!saved) return;
            function field(label, control) { const wrapper = element('label', 'fokus-media-field', label); wrapper.append(control); detail.append(wrapper); return control; }
            const caption = field('Caption / link text', element('input')); caption.type = 'text'; caption.maxLength = 500;
            const width = element('input'); width.type = 'number'; width.min = 1; width.max = 9999; width.placeholder = 'Original size';
            const align = element('select');
            [['inline', 'Inline'], ['left', 'Left'], ['center', 'Center'], ['right', 'Right']].forEach(([value, label]) => { const option = element('option', '', label); option.value = value; align.append(option); });
            const mode = element('select');
            [['default', 'Image with detail link'], ['nolink', 'Image without link'], ['linkonly', 'Text link']].forEach(([value, label]) => { const option = element('option', '', label); option.value = value; mode.append(option); });
            if (item.image) { field('Width (px)', width); field('Alignment', align); field('Display', mode); }
            const syntax = element('code', 'fokus-media-syntax');
            function options() { return { caption: caption.value, width: width.value, align: align.value, mode: mode.value }; }
            function update() { syntax.textContent = api.syntax(item, options()); }
            [caption, width, align, mode].forEach(node => node.addEventListener('input', update)); update();
            const insert = button('Insert media', () => {
                if (uploading) return;
                try {
                    // Focus the editor after dismissing the modal's focus trap.
                    const text = api.syntax(item, options());
                    const current = api.capture(edid);
                    if (!current || current.value !== saved.value || current.textarea !== saved.textarea || current.cm !== saved.cm) throw new Error('The editor changed. Close and reopen Media Library before inserting.');
                    pendingInsertion = text; dialog.close();
                } catch (error) { status.textContent = error.message; }
            }, 'fokus-media-primary');
            detail.append(syntax, insert);
        }
        function confirmAction(title, description, label, initial, actionLabel) {
            return new Promise(resolve => {
                const confirm = element('dialog', 'fokus-media-confirm');
                const heading = element('h2', '', title); heading.id = 'fokus-media-confirm-heading';
                confirm.setAttribute('aria-labelledby', heading.id);
                const form = element('form');
                const descriptionNode = element('p', '', description);
                let input;
                form.append(heading, descriptionNode);
                if (label) {
                    const wrapper = element('label', '', label); input = element('input'); input.type = 'text'; input.value = initial; input.required = true;
                    wrapper.append(input); form.append(wrapper);
                }
                let result = null;
                const controls = element('div', 'fokus-media-confirm-buttons');
                const cancel = button('Cancel', () => confirm.close());
                const submit = button(actionLabel, () => {}); submit.type = 'submit';
                controls.append(cancel, submit); form.append(controls); confirm.append(form); document.body.append(confirm);
                form.addEventListener('submit', event => { event.preventDefault(); result = input ? input.value.trim() : true; confirm.close(); });
                confirm.addEventListener('close', () => { confirm.remove(); resolve(result); }, {once: true});
                confirm.showModal(); (input || cancel).focus();
            });
        }
        async function getDetails(item) {
            const url = new URL(props.ajaxUrl, window.location.href);
            Object.entries({call: 'fokusbutler_media', operation: 'details', id: item.id}).forEach(([key,value]) => url.searchParams.set(key,value));
            const response = await fetch(url, {credentials: 'same-origin'}), result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Could not load file actions.');
            return result;
        }
        async function moveFile(item, destination) {
            if (uploading) return;
            try {
                const details = await getDetails(item);
                if (!details.allowed.move) throw new Error('Moving requires delete permission and the Move plugin.');
                const target = await confirmAction('Move or rename file', item.id + '\nPage links and revision history will follow the file. Close editors for pages using it before moving.', 'Destination media ID', destination || item.id, 'Move file');
                if (target) await mutate('move', item, details, {destination: target});
            } catch (error) { uploadStatus.textContent = error.message; }
        }
        async function mutate(operation, item, details, fields = {}, file = null) {
            if (uploading) return null;
            uploading = true;
            clearTimeout(timer);
            const disabled = new Map();
            dialog.querySelectorAll('button,input,select,textarea').forEach(node => { disabled.set(node, node.disabled); node.disabled = true; });
            uploadStatus.textContent = 'Working on ' + item.id + '…';
            const form = new FormData();
            Object.entries({call: 'fokusbutler_media', operation, id: item.id, fingerprint: details.fingerprint, sectok: saved?.textarea.form?.querySelector('[name="sectok"]')?.value || props.token || '', ...fields}).forEach(([key,value]) => form.append(key,value));
            if (file) form.append('upload', file);
            let result;
            try {
                result = await new Promise((resolve,reject) => {
                    const xhr = new XMLHttpRequest(); xhr.open('POST',props.ajaxUrl); xhr.responseType = 'json'; xhr.timeout = 300000;
                    if (file) { progress.hidden = false; progress.value = 0; }
                    xhr.upload.onprogress = event => { if (event.lengthComputable) progress.value = event.loaded / event.total * 100; };
                    xhr.onerror = () => reject(new Error('Network error. Select the file again to check whether the action completed.'));
                    xhr.ontimeout = () => reject(new Error('The request timed out. Check the file before retrying.'));
                    xhr.onload = () => xhr.status >= 200 && xhr.status < 300 && xhr.response?.id ? resolve(xhr.response) : reject(new Error(xhr.response?.error || 'The file action failed.'));
                    xhr.send(form);
                });
            } catch (error) { uploadStatus.textContent = error.message; }
            finally {
                uploading = false; progress.hidden = true;
                disabled.forEach((wasDisabled,node) => { if (node.isConnected) node.disabled = wasDisabled; });
            }
            if (!result) return null;
            if (operation === 'namespace') { ns = result.namespace; search.value = ''; currentOffset = 0; }
            if (operation === 'move') { ns = result.id.split(':').slice(0,-1).join(':'); search.value = ''; currentOffset = 0; }
            await load(currentOffset);
            uploadStatus.replaceChildren(document.createTextNode(operation === 'delete' ? 'Deleted ' + item.id + '. ' : operation === 'namespace' ? 'Created namespace :' + result.namespace + '.' : 'Saved ' + result.id + '.'));
            if (result.deleted && result.revision) {
                uploadStatus.append(button('Undo deletion', () => mutate('restore', item, {fingerprint: result.fingerprint}, {revision: result.revision})));
            }
            if (!result.deleted && !result.created) {
                const index = data?.items.findIndex(entry => entry.id === result.id);
                if (index >= 0) pick(data.items[index], grid.querySelectorAll('.fokus-media-card')[index]);
            }
            document.dispatchEvent(new CustomEvent('fokus:statuschange'));
            return result;
        }
        function compareVersion(item, entry) {
            const compare = element('dialog','fokus-media-compare');
            compare.setAttribute('aria-label','Compare media versions');
            const header = element('header'); header.append(element('h2','','Compare versions'),button('Close comparison',()=>compare.close()));
            const panels = element('div','fokus-media-compare-panels');
            [[new Date(entry.revision * 1000).toLocaleString(),entry.url],['Current version',item.url]].forEach(([label,url])=>{
                const figure = element('figure'), image = element('img'); image.src=url; image.alt=label;
                figure.append(element('figcaption','',label),image); panels.append(figure);
            });
            compare.append(header,panels); document.body.append(compare);
            compare.addEventListener('close',()=>compare.remove(),{once:true}); compare.showModal();
        }
        function renderManagement(item, details, host) {
            host.append(element('h3', '', 'File actions'));
            const actions = element('div', 'fokus-media-file-actions');
            if (details.allowed.replace) {
                const replacement = element('input'); replacement.type = 'file'; replacement.hidden = true; replacement.accept = '.' + item.name.split('.').pop();
                replacement.addEventListener('change', async () => {
                    const file = replacement.files[0]; if (!file) return;
                    const proceed = await confirmAction('Upload new version', 'Replace ' + item.id + ' with ' + file.name + '? ' + (details.allowed.revisions ? 'The current version will be retained in history.' : 'Revision history is disabled; this replaces the current file permanently.'), null, null, 'Upload new version');
                    if (proceed) await mutate('replace', item, details, {}, file);
                    replacement.value = '';
                });
                actions.append(button('Upload new version', () => replacement.click()), replacement);
            }
            if (details.allowed.move) actions.append(button('Move / rename', () => moveFile(item)));
            else if (details.allowed.delete) actions.append(element('p', 'fokus-media-muted', 'Enable the Move plugin for moving and renaming.'));
            if (details.allowed.delete) actions.append(button('Delete file', async () => {
                const proceed = await confirmAction('Delete file', 'Delete ' + item.id + '? DokuWiki’s configured reference checks apply. ' + (details.allowed.revisions ? 'You can undo this deletion while the library stays open.' : 'Revision history is disabled; this deletion is permanent.'), null, null, 'Delete file');
                if (proceed) await mutate('delete', item, details);
            }, 'fokus-media-danger'));
            host.append(actions);
            if (details.history.length || details.historyMore) {
                const history = element('details', 'fokus-media-history'); history.append(element('summary', '', 'Version history (' + details.history.length + ')'));
                const seenRevisions = new Set();
                function addRevisions(entries) { entries.forEach(entry => {
                    if (seenRevisions.has(entry.revision)) return; seenRevisions.add(entry.revision);
                    const row = element('div'); const link = element('a', '', new Date(entry.revision * 1000).toLocaleString()); link.href = entry.url; link.target = '_blank'; link.rel = 'noopener';
                    row.append(link);
                    if (entry.summary) row.append(element('span', 'fokus-media-muted', entry.summary));
                    if (details.allowed.replace) row.append(button('Restore this version', async () => {
                        const proceed = await confirmAction('Restore version', 'Restore ' + item.id + ' to ' + new Date(entry.revision * 1000).toLocaleString() + '? The current version will be retained in history.', null, null, 'Restore version');
                        if (proceed) await mutate('restore', item, details, {revision: entry.revision});
                    }));
                    if (item.image) row.append(button('Compare with current', () => compareVersion(item, entry)));
                    history.append(row);
                }); }
                addRevisions(details.history);
                let offset = details.historyOffset;
                const older = button('Load older versions', async () => {
                    older.disabled = true;
                    try {
                        const url = new URL(props.ajaxUrl, window.location.href);
                        Object.entries({call:'fokusbutler_media',operation:'history',id:item.id,offset}).forEach(([key,value])=>url.searchParams.set(key,value));
                        const response = await fetch(url,{credentials:'same-origin'}), result = await response.json();
                        if (!response.ok) throw new Error(result.error || 'Could not load older versions.');
                        if (!history.isConnected) return;
                        addRevisions(result.history); offset = result.offset; older.hidden = !result.more; history.append(older);
                    } catch (error) { uploadStatus.textContent = error.message; }
                    finally { older.disabled = false; }
                });
                older.hidden = !details.historyMore; history.append(older);
                host.append(history);
            }
            if (details.metadata.length) {
                const metadata = element('details', 'fokus-media-metadata'); metadata.append(element('summary', '', 'Image metadata'));
                const inputs = [];
                details.metadata.forEach(field => {
                    const wrapper = element('label', 'fokus-media-field', field.label), control = element(field.multiline ? 'textarea' : 'input');
                    control.value = field.value; control.maxLength = 10000; control.disabled = !details.allowed.metadata;
                    wrapper.append(control); metadata.append(wrapper); inputs.push([field.key, control]);
                });
                if (details.allowed.metadata) metadata.append(button('Save metadata', () => mutate('metadata', item, details, Object.fromEntries(inputs.map(([key,control]) => ['metadata[' + key + ']', control.value])))));
                host.append(metadata);
            }
        }
        function folders() {
            aside.replaceChildren(element('h3', '', 'Namespaces'));
            const newNamespace = button('New namespace', async () => {
                if (uploading) return;
                const name = await confirmAction('New namespace', 'Create a media namespace, for example drawings:schematics.', 'Namespace ID', ns ? ns + ':new_namespace' : 'new_namespace', 'Create namespace');
                if (name) await mutate('namespace', {id: name}, {fingerprint: ''});
            }, 'fokus-media-new-namespace');
            newNamespace.disabled = uploading; aside.append(newNamespace);
            const values = new Set(['', ...data.folders, ns]);
            const branches = api.namespaceBranches(values);
            const ancestorParts = ns.split(':');
            while (ancestorParts.length > 1) { ancestorParts.pop(); expandedFolders.add(ancestorParts.join(':')); }
            const tree = element('div', 'fokus-media-tree');
            function renderFolder(folder, host) {
                const node = button(folder ? folder.split(':').pop() : 'All root files', () => { ns = folder; detailEmpty(); load(0); });
                node.disabled = uploading || !values.has(folder); node.title = folder || 'Root namespace';
                actionIcon(node, expandedFolders.has(folder) ? 'folder-open' : 'folder');
                node.setAttribute('aria-current', folder === ns ? 'location' : 'false');
                const row = element('div', 'fokus-media-tree-row');
                if (data.uploadNamespaces.includes(folder)) {
                    node.addEventListener('dragover', event => {
                        if (!uploading && dragItem?.canMove && dragItem.id.split(':').slice(0,-1).join(':') !== folder) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; node.classList.add('is-drop-target'); }
                    });
                    node.addEventListener('dragleave', () => node.classList.remove('is-drop-target'));
                    node.addEventListener('drop', event => {
                        event.preventDefault(); event.stopPropagation(); node.classList.remove('is-drop-target');
                        if (dragItem && !uploading) { const item = dragItem; dragItem = null; moveFile(item, (folder ? folder + ':' : '') + item.name); }
                    });
                }
                const children = branches.get(folder) || [];
                if (children.length && folder) {
                    const group = element('div', 'fokus-media-tree-children'); group.hidden = !expandedFolders.has(folder);
                    const toggle = button(group.hidden ? 'Expand ' + folder : 'Collapse ' + folder, () => {
                        group.hidden = !group.hidden; group.hidden ? expandedFolders.delete(folder) : expandedFolders.add(folder);
                        toggle.classList.toggle('is-expanded', !group.hidden); toggle.setAttribute('aria-expanded', String(!group.hidden)); toggle.setAttribute('aria-label', (group.hidden ? 'Expand ' : 'Collapse ') + folder); toggle.title = toggle.getAttribute('aria-label');
                        node.querySelector('img').src = iconBase + (group.hidden ? 'folder' : 'folder-open') + '-duotone.svg';
                    }, 'fokus-media-tree-toggle');
                    actionIcon(toggle, 'caret-right', true); toggle.classList.toggle('is-expanded', !group.hidden); toggle.setAttribute('aria-label', (group.hidden ? 'Expand ' : 'Collapse ') + folder); toggle.setAttribute('aria-expanded', String(!group.hidden)); toggle.disabled = uploading;
                    row.append(toggle, node); host.append(row, group); children.forEach(child => renderFolder(child, group));
                } else { row.classList.add('is-leaf'); row.append(node); host.append(row); children.forEach(child => renderFolder(child, host)); }
            }
            renderFolder('', tree); aside.append(tree);
        }
        async function load(offset = 0) {
            currentOffset = offset;
            const version = ++request;
            location.textContent = ns ? ':' + ns : ': (root)';
            status.textContent = 'Loading media…'; grid.setAttribute('aria-busy', 'true');
            grid.replaceChildren(); paging.replaceChildren(); detailEmpty(); uploadButton.disabled = true;
            const url = new URL(props.ajaxUrl, window.location.href);
            Object.entries({ call: 'fokusbutler_media', ns, q: search.value, offset }).forEach(([key, value]) => url.searchParams.set(key, value));
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Could not load media.');
                if (version !== request || !dialog.open) return;
                data = result; folders();
                uploadButton.disabled = !data.canUpload || uploading;
                footer.textContent = data.canUpload ? 'Drop files here · Upload to :' + (ns || ' (root)') + ' · Maximum ' + bytes(data.maxUpload) + ' per file' : 'You can browse this namespace, but do not have upload permission.';
                status.replaceChildren(document.createTextNode(data.total ? data.total + ' file' + (data.total === 1 ? '' : 's') : search.value ? 'No matching files.' : 'No media in this namespace yet.'));
                data.items.forEach(item => {
                    const card = button('', () => pick(item, card), 'fokus-media-card'); card.disabled = uploading; card.setAttribute('aria-pressed', 'false'); card.title = item.id;
                    card.draggable = item.canMove;
                    card.addEventListener('dragstart', event => { if (uploading || !item.canMove) { event.preventDefault(); return; } dragItem = item; event.dataTransfer.setData('application/x-fokus-media', item.id); event.dataTransfer.effectAllowed = 'move'; });
                    card.addEventListener('dragend', () => { dragItem = null; aside.querySelectorAll('.is-drop-target').forEach(node => node.classList.remove('is-drop-target')); });
                    if (item.image) { const image = element('img'); image.src = item.thumbnail; image.alt = ''; image.loading = 'lazy'; card.append(image); }
                    else card.append(element('span', 'fokus-media-filetype', item.name.split('.').pop().toUpperCase()));
                    card.append(element('span', 'fokus-media-name', item.name), element('span', 'fokus-media-muted', bytes(item.size)));
                    grid.append(card);
                });
                if (offset > 0) paging.append(button('Previous', () => load(Math.max(0, offset - 80))));
                if (offset + data.items.length < data.total) paging.append(button('Next', () => load(offset + 80)));
            } catch (error) {
                if (version === request && dialog.open) status.replaceChildren(document.createTextNode(error.message + ' '), button('Retry', () => load(offset)));
            } finally { if (version === request) grid.removeAttribute('aria-busy'); }
        }
        search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(0), 200); });
        async function upload(files) {
            if (uploading || !data?.canUpload || !files.length) return;
            const destination = ns;
            uploading = true; close.disabled = true; search.disabled = true; uploadButton.disabled = true;
            aside.querySelectorAll('button').forEach(node => { node.disabled = true; });
            grid.querySelectorAll('button').forEach(node => { node.disabled = true; });
            detail.querySelectorAll('button').forEach(node => { node.disabled = true; });
            paging.querySelectorAll('button').forEach(node => { node.disabled = true; });
            clearTimeout(timer);
            const messages = []; let successes = 0;
            for (const [index, file] of [...files].entries()) {
                uploadStatus.textContent = 'Uploading ' + (index + 1) + '/' + files.length + ': ' + file.name;
                progress.hidden = false; progress.value = 0;
                try {
                    if (file.size > data.maxUpload) throw new Error('Exceeds the server upload limit.');
                    await new Promise((resolve, reject) => {
                        const xhr = new XMLHttpRequest(); xhr.open('POST', props.ajaxUrl); xhr.responseType = 'json'; xhr.timeout = 300000;
                        xhr.upload.onprogress = event => { if (event.lengthComputable) progress.value = event.loaded / event.total * 100; };
                        xhr.onerror = () => reject(new Error('Network error.')); xhr.ontimeout = () => reject(new Error('Upload timed out.'));
                        xhr.onload = () => xhr.status >= 200 && xhr.status < 300 && xhr.response?.id ? resolve(xhr.response) : reject(new Error(xhr.response?.error || 'Upload failed. Check the server upload limit.'));
                        const form = new FormData();
                        Object.entries({ call: 'fokusbutler_media', operation: 'upload', ns: destination, sectok: saved?.textarea.form?.querySelector('[name="sectok"]')?.value || props.token || '' }).forEach(([key, value]) => form.append(key, value));
                        form.append('upload', file); xhr.send(form);
                    });
                    successes++;
                } catch (error) { messages.push(file.name + ': ' + error.message); }
            }
            uploading = false; close.disabled = false; search.disabled = false; progress.hidden = true; uploadInput.value = '';
            search.value = ''; await load(0);
            uploadStatus.textContent = successes + ' uploaded.' + (messages.length ? '\n' + messages.join('\n') : '');
            if (successes) document.dispatchEvent(new CustomEvent('fokus:statuschange'));
        }
        uploadInput.addEventListener('change', () => upload(uploadInput.files));
        center.addEventListener('dragover', event => { if (event.dataTransfer.types.includes('Files')) { event.preventDefault(); event.dataTransfer.dropEffect = data?.canUpload ? 'copy' : 'none'; } });
        center.addEventListener('drop', event => { event.preventDefault(); event.stopPropagation(); upload(event.dataTransfer.files); });
        load(0);
    };
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-fokus-media-open]');
        const config = window.FokusButlerEditorConfig?.media;
        if (!opener || !config) return;
        event.preventDefault();
        const menu = opener.closest('details');
        if (menu) { menu.open = false; menu.querySelector('summary')?.focus(); }
        api.open(config);
    });
    window.addBtnActionFokusbutlermedia = function ($btn, props, edid) {
        $btn.on('click', function (event) { event.preventDefault(); api.open(props, edid); return false; });
        return true;
    };
})();
