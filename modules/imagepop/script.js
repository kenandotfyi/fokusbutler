(function () {
    'use strict';

    if (window.FokusButlerImagePop) return;

    let dialog = null;
    let gallery = [];
    let activeIndex = 0;
    let returnFocus = null;
    let zoomScale = 1;
    let panX = 0;
    let panY = 0;
    let dragState = null;
    const DEFAULT_IMAGE_SELECTOR = 'img.media, img.medialeft, img.mediaright, img.mediacenter';
    const imagePopConfig = typeof JSINFO !== 'undefined' && JSINFO.imagepop ? JSINFO.imagepop : {};
    const CONTENT_IMAGE_SELECTOR = typeof imagePopConfig.imageSelector === 'string'
        ? imagePopConfig.imageSelector.trim() || DEFAULT_IMAGE_SELECTOR
        : DEFAULT_IMAGE_SELECTOR;
    let invalidSelectorReported = false;

    function matchesContentImage(image) {
        if (image.closest('.fokus-wikipedia-related, .mwe-math-element, .mwe-math-fallback-image, .mwe-math-fallback-image-inline, .mwe-math-fallback-image-display')) return false;
        if (image.closest('.fokus-wikipedia-content')) return true;
        if (image.closest('.fokus-jots-markdown') && image.hasAttribute('src')) return true;
        try {
            return image.matches(CONTENT_IMAGE_SELECTOR);
        } catch (error) {
            if (!invalidSelectorReported) {
                console.warn('ImagePop: the configured image selector is invalid.', error);
                invalidSelectorReported = true;
            }
            return false;
        }
    }

    function getImageSource(image) {
        const original = window.FokusButlerContent?.wikipediaImageSource?.(image) || '';
        if (original) return original;
        const anchor = image.closest('a[href]');
        const candidates = [image.currentSrc, image.src, anchor && anchor.href].filter(Boolean);

        for (const candidate of candidates) {
            let url;
            try {
                url = new URL(candidate, window.location.href);
            } catch (error) {
                continue;
            }

            const media = url.searchParams.get('media');
            if (media) {
                const base = new URL(window.DOKU_BASE || '/', window.location.href);
                const fetchUrl = new URL('lib/exe/fetch.php', base);
                fetchUrl.searchParams.set('media', media);
                ['cache', 'tok', 'rev'].forEach((parameter) => {
                    const value = url.searchParams.get(parameter);
                    if (value) fetchUrl.searchParams.set(parameter, value);
                });
                return fetchUrl.href;
            }

            if (url.pathname.includes('/_media/')) return url.href;
        }

        return image.currentSrc || image.src;
    }

    function imageItems(root) {
        let images = [];
        try {
            images = Array.from(root.querySelectorAll(CONTENT_IMAGE_SELECTOR));
        } catch (error) {
            if (!invalidSelectorReported) {
                console.warn('ImagePop: the configured image selector is invalid.', error);
                invalidSelectorReported = true;
            }
        }
        root.querySelectorAll('.fokus-wikipedia-content img').forEach((image) => {
            if (!image.closest('.fokus-wikipedia-related')) images.push(image);
        });
        root.querySelectorAll('.fokus-jots-markdown img[src]').forEach(image => images.push(image));
        images = Array.from(new Set(images));
        return images.filter((image) => image instanceof HTMLImageElement && matchesContentImage(image)).map((image) => ({
            source: getImageSource(image),
            thumbnailSource: image.dataset.fokusThumbnailSrc || image.currentSrc || image.src,
            alt: image.getAttribute('alt') || '',
            title: image.getAttribute('title') || '',
            element: image,
        })).filter((item) => item.source);
    }

    function makeButton(className, label, iconPath) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.setAttribute('aria-label', label);
        button.title = label;
        button.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="${iconPath}"/></svg>`;
        return button;
    }

    function close() {
        if (!dialog) return;
        dialog.remove();
        dialog = null;
        gallery = [];
        activeIndex = 0;
        zoomScale = 1;
        panX = 0;
        panY = 0;
        dragState = null;
        document.documentElement.classList.remove('wiki-imagepop-open');
        if (returnFocus && returnFocus.isConnected) returnFocus.focus({ preventScroll: true });
        returnFocus = null;
    }

    function constrainPan() {
        const image = dialog && dialog.querySelector('.wiki-imagepop-image');
        const stage = dialog && dialog.querySelector('.wiki-imagepop-stage');
        if (!image || !stage) return;

        const maxX = Math.max(0, (image.clientWidth * zoomScale - stage.clientWidth) / 2);
        const maxY = Math.max(0, (image.clientHeight * zoomScale - stage.clientHeight) / 2);
        panX = Math.max(-maxX, Math.min(maxX, panX));
        panY = Math.max(-maxY, Math.min(maxY, panY));
    }

    function updateZoomUi() {
        if (!dialog) return;
        const image = dialog.querySelector('.wiki-imagepop-image');
        const scaleLabel = dialog.querySelector('.wiki-imagepop-zoom-level');
        const zoomOut = dialog.querySelector('.wiki-imagepop-zoom-out');
        const zoomIn = dialog.querySelector('.wiki-imagepop-zoom-in');
        constrainPan();
        image.style.transform = `translate3d(${panX}px, ${panY}px, 0) scale(${zoomScale})`;
        image.classList.toggle('is-zoomed', zoomScale > 1);
        image.classList.toggle('is-dragging', Boolean(dragState));
        scaleLabel.textContent = `${Math.round(zoomScale * 100)}%`;
        zoomOut.disabled = zoomScale <= 1;
        zoomIn.disabled = zoomScale >= 4;
        zoomOut.setAttribute('aria-label', `Zoom out, currently ${Math.round(zoomScale * 100)} percent`);
        zoomIn.setAttribute('aria-label', `Zoom in, currently ${Math.round(zoomScale * 100)} percent`);
        zoomOut.title = zoomOut.getAttribute('aria-label');
        zoomIn.title = zoomIn.getAttribute('aria-label');
    }

    function setZoom(nextScale, anchorX, anchorY) {
        if (!dialog) return;
        const image = dialog.querySelector('.wiki-imagepop-image');
        const stage = dialog.querySelector('.wiki-imagepop-stage');
        const rect = stage.getBoundingClientRect();
        const pointX = anchorX === undefined ? 0 : anchorX - rect.left - rect.width / 2;
        const pointY = anchorY === undefined ? 0 : anchorY - rect.top - rect.height / 2;
        const boundedScale = Math.max(1, Math.min(4, nextScale));
        const ratio = boundedScale / zoomScale;
        panX = pointX - ratio * (pointX - panX);
        panY = pointY - ratio * (pointY - panY);
        zoomScale = boundedScale;
        if (zoomScale === 1) {
            panX = 0;
            panY = 0;
        }
        updateZoomUi();
        image.focus({ preventScroll: true });
    }

    function showItem(index) {
        if (!dialog || gallery.length === 0) return;
        activeIndex = (index + gallery.length) % gallery.length;
        zoomScale = 1;
        panX = 0;
        panY = 0;
        dragState = null;

        const item = gallery[activeIndex];
        const image = dialog.querySelector('.wiki-imagepop-image');
        const caption = dialog.querySelector('.wiki-imagepop-caption');
        const counter = dialog.querySelector('.wiki-imagepop-counter');
        const thumbnailButtons = dialog.querySelectorAll('.wiki-imagepop-thumbnail');
        image.src = item.source;
        image.alt = item.alt;
        caption.textContent = item.title || item.alt;
        caption.hidden = caption.textContent === '';
        counter.textContent = `${activeIndex + 1} / ${gallery.length}`;
        thumbnailButtons.forEach((button, buttonIndex) => {
            if (buttonIndex === activeIndex) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }
        });

        const activeThumbnail = thumbnailButtons[activeIndex];
        if (activeThumbnail) {
            const strip = dialog.querySelector('.wiki-imagepop-thumbnails');
            const stripRect = strip.getBoundingClientRect();
            const thumbnailRect = activeThumbnail.getBoundingClientRect();
            strip.scrollTo({
                left: strip.scrollLeft + thumbnailRect.left - stripRect.left - (strip.clientWidth - activeThumbnail.offsetWidth) / 2,
                behavior: 'smooth',
            });
        }
        updateZoomUi();
    }

    function open(items, index, trigger) {
        if (!items.length) return;
        close();
        gallery = items;
        activeIndex = index;
        returnFocus = trigger;

        dialog = document.createElement('div');
        dialog.className = 'wiki-imagepop';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', 'Image viewer');

        const stage = document.createElement('div');
        stage.className = 'wiki-imagepop-stage';
        const image = document.createElement('img');
        image.className = 'wiki-imagepop-image';
        image.draggable = false;
        stage.appendChild(image);

        const closeButton = makeButton('wiki-imagepop-close', 'Close image viewer', 'M18.3 5.7a1 1 0 0 0-1.4 0L12 10.6 7.1 5.7a1 1 0 0 0-1.4 1.4l4.9 4.9-4.9 4.9a1 1 0 1 0 1.4 1.4l4.9-4.9 4.9 4.9a1 1 0 0 0 1.4-1.4L13.4 12l4.9-4.9a1 1 0 0 0 0-1.4Z');
        const previousButton = makeButton('wiki-imagepop-previous', 'Previous image', 'M15.5 4.5a1 1 0 0 1 0 1.4L9.4 12l6.1 6.1a1 1 0 0 1-1.4 1.4l-6.8-6.8a1 1 0 0 1 0-1.4l6.8-6.8a1 1 0 0 1 1.4 0Z');
        const nextButton = makeButton('wiki-imagepop-next', 'Next image', 'M8.5 4.5a1 1 0 0 0 0 1.4l6.1 6.1-6.1 6.1a1 1 0 0 0 1.4 1.4l6.8-6.8a1 1 0 0 0 0-1.4l-6.8-6.8a1 1 0 0 0-1.4 0Z');
        const zoomControls = document.createElement('div');
        zoomControls.className = 'wiki-imagepop-zoom-controls';
        const zoomOutButton = makeButton('wiki-imagepop-zoom-out', 'Zoom out', 'M5 11h14v2H5z');
        const zoomLevel = document.createElement('span');
        zoomLevel.className = 'wiki-imagepop-zoom-level';
        zoomLevel.setAttribute('aria-live', 'polite');
        const zoomInButton = makeButton('wiki-imagepop-zoom-in', 'Zoom in', 'M11 5h2v14h-2z M5 11h14v2H5z');
        zoomControls.append(zoomOutButton, zoomLevel, zoomInButton);
        const caption = document.createElement('div');
        caption.className = 'wiki-imagepop-caption';
        const counter = document.createElement('div');
        counter.className = 'wiki-imagepop-counter';
        const info = document.createElement('div');
        info.className = 'wiki-imagepop-info';
        info.append(caption, counter);

        const thumbnails = document.createElement('div');
        thumbnails.className = 'wiki-imagepop-thumbnails';
        thumbnails.setAttribute('aria-label', 'Images in this page');
        thumbnails.setAttribute('role', 'list');
        gallery.forEach((item, itemIndex) => {
            const thumbnailButton = document.createElement('button');
            thumbnailButton.type = 'button';
            thumbnailButton.className = 'wiki-imagepop-thumbnail';
            thumbnailButton.setAttribute('role', 'listitem');
            thumbnailButton.setAttribute('aria-label', `Show image ${itemIndex + 1}${item.title || item.alt ? `: ${item.title || item.alt}` : ''}`);
            thumbnailButton.title = item.title || item.alt || `Image ${itemIndex + 1}`;

            const thumbnailImage = document.createElement('img');
            thumbnailImage.src = item.thumbnailSource;
            thumbnailImage.alt = '';
            thumbnailImage.loading = 'lazy';
            thumbnailImage.draggable = false;
            thumbnailButton.appendChild(thumbnailImage);
            thumbnailButton.addEventListener('click', () => showItem(itemIndex));
            thumbnails.appendChild(thumbnailButton);
        });

        closeButton.addEventListener('click', close);
        previousButton.addEventListener('click', () => showItem(activeIndex - 1));
        nextButton.addEventListener('click', () => showItem(activeIndex + 1));
        zoomOutButton.addEventListener('click', () => setZoom(zoomScale / 1.5));
        zoomInButton.addEventListener('click', () => setZoom(zoomScale * 1.5));
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog || event.target === stage) close();
        });
        stage.addEventListener('wheel', (event) => {
            event.preventDefault();
            const deltaMultiplier = event.deltaMode === WheelEvent.DOM_DELTA_LINE
                ? 16
                : event.deltaMode === WheelEvent.DOM_DELTA_PAGE
                    ? stage.clientHeight
                    : 1;
            const normalizedDelta = event.deltaY * deltaMultiplier;
            const factor = Math.max(0.85, Math.min(1.15, Math.exp(-normalizedDelta * 0.0008)));
            setZoom(zoomScale * factor, event.clientX, event.clientY);
        }, { passive: false });
        image.addEventListener('pointerdown', (event) => {
            if (zoomScale <= 1 || event.button !== 0) return;
            event.preventDefault();
            dragState = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, panX, panY };
            image.setPointerCapture(event.pointerId);
            updateZoomUi();
        });
        image.addEventListener('pointermove', (event) => {
            if (!dragState || dragState.pointerId !== event.pointerId) return;
            panX = dragState.panX + event.clientX - dragState.x;
            panY = dragState.panY + event.clientY - dragState.y;
            updateZoomUi();
        });
        const stopDragging = (event) => {
            if (!dragState || dragState.pointerId !== event.pointerId) return;
            dragState = null;
            updateZoomUi();
        };
        image.addEventListener('pointerup', stopDragging);
        image.addEventListener('pointercancel', stopDragging);

        if (gallery.length > 1) {
            dialog.append(previousButton, nextButton);
        }
        dialog.append(closeButton, zoomControls, stage);
        if (gallery.length > 1) dialog.appendChild(thumbnails);
        dialog.appendChild(info);
        document.body.appendChild(dialog);
        document.documentElement.classList.add('wiki-imagepop-open');
        showItem(index);
        closeButton.focus({ preventScroll: true });
    }

    function onClick(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (dialog) return;
        const target = event.target instanceof Element ? event.target : null;
        const image = target && (target.matches('img') ? target : target.closest('img'));
        const root = image && image.closest('.page.group, .fokus-content-body');
        if (!image || !matchesContentImage(image) || !root) return;

        const items = imageItems(root);
        const index = items.findIndex((item) => item.element === image);
        if (index < 0) return;

        event.preventDefault();
        event.stopPropagation();
        open(items, index, image);
    }

    function onKeyDown(event) {
        if (!dialog) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            close();
        } else if (event.key === 'ArrowLeft' && gallery.length > 1) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            showItem(activeIndex - 1);
        } else if (event.key === 'ArrowRight' && gallery.length > 1) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            showItem(activeIndex + 1);
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            setZoom(zoomScale * 1.5);
        } else if (event.key === '-') {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            setZoom(zoomScale / 1.5);
        } else if (event.key === '0') {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            setZoom(1);
        }
    }

    document.addEventListener('click', onClick, true);
    document.addEventListener('keydown', onKeyDown, true);
    window.FokusButlerImagePop = { close };
})();
