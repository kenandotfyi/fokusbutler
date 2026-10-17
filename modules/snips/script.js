(function () {
    'use strict';
/**
 * Custom toolbar picker that supports both icon-only and text-only entries.
 * DokuWiki's stock picker treats object entries as toolbar buttons, which do
 * not display their labels when no icon is defined.
 */
function addBtnActionFokusbutlersnips($btn, props, edid) {
    const pickerId = 'snips-picker-' + (window.pickercounter++);
    const $picker = jQuery('<div>')
        .attr('id', pickerId)
        .addClass('picker a11y snips-picker')
        .prop('hidden', true)
        .css('position', 'absolute');

    let loadTagList = null;
    if (props.tagPicker) {
        const tagPicker = props.tagPicker;
        const strings = tagPicker.strings || {};
        const $tagHeading = jQuery('<div>').addClass('snips-tag-heading');
        jQuery('<img>').attr({ src: tagPicker.icon, alt: '' }).appendTo($tagHeading);
        jQuery('<span>').text(strings.heading || 'Existing tags').appendTo($tagHeading);
        const $divider = jQuery('<div>').addClass('snips-section-divider').attr('aria-hidden', 'true');
        const $snippetsHeading = jQuery('<div>').addClass('snips-snippets-heading')
            .text(strings.snippetsHeading || 'Snippets');
        const $search = jQuery('<input>')
            .attr({
                type: 'search',
                placeholder: strings.search || 'Search existing tags…',
                'aria-label': strings.search || 'Search existing tags'
            })
            .addClass('snips-tag-search');
        const $status = jQuery('<div>').addClass('snips-tag-status').attr('role', 'status').text(strings.open || 'Open to load tags.');
        const $results = jQuery('<div>').addClass('snips-tag-results');
        const $insertSelected = jQuery('<button>')
            .attr('type', 'button')
            .attr('aria-controls', edid)
            .addClass('pickerbutton snips-tag-insert-selected')
            .prop('hidden', true)
            .prop('disabled', true);
        let tags = [];
        const selectedTags = new Set();

        function updateSelectedButton() {
            const count = selectedTags.size;
            $insertSelected
                .prop('hidden', count === 0)
                .prop('disabled', count === 0)
                .text((strings.insertSelectedTags || 'Insert selected tags') + ' (' + count + ')');
        }

        function renderTags() {
            const query = String($search.val() || '').trim().toLocaleLowerCase();
            const matches = tags.filter(function (tag) {
                return tag.label.toLocaleLowerCase().includes(query)
                    || tag.id.toLocaleLowerCase().includes(query);
            });
            $results.empty();
            if (!matches.length) {
                if (tags.length) $status.text(strings.noMatches || 'No matching tags.');
                return;
            }
            $status.text(matches.length + (matches.length === 1 ? ' tag' : ' tags'));
            matches.forEach(function (tag) {
                const $choice = jQuery('<button>')
                    .attr('type', 'button')
                    .attr('title', tag.id + ' · ' + tag.count + (tag.count === 1 ? ' page' : ' pages'))
                    .attr('aria-controls', edid)
                    .addClass('pickerbutton snips-tag-choice')
                    .toggleClass('is-selected', selectedTags.has(tag.id))
                    .attr('aria-pressed', selectedTags.has(tag.id) ? 'true' : 'false')
                    .text(tag.label);
                $choice.on('click', function (event) {
                    event.preventDefault();
                    if (selectedTags.has(tag.id)) {
                        selectedTags.delete(tag.id);
                    } else {
                        selectedTags.add(tag.id);
                    }
                    renderTags();
                    updateSelectedButton();
                    return false;
                });
                $results.append($choice);
            });
            updateSelectedButton();
        }

        function loadTags() {
            $status.text(strings.loading || 'Loading tags…');
            $results.empty();
            fetch(tagPicker.ajaxUrl, { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) throw new Error('Could not load tags.');
                    return response.json();
                })
                .then(function (data) {
                    tags = Array.isArray(data.tags) ? data.tags.filter(function (tag) {
                        return tag && typeof tag.id === 'string' && typeof tag.label === 'string';
                    }) : [];
                    if (!tags.length) {
                        $status.text(strings.empty || 'No tags are available yet.');
                        return;
                    }
                    renderTags();
                })
                .catch(function () {
                    $status.text(strings.loadError || 'Could not load tags. Close and reopen the picker to retry.');
                });
        }

        const $syntax = jQuery('<button>')
            .attr('type', 'button')
            .attr('aria-controls', edid)
            .addClass('pickerbutton snips-tag-syntax')
            .text(strings.insertSyntax || 'Insert tag syntax…')
            .on('click', function (event) {
                event.preventDefault();
                insertTags(edid, fixtxt('{{tag>'), fixtxt('}}'), fixtxt('tag'));
                pickerClose();
                return false;
            });

        $insertSelected.on('click', function (event) {
            event.preventDefault();
            if (!selectedTags.size) return false;
            insertAtCarret(edid, fixtxt('{{tag>' + Array.from(selectedTags).join(' ') + '}}'));
            pickerClose();
            return false;
        });

        $picker.append($tagHeading, $search, $syntax, $status, $results, $insertSelected, $divider, $snippetsHeading);
        $search.on('input', renderTags).on('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                pickerClose();
            }
        });
        loadTagList = loadTags;
    }

    jQuery.each(props.list || [], function (_, item) {
        const title = String(item.title || '');
        const $choice = jQuery('<button>')
            .attr('type', 'button')
            .attr('title', title)
            .attr('aria-controls', edid)
            .addClass('pickerbutton');

        if (item.icon) {
            let iconUrl = String(item.icon);
            if (iconUrl.charAt(0) !== '/') {
                iconUrl = DOKU_BASE + 'lib/images/toolbar/' + iconUrl;
            }
            jQuery('<img>').attr('src', iconUrl).attr('alt', '').appendTo($choice);
        } else {
            $choice.text(title);
        }

        $choice.on('click', function (event) {
            event.preventDefault();
            if (item.mode === 'wrap') {
                insertTags(edid, fixtxt(item.open || ''), fixtxt(item.close || ''), fixtxt(item.sample || title));
            } else {
                insertAtCarret(edid, fixtxt(item.insert || ''));
            }
            pickerClose();
            return false;
        });

        $picker.append($choice);
    });

    jQuery('body').append($picker);
    $btn.on('click', function (event) {
        event.preventDefault();
        const wasHidden = $picker.prop('hidden');
        pickerToggle(pickerId, $btn);
        if (wasHidden && loadTagList) {
            loadTagList();
            window.setTimeout(function () {
                $picker.find('.snips-tag-search').trigger('focus');
            }, 0);
        }
        return false;
    });
    return pickerId;
}

window.addBtnActionFokusbutlersnips = addBtnActionFokusbutlersnips;

})();
