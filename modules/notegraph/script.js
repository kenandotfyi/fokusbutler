(function () {
    'use strict';

    const config = window.FokusButlerNoteGraphConfig;
    if (!config || window.FokusButlerNoteGraph) return;

    const NS = 'http://www.w3.org/2000/svg';
    let dialog = null;
    let triggerToRestore = null;
    let graphState = null;
    let resizeObserver = null;

    function svgElement(name, attributes = {}) {
        const element = document.createElementNS(NS, name);
        Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));
        return element;
    }

    function makeButton(label, text, className = '') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = text;
        button.title = label;
        button.setAttribute('aria-label', label);
        return button;
    }

    function shortTitle(title, length = 22) {
        return title.length > length ? `${title.slice(0, length - 1)}…` : title;
    }

    function createTrigger(actions) {
        if (actions.querySelector('.notegraph-trigger')) return;
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'notegraph-trigger';
        trigger.title = 'Open page connections graph';
        trigger.setAttribute('aria-label', 'Open page connections graph');
        trigger.innerHTML = '<svg class="phosphor-duotone" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><path d="M152 128a24 24 0 1 1-24-24a24 24 0 0 1 24 24" opacity="0.2"/><path d="M200 152a31.84 31.84 0 0 0-19.53 6.68l-23.11-18A31.65 31.65 0 0 0 160 128c0-.74 0-1.48-.08-2.21l13.23-4.41A32 32 0 1 0 168 104c0 .74 0 1.48.08 2.21l-13.23 4.41A32 32 0 0 0 128 96a32.6 32.6 0 0 0-5.27.44L115.89 81A32 32 0 1 0 96 88a32.6 32.6 0 0 0 5.27-.44l6.84 15.4a31.92 31.92 0 0 0-8.57 39.64l-25.71 22.84a32.06 32.06 0 1 0 10.63 12l25.71-22.84a31.91 31.91 0 0 0 37.36-1.24l23.11 18A31.65 31.65 0 0 0 168 184a32 32 0 1 0 32-32m0-64a16 16 0 1 1-16 16a16 16 0 0 1 16-16M80 56a16 16 0 1 1 16 16a16 16 0 0 1-16-16M56 208a16 16 0 1 1 16-16a16 16 0 0 1-16 16m56-80a16 16 0 1 1 16 16a16 16 0 0 1-16-16m88 72a16 16 0 1 1 16-16a16 16 0 0 1-16 16"/></svg>';
        trigger.addEventListener('click', () => {
            const pane = trigger.closest('.infinitepanels-pane');
            const pageId = pane?.dataset.pageId || config.currentPageId;
            if (pageId) openGraph(pageId, trigger);
        });
        actions.prepend(trigger);
    }

    function scanForTriggers(root = document) {
        const headers = [];
        if (root instanceof Element && root.matches('.topBanner, .infinitepanels-pane-toolbar')) headers.push(root);
        root.querySelectorAll?.('.topBanner, .infinitepanels-pane-toolbar').forEach((header) => headers.push(header));

        headers.forEach((header) => {
            if (header.closest('.infinitepanels-popup-pane')) return;
            const provider = header.closest('.infinitepanels-pane')?.dataset.provider;
            if (provider && provider !== 'wiki') return;
            let actions = header.querySelector('.infinitepanels-actions');
            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'infinitepanels-actions notegraph-actions';
                const rightSide = header.querySelector('.infinitepanels-header-right');
                (rightSide || header).appendChild(actions);
            }
            createTrigger(actions);
        });
    }

    function makeDialog(pageId, trigger) {
        dialog = document.createElement('div');
        dialog.className = 'notegraph-backdrop';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', `Connections graph for ${pageId}`);

        const windowElement = document.createElement('section');
        windowElement.className = 'notegraph-window';
        const header = document.createElement('header');
        header.className = 'notegraph-header';

        const heading = document.createElement('div');
        heading.className = 'notegraph-heading';
        const title = document.createElement('strong');
        title.textContent = `Connections · ${pageId}`;
        heading.appendChild(title);

        const toolbar = document.createElement('div');
        toolbar.className = 'notegraph-toolbar';
        const depthDown = makeButton('Decrease graph depth', '−');
        depthDown.className = 'notegraph-depth-down';
        const depthLabel = document.createElement('span');
        depthLabel.className = 'notegraph-depth-label';
        depthLabel.setAttribute('aria-live', 'polite');
        const depthUp = makeButton('Increase graph depth', '+');
        depthUp.className = 'notegraph-depth-up';
        const zoomOut = makeButton('Zoom out', '−', 'notegraph-zoom-out');
        const zoomIn = makeButton('Zoom in', '+', 'notegraph-zoom-in');
        const fit = makeButton('Fit graph to view', 'Fit');
        const closeButton = makeButton('Close graph', '×', 'notegraph-close');
        toolbar.append(depthDown, depthLabel, depthUp, zoomOut, zoomIn, fit, closeButton);
        header.append(heading, toolbar);

        const status = document.createElement('div');
        status.className = 'notegraph-status';
        status.textContent = 'Loading connections…';

        const stage = document.createElement('div');
        stage.className = 'notegraph-stage';
        const svg = svgElement('svg', { role: 'img', 'aria-label': `Page connection graph for ${pageId}` });
        stage.appendChild(svg);

        const footer = document.createElement('div');
        footer.className = 'notegraph-footer';
        footer.textContent = 'Drag the background to pan · Scroll to zoom · Select a page to open it in a pane';

        windowElement.append(header, status, stage, footer);
        dialog.appendChild(windowElement);
        document.body.appendChild(dialog);
        triggerToRestore = trigger;

        closeButton.addEventListener('click', closeGraph);
        fit.addEventListener('click', fitGraph);
        zoomIn.addEventListener('click', () => zoomGraph(1.25));
        zoomOut.addEventListener('click', () => zoomGraph(0.8));
        depthDown.addEventListener('click', () => changeDepth(-1));
        depthUp.addEventListener('click', () => changeDepth(1));
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) closeGraph();
        });

        graphState = {
            pageId,
            depth: 1,
            maxDepth: Math.max(1, Number(config.maxDepth) || 3),
            data: null,
            requestVersion: 0,
            svg,
            stage,
            status,
            depthLabel,
            scene: null,
            viewWidth: 0,
            viewHeight: 0,
            worldWidth: 0,
            worldHeight: 0,
            scale: 1,
            tx: 0,
            ty: 0,
            panStart: null,
        };
        depthDown.disabled = true;
        depthUp.disabled = graphState.depth >= graphState.maxDepth;
        updateDepthLabel();
        setupPanZoom(svg);
        resizeObserver = new ResizeObserver(() => {
            if (graphState?.data) renderGraph(graphState.data, true);
        });
        resizeObserver.observe(stage);
        closeButton.focus({ preventScroll: true });
    }

    function updateDepthLabel() {
        if (!graphState) return;
        graphState.depthLabel.textContent = `Depth ${graphState.depth}`;
        dialog.querySelector('.notegraph-depth-down').disabled = graphState.depth <= 1;
        dialog.querySelector('.notegraph-depth-up').disabled = graphState.depth >= graphState.maxDepth;
    }

    async function loadGraph(depth) {
        if (!graphState) return;
        const requestVersion = ++graphState.requestVersion;
        graphState.depth = Math.max(1, Math.min(graphState.maxDepth, depth));
        updateDepthLabel();
        graphState.status.textContent = 'Loading connections…';
        graphState.svg.replaceChildren();

        const url = new URL(config.ajaxUrl, window.location.href);
        url.searchParams.set('id', graphState.pageId);
        url.searchParams.set('depth', String(graphState.depth));
        try {
            const response = await fetch(url.href, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`Graph request failed (${response.status})`);
            const data = await response.json();
            if (!graphState || requestVersion !== graphState.requestVersion) return;
            graphState.data = data;
            renderGraph(data, true);
            graphState.status.textContent = data.truncated
                ? `Showing up to ${data.nodes.length} pages; some connections were omitted to keep the graph responsive.`
                : `${data.nodes.length} page${data.nodes.length === 1 ? '' : 's'} · ${data.links.length} connection${data.links.length === 1 ? '' : 's'}`;
        } catch (error) {
            if (!graphState || requestVersion !== graphState.requestVersion) return;
            graphState.status.textContent = 'Could not load this graph. Check that you can read the selected page.';
            console.error('NoteGraph: graph request failed', error);
        }
    }

    function changeDepth(amount) {
        if (!graphState) return;
        const nextDepth = Math.max(1, Math.min(graphState.maxDepth, graphState.depth + amount));
        if (nextDepth !== graphState.depth) loadGraph(nextDepth);
    }

    function openGraph(pageId, trigger) {
        if (dialog) closeGraph();
        makeDialog(pageId, trigger);
        loadGraph(1);
    }

    function closeGraph() {
        if (!dialog) return;
        resizeObserver?.disconnect();
        resizeObserver = null;
        dialog.remove();
        dialog = null;
        graphState = null;
        const trigger = triggerToRestore;
        triggerToRestore = null;
        if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    }

    function layoutGraph(data, width, height) {
        const levels = new Map();
        data.nodes.forEach((node) => {
            if (!levels.has(node.depth)) levels.set(node.depth, []);
            levels.get(node.depth).push(node);
        });

        let maxRadius = 0;
        levels.forEach((nodes, depth) => {
            if (depth === 0) return;
            const radius = Math.max(175 * depth, (nodes.length * 66) / (2 * Math.PI));
            maxRadius = Math.max(maxRadius, radius);
        });
        const worldWidth = Math.max(width, 2 * (maxRadius + 115));
        const worldHeight = Math.max(height, 2 * (maxRadius + 100));
        const centerX = worldWidth / 2;
        const centerY = worldHeight / 2;
        const positions = new Map();

        levels.forEach((nodes, depth) => {
            if (depth === 0) {
                nodes.forEach((node) => positions.set(node.id, { x: centerX, y: centerY }));
                return;
            }
            const radius = Math.max(175 * depth, (nodes.length * 66) / (2 * Math.PI));
            nodes.forEach((node, index) => {
                const angle = -Math.PI / 2 + (2 * Math.PI * index) / nodes.length;
                positions.set(node.id, {
                    x: centerX + Math.cos(angle) * radius,
                    y: centerY + Math.sin(angle) * radius,
                });
            });
        });

        return { positions, worldWidth, worldHeight, centerX, centerY };
    }

    function renderGraph(data, shouldFit = false) {
        if (!graphState) return;
        const { svg, stage } = graphState;
        const width = Math.max(320, stage.clientWidth);
        const height = Math.max(300, stage.clientHeight);
        graphState.viewWidth = width;
        graphState.viewHeight = height;
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.replaceChildren();

        const defs = svgElement('defs');
        const marker = svgElement('marker', {
            id: 'notegraph-arrow', viewBox: '0 0 10 10', refX: '10', refY: '5',
            markerWidth: '10', markerHeight: '10', markerUnits: 'userSpaceOnUse', orient: 'auto',
        });
        marker.appendChild(svgElement('path', { d: 'M 0 0 L 10 5 L 0 10 z', class: 'notegraph-arrowhead' }));
        defs.appendChild(marker);
        svg.appendChild(defs);

        const layout = layoutGraph(data, width, height);
        graphState.worldWidth = layout.worldWidth;
        graphState.worldHeight = layout.worldHeight;
        const scene = svgElement('g', { class: 'notegraph-scene' });
        graphState.scene = scene;
        svg.appendChild(scene);

        const directedLinks = new Set(data.links.map((link) => `${link.source}\0${link.target}`));
        const nodesById = new Map(data.nodes.map((node) => [node.id, node]));
        const trimPoint = (from, toward, distance) => {
            const dx = toward.x - from.x;
            const dy = toward.y - from.y;
            const length = Math.max(1, Math.hypot(dx, dy));
            const offset = Math.min(distance, length * 0.4);
            return { x: from.x + (dx / length) * offset, y: from.y + (dy / length) * offset };
        };
        data.links.forEach((link) => {
            const source = layout.positions.get(link.source);
            const target = layout.positions.get(link.target);
            if (!source || !target) return;
            const sourceNode = nodesById.get(link.source);
            const targetNode = nodesById.get(link.target);
            if (!sourceNode || !targetNode) return;
            const sourceRadius = sourceNode.depth === 0 ? 24 : 17;
            const targetRadius = targetNode.depth === 0 ? 24 : 17;
            const reverse = directedLinks.has(`${link.target}\0${link.source}`);
            let pathData;
            if (reverse) {
                const dx = target.x - source.x;
                const dy = target.y - source.y;
                const distance = Math.max(1, Math.hypot(dx, dy));
                const direction = link.source < link.target ? 1 : -1;
                const offset = 13 * direction;
                const midX = (source.x + target.x) / 2 - (dy / distance) * offset;
                const midY = (source.y + target.y) / 2 + (dx / distance) * offset;
                const control = { x: midX, y: midY };
                const start = trimPoint(source, control, sourceRadius + 1);
                const end = trimPoint(target, control, targetRadius + 2);
                pathData = `M${start.x},${start.y} Q${midX},${midY} ${end.x},${end.y}`;
            } else {
                const start = trimPoint(source, target, sourceRadius + 1);
                const end = trimPoint(target, source, targetRadius + 2);
                pathData = `M${start.x},${start.y} L${end.x},${end.y}`;
            }
            scene.appendChild(svgElement('path', { d: pathData, class: 'notegraph-link', 'marker-end': 'url(#notegraph-arrow)' }));
        });

        data.nodes.forEach((node) => {
            const point = layout.positions.get(node.id);
            if (!point) return;
            const group = svgElement('g', {
                class: `notegraph-node${node.depth === 0 ? ' is-root' : ''}`,
                transform: `translate(${point.x} ${point.y})`,
                tabindex: '0',
                role: 'button',
                'aria-label': `${node.title}, ${node.id}`,
            });
            const title = svgElement('title');
            title.textContent = `${node.title}\n${node.id}`;
            group.appendChild(title);
            group.appendChild(svgElement('circle', { r: node.depth === 0 ? '24' : '17' }));
            const label = svgElement('text', { y: node.depth === 0 ? '42' : '34' });
            label.textContent = shortTitle(node.title);
            group.appendChild(label);
            const activate = () => openPageInPane(node);
            group.addEventListener('click', activate);
            group.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                activate();
            });
            group.addEventListener('pointerdown', (event) => event.stopPropagation());
            scene.appendChild(group);
        });

        if (shouldFit || !graphState.scale) fitGraph();
        else applyTransform();
    }

    function applyTransform() {
        if (!graphState?.scene) return;
        graphState.scene.setAttribute('transform', `translate(${graphState.tx} ${graphState.ty}) scale(${graphState.scale})`);
    }

    function fitGraph() {
        if (!graphState || !graphState.scene) return;
        const scale = Math.min(
            graphState.viewWidth / graphState.worldWidth,
            graphState.viewHeight / graphState.worldHeight
        ) * 0.9;
        graphState.scale = scale;
        graphState.tx = (graphState.viewWidth - graphState.worldWidth * scale) / 2;
        graphState.ty = (graphState.viewHeight - graphState.worldHeight * scale) / 2;
        applyTransform();
    }

    function svgPoint(event) {
        const point = graphState.svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        return point.matrixTransform(graphState.svg.getScreenCTM().inverse());
    }

    function zoomAt(point, factor) {
        if (!graphState) return;
        const nextScale = Math.max(0.25, Math.min(3, graphState.scale * factor));
        const ratio = nextScale / graphState.scale;
        graphState.tx = point.x - (point.x - graphState.tx) * ratio;
        graphState.ty = point.y - (point.y - graphState.ty) * ratio;
        graphState.scale = nextScale;
        applyTransform();
    }

    function zoomGraph(factor) {
        if (!graphState) return;
        zoomAt({ x: graphState.viewWidth / 2, y: graphState.viewHeight / 2 }, factor);
    }

    function setupPanZoom(svg) {
        svg.addEventListener('wheel', (event) => {
            event.preventDefault();
            const factor = Math.exp(-event.deltaY * 0.001);
            zoomAt(svgPoint(event), factor);
        }, { passive: false });
        svg.addEventListener('pointerdown', (event) => {
            if (event.target.closest?.('.notegraph-node') || event.button !== 0) return;
            const point = svgPoint(event);
            graphState.panStart = { x: point.x, y: point.y, tx: graphState.tx, ty: graphState.ty };
            svg.setPointerCapture(event.pointerId);
            svg.classList.add('is-panning');
        });
        svg.addEventListener('pointermove', (event) => {
            if (!graphState?.panStart) return;
            const point = svgPoint(event);
            graphState.tx = graphState.panStart.tx + point.x - graphState.panStart.x;
            graphState.ty = graphState.panStart.ty + point.y - graphState.panStart.y;
            applyTransform();
        });
        const endPan = () => {
            if (!graphState) return;
            graphState.panStart = null;
            svg.classList.remove('is-panning');
        };
        svg.addEventListener('pointerup', endPan);
        svg.addEventListener('pointercancel', endPan);
    }

    function openPageInPane(node) {
        closeGraph();
        if (typeof window.infinitepanelsOpenPage === 'function') {
            window.infinitepanelsOpenPage(node.url, true, node.id);
        } else {
            window.location.href = node.url;
        }
    }

    function onKeyDown(event) {
        if (!dialog) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            zoomGraph(1.2);
        } else if (event.key === '-') {
            event.preventDefault();
            zoomGraph(0.83);
        } else if (event.key === '0') {
            event.preventDefault();
            fitGraph();
        } else {
            return;
        }
        if (event.key === 'Escape') closeGraph();
    }

    document.addEventListener('keydown', onKeyDown, true);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => scanForTriggers(), { once: true });
    } else {
        scanForTriggers();
    }
    new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node instanceof Element) scanForTriggers(node);
        }));
    }).observe(document.body, { childList: true, subtree: true });

    window.FokusButlerNoteGraph = { open: openGraph };
})();
