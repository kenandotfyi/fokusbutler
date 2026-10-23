(function () {
    'use strict';
function addBtnActionFokusbutlertodoist($btn, props, edid) {
    $btn.on('click', function (event) {
        event.preventDefault();
        const dialog = getTaskDialog();
        dialog.dataset.editorId = edid;
        dialog.showModal();
        dialog.querySelector('[name="title"]').focus();
        return false;
    });
    return true;
}

function getTaskDialog() {
    let dialog = document.getElementById('todoistcompanion-add-dialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'todoistcompanion-add-dialog';
    dialog.className = 'todoistcompanion-add-dialog';
    dialog.setAttribute('aria-labelledby', 'todoistcompanion-add-title');
    dialog.innerHTML = '<form class="todoistcompanion-add-form">' +
        '<header class="todoistcompanion-add-header"><h2 id="todoistcompanion-add-title">Add a task</h2>' +
        '<button class="todoistcompanion-add-close" type="button" aria-label="Close dialog">×</button></header>' +
        '<label class="todoistcompanion-add-field">Task<input name="title" type="text" maxlength="500" autocomplete="off" required></label>' +
        '<fieldset class="todoistcompanion-due-fields"><legend>Due date and time <span>(optional)</span></legend>' +
        '<div class="todoistcompanion-due-inputs">' +
        '<label class="todoistcompanion-add-field">Date<input name="date" type="date"></label>' +
        '<label class="todoistcompanion-add-field">Time<input name="time" type="time" step="60"></label></div>' +
        '<p class="todoistcompanion-add-help">Choose both to set the task’s due date and time in Todoist.</p></fieldset>' +
        '<footer class="todoistcompanion-add-actions"><button class="todoistcompanion-add-cancel" type="button">Cancel</button>' +
        '<button class="todoistcompanion-add-submit" type="submit">Add task</button></footer></form>';
    document.body.appendChild(dialog);

    const form = dialog.querySelector('form');
    const date = form.elements.date;
    const time = form.elements.time;
    const today = localDateString(new Date());
    date.min = today;
    const updateTimeMinimum = function () {
        time.min = date.value === today ? localTimeString(new Date()) : '';
    };
    date.addEventListener('change', updateTimeMinimum);
    time.addEventListener('focus', updateTimeMinimum);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        date.setCustomValidity('');
        time.setCustomValidity('');
        if (!!date.value !== !!time.value) {
            const message = 'Choose both a due date and time, or leave both empty.';
            date.setCustomValidity(message);
            time.setCustomValidity(message);
            form.reportValidity();
            return;
        }
        if (date.value && new Date(date.value + 'T' + time.value) <= new Date()) {
            time.setCustomValidity('Choose a future date and time.');
            form.reportValidity();
            return;
        }

        const title = form.elements.title.value.trim().replace(/[{}|\r\n]+/g, ' ');
        if (!title) {
            form.elements.title.focus();
            return;
        }
        const alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
        const bytes = new Uint8Array(8);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i++) bytes[i] = Math.random() * 256;
        }
        const taskId = Array.from(bytes, byte => alphabet[byte % alphabet.length]).join('');
        let syntax = '{{todoistcompanion>' + title + '|id=' + taskId;
        if (date.value && time.value) {
            const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
            syntax += '|due=' + date.value + 'T' + time.value + '|tz=' + timezone;
        }
        syntax += '}}';
        insertAtCarret(dialog.dataset.editorId, fixtxt(syntax));
        form.reset();
        date.min = localDateString(new Date());
        dialog.close();
    });

    dialog.querySelector('.todoistcompanion-add-close').addEventListener('click', function () {
        dialog.close();
    });
    dialog.querySelector('.todoistcompanion-add-cancel').addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', function () {
        form.reset();
        date.min = localDateString(new Date());
    });
    return dialog;
}

function localDateString(date) {
    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

function localTimeString(date) {
    return [String(date.getHours()).padStart(2, '0'), String(date.getMinutes()).padStart(2, '0')].join(':');
}

(function () {
    function ajaxUrl(action) {
        return DOKU_BASE + 'lib/exe/ajax.php?call=fokusbutler_todoist_' + action;
    }

    function textElement(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }

    function taskDate(value, label, timezone) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}.*)?$/.test(value)) return null;
        const dateOnly = value.length === 10;
        const date = new Date(dateOnly ? value + 'T00:00:00' : value);
        if (Number.isNaN(date.getTime())) return null;
        let formatted;
        try {
            const options = { year: '2-digit', month: '2-digit', day: '2-digit', timeZone: dateOnly ? 'UTC' : (timezone || undefined) };
            if (!dateOnly) Object.assign(options, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
            const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', options).formatToParts(date).map(part => [part.type, part.value]));
            formatted = parts.year + '-' + parts.month + '-' + parts.day + (dateOnly ? '' : ' ' + parts.hour + ':' + parts.minute);
        } catch (error) { return null; }
        const time = textElement('time', 'todoistcompanion-task-date', label + ' ' + formatted);
        time.dateTime = value;
        time.title = label + ': ' + value + (timezone ? ' (' + timezone + ')' : '');
        return time;
    }

    function taskCheckboxIcon(checked) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 256 256');
        svg.setAttribute('fill', 'currentColor');
        const fill = document.createElementNS(svg.namespaceURI, 'circle');
        fill.setAttribute('cx', '128'); fill.setAttribute('cy', '128'); fill.setAttribute('r', '104'); fill.setAttribute('opacity', '0.2');
        svg.appendChild(fill);
        const ring = document.createElementNS(svg.namespaceURI, 'circle');
        ring.setAttribute('cx', '128'); ring.setAttribute('cy', '128'); ring.setAttribute('r', '96'); ring.setAttribute('fill', 'none');
        ring.setAttribute('stroke', 'currentColor'); ring.setAttribute('stroke-width', '16');
        svg.appendChild(ring);
        if (checked) {
            const check = document.createElementNS(svg.namespaceURI, 'path');
            check.setAttribute('d', 'm88 128 28 28 56-56'); check.setAttribute('fill', 'none'); check.setAttribute('stroke', 'currentColor');
            check.setAttribute('stroke-width', '16'); check.setAttribute('stroke-linecap', 'round'); check.setAttribute('stroke-linejoin', 'round');
            svg.appendChild(check);
        }
        return svg;
    }

    function todoistLogoIcon() {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'currentColor');
        const path = document.createElementNS(svg.namespaceURI, 'path');
        path.setAttribute('d', 'M21 0H3C1.35 0 0 1.35 0 3v3.858s3.854 2.24 4.098 2.38c.31.18.694.177 1.004 0 .26-.147 8.02-4.608 8.136-4.675.279-.161.58-.107.748-.01.164.097.606.348.84.48.232.134.221.502.013.622l-9.712 5.59c-.346.2-.69.204-1.048.002C3.478 10.907.998 9.463 0 8.882v2.02l4.098 2.38c.31.18.694.177 1.004 0 .26-.147 8.02-4.609 8.136-4.676.279-.16.58-.106.748-.008.164.096.606.347.84.48.232.133.221.5.013.62-.208.121-9.288 5.346-9.712 5.59-.346.2-.69.205-1.048.002C3.478 14.951.998 13.506 0 12.926v2.02l4.098 2.38c.31.18.694.177 1.004 0 .26-.147 8.02-4.609 8.136-4.676.279-.16.58-.106.748-.009.164.097.606.348.84.48.232.133.221.502.013.622l-9.712 5.59c-.346.199-.69.204-1.048.001C3.478 18.994.998 17.55 0 16.97V21c0 1.65 1.35 3 3 3h18c1.65 0 3-1.35 3-3V3c0-1.65-1.35-3-3-3z');
        svg.appendChild(path);
        return svg;
    }

    function renderIncomingTask(task, page, displayTimezone) {
        const id = String(task.id || '');
        const item = textElement('li', 'todoistcompanion-todos-item todoistcompanion-incoming-item');
        const row = textElement('div', 'todoistcompanion-task');
        row.dataset.todoistcompanionTask = task.key || '';
        row.dataset.page = page;
        const toggle = textElement('button', 'todoistcompanion-task-toggle');
        toggle.type = 'button'; toggle.setAttribute('role', 'checkbox'); toggle.setAttribute('aria-checked', 'false');
        toggle.setAttribute('aria-busy', 'true'); toggle.disabled = true; toggle.setAttribute('aria-label', 'Loading task status');
        [false, true].forEach((checked, index) => {
            const icon = textElement('span', 'todoistcompanion-task-icon ' + (index ? 'todoistcompanion-task-icon-checked' : 'todoistcompanion-task-icon-unchecked'));
            icon.setAttribute('aria-hidden', 'true');
            icon.appendChild(taskCheckboxIcon(checked)); toggle.appendChild(icon);
        });
        row.appendChild(toggle);
        const heading = textElement('span', 'todoistcompanion-task-heading');
        const detailsId = 'todoist-details-' + id.replace(/[^a-zA-Z0-9_-]/g, '');
        const expand = textElement('button', 'todoistcompanion-expand', task.content || '');
        expand.type = 'button'; expand.setAttribute('aria-expanded', 'false'); expand.setAttribute('aria-controls', detailsId);
        heading.appendChild(expand);
        if (task.wiki_page && task.wiki_page_url) {
            const pageLink = textElement('a', 'todoistcompanion-task-page wikilink1', task.wiki_page_title || task.wiki_page);
            pageLink.href = task.wiki_page_url;
            pageLink.dataset.wikiId = task.wiki_page;
            pageLink.title = task.wiki_page_title || task.wiki_page;
            heading.appendChild(pageLink);
        }
        row.appendChild(heading);
        const origin = textElement('a', 'todoistcompanion-origin');
        origin.href = 'https://app.todoist.com/app/task/' + encodeURIComponent(id);
        origin.target = '_blank'; origin.rel = 'noopener noreferrer'; origin.title = 'Added in Todoist — open task'; origin.setAttribute('aria-label', origin.title);
        origin.appendChild(todoistLogoIcon()); row.appendChild(origin);
        row.appendChild(textElement('span', 'todoistcompanion-task-status', 'Loading task status…'));
        item.appendChild(row);

        const details = textElement('div', 'todoistcompanion-task-details'); details.id = detailsId; details.hidden = true;
        const dates = [];
        const due = task.due || {};
        const dueDate = taskDate(due.datetime || due.date || '', 'Due', due.timezone);
        const addedDate = taskDate(task.added_at || '', 'Added', displayTimezone);
        if (dueDate) dates.push(dueDate); if (addedDate) dates.push(addedDate);
        if (dates.length) { const p = textElement('p', 'todoistcompanion-task-metadata'); dates.forEach((date, i) => { if (i) p.appendChild(document.createTextNode(' ')); p.appendChild(date); }); details.appendChild(p); }
        if (task.description) details.appendChild(textElement('p', 'todoistcompanion-task-description', task.description));
        const metadata = [];
        if (due.is_recurring) metadata.push('Repeats: ' + (due.string || 'Recurring'));
        if (task.deadline && task.deadline.date) metadata.push('Deadline: ' + task.deadline.date);
        (task.labels || []).forEach(label => metadata.push('@' + label));
        if (metadata.length) details.appendChild(textElement('p', 'todoistcompanion-task-metadata', metadata.join(' · ')));
        item.appendChild(details);
        return item;
    }

    function loadIncomingTasks(list) {
        if (list.dataset.loading === 'true') return;
        list.dataset.loading = 'true';
        jQuery.ajax({
            url: ajaxUrl('list'), method: 'POST', dataType: 'json', timeout: 20000,
            data: { page: list.dataset.page }
        }).done(function (result) {
            if (!result || !result.ok) {
                if (!list.dataset.signature) list.replaceChildren(textElement('li', 'todoistcompanion-todos-empty', result && result.error || 'Todoist tasks could not be loaded.'));
                return;
            }
            const tasks = result.tasks || [];
            const signature = JSON.stringify(tasks);
            if (list.dataset.signature !== signature) {
                list.dataset.signature = signature;
                const fragment = document.createDocumentFragment();
                tasks.forEach(task => fragment.appendChild(renderIncomingTask(task, list.dataset.page, result.timezone)));
                if (!tasks.length) fragment.appendChild(textElement('li', 'todoistcompanion-todos-empty', 'No Todoist tasks.'));
                list.replaceChildren(fragment);
                list.hidden = false;
            }
            let warning = list.parentElement.querySelector('.todoistcompanion-list-error');
            if (result.error) {
                if (!warning) { warning = textElement('p', 'todoistcompanion-task-error todoistcompanion-list-error'); list.parentElement.insertBefore(warning, list); }
                warning.textContent = result.error + ' The list may be out of date.';
            } else if (warning) warning.remove();
        }).fail(function (xhr) {
            if (list.dataset.signature) return;
            list.replaceChildren(textElement('li', 'todoistcompanion-todos-empty', 'Todoist tasks could not be loaded.'));
        }).always(function () { delete list.dataset.loading; });
    }

    function scanIncoming(root) {
        if (!root || root.nodeType !== 1 && root !== document) return;
        if (root.matches && root.matches('.todoistcompanion-incoming[data-page]')) loadIncomingTasks(root);
        if (root.querySelectorAll) root.querySelectorAll('.todoistcompanion-incoming[data-page]').forEach(loadIncomingTasks);
    }

    function setTaskState(element, result) {
        const $task = jQuery(element);
        const $button = $task.find('.todoistcompanion-task-toggle');
        const $status = $task.find('.todoistcompanion-task-status');
        const completed = !!result.completed;
        const synced = !!result.synced;

        $task.toggleClass('is-completed', completed);
        $button.attr('aria-checked', completed ? 'true' : 'false')
            .attr('aria-busy', 'false')
            .attr('aria-label', completed ? 'Completed task' : 'Complete task')
            .prop('disabled', completed || !synced || !result.canComplete);
        const statusText = !synced
            ? 'Waiting for Todoist sync'
            : (result.duePending ? 'Task date could not sync' : '');
        $status.removeClass('is-error').text(statusText);
    }

    function loadTaskState(element, refresh) {
        if (element.getAttribute('data-state-loading') === 'true') return;
        if (!refresh && element.getAttribute('data-state-requested') === 'true') return;
        element.setAttribute('data-state-requested', 'true');
        element.setAttribute('data-state-loading', 'true');
        const $task = jQuery(element);
        const $status = $task.find('.todoistcompanion-task-status');

        jQuery.ajax({
            url: ajaxUrl('status'),
            method: 'POST',
            dataType: 'json',
            timeout: 10000,
            data: {
                task: $task.attr('data-todoistcompanion-task'),
                page: $task.attr('data-page')
            }
        }).done(function (result) {
            if (result && result.ok) {
                setTaskState(element, result);
                return;
            }
            $task.find('.todoistcompanion-task-toggle').prop('disabled', true).attr('aria-busy', 'false');
            $status.addClass('is-error').text(result && result.error ? result.error : 'Could not load task status.');
        }).fail(function () {
            $task.find('.todoistcompanion-task-toggle').prop('disabled', true).attr('aria-busy', 'false');
            $status.addClass('is-error').text('Could not load task status. Reload to try again.');
        }).always(function () {
            element.removeAttribute('data-state-loading');
        });
    }

    function scanTasks(root) {
        if (!root || root.nodeType !== 1 && root !== document) return;
        if (root.matches && root.matches('[data-todoistcompanion-task]')) loadTaskState(root);
        if (root.querySelectorAll) {
            root.querySelectorAll('[data-todoistcompanion-task]').forEach(loadTaskState);
        }
    }

    jQuery(function () {
        scanTasks(document);
        scanIncoming(document);
        window.setInterval(function () {
            if (document.visibilityState !== 'visible') return;
            document.querySelectorAll('.todoistcompanion-incoming[data-page]').forEach(loadIncomingTasks);
            document.querySelectorAll('[data-todoistcompanion-task]').forEach(function (element) {
                loadTaskState(element, true);
            });
        }, 20000);
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) { scanTasks(node); scanIncoming(node); });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });

        jQuery(document).on('click', '.todoistcompanion-expand', function () {
            const button = this;
            const details = document.getElementById(button.getAttribute('aria-controls'));
            if (!details) return;
            const expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            details.hidden = expanded;
        });

        jQuery(document).on('click', '.todoistcompanion-task-toggle:not(:disabled)', function () {
            const $button = jQuery(this);
            const $task = $button.closest('[data-todoistcompanion-task]');
            const $status = $task.find('.todoistcompanion-task-status');
            $button.prop('disabled', true);
            $status.removeClass('is-error').text('Syncing…');
            jQuery.ajax({
                url: ajaxUrl('complete'),
                method: 'POST',
                dataType: 'json',
                timeout: 15000,
                data: {
                    task: $task.attr('data-todoistcompanion-task'),
                    page: $task.attr('data-page'),
                    sectok: (typeof JSINFO !== 'undefined' && JSINFO.todoistcompanion) ? JSINFO.todoistcompanion.token : ''
                }
            }).done(function (result) {
                if (result && result.ok) {
                    setTaskState($task[0], { ok: true, synced: true, completed: true, canComplete: false });
                    document.dispatchEvent(new CustomEvent('fokus:statuschange'));
                    return;
                }
                $button.prop('disabled', false);
                $status.addClass('is-error').text(result && result.error ? result.error : 'Todoist sync failed.');
            }).fail(function (xhr, status) {
                $button.prop('disabled', false);
                const message = status === 'timeout'
                    ? 'Todoist did not respond in time. Try again.'
                    : (xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Could not sync with Todoist.');
                $status.addClass('is-error').text(message);
            });
        });
    });
}());

window.addBtnActionFokusbutlertodoist = addBtnActionFokusbutlertodoist;

})();
