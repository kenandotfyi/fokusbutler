(function () {
    'use strict';
    const base = new URL('.', document.currentScript.src);
    const api = window.FokusButlerEquations = {};
    let library;
    function load() {
        return library ||= import(new URL('vendor/mathlive.min.mjs', base).href).then(module => {
            module.MathfieldElement.fontsDirectory = new URL('vendor/fonts', base).href;
            module.MathfieldElement.soundsDirectory = null;
            // Fokus hosts Moai in an iframe. The default iframe keyboard is a
            // parent-window proxy whose container setter deliberately throws.
            module.initVirtualKeyboardInCurrentBrowsingContext();
            return module;
        }).catch(error => { library = null; throw error; });
    }
    // Match complete supported math delimiters only; never guess at bare prose.
    api.equationAt = function (value, start, end) {
        const pattern = /\$\$([\s\S]*?)\$\$|\\\[([\s\S]*?)\\\]|\\\(([\s\S]*?)\\\)|(?<![\\$])\$(?!\$)([^\n$]*?)(?<!\\)\$(?!\$)/g;
        for (const match of value.matchAll(pattern)) {
            if (match.index > 0 && value[match.index - 1] === '\\') continue;
            const stop = match.index + match[0].length;
            if (start >= match.index && end <= stop && (start < stop || end > start)) {
                return { start: match.index, end: stop, latex: (match[1] ?? match[2] ?? match[3] ?? match[4]).trim(), display: match[1] !== undefined || match[2] !== undefined };
            }
        }
        return null;
    };
    function capture(edid) {
        const textarea = document.getElementById(edid);
        if (!textarea || textarea.disabled || textarea.readOnly) return null;
        const cm = window.plugin_codemirror?.cm;
        if (cm && cm.getTextArea() === textarea && cm.getWrapperElement().getClientRects().length) {
            if (cm.getOption('readOnly')) return null;
            return { textarea, cm, value: cm.getValue(), start: cm.indexFromPos(cm.getCursor('from')), end: cm.indexFromPos(cm.getCursor('to')), scroll: cm.getScrollInfo() };
        }
        if (!textarea.getClientRects().length) return null;
        return { textarea, value: textarea.value, start: textarea.selectionStart, end: textarea.selectionEnd, scroll: textarea.scrollTop };
    }
    function unchanged(saved) {
        const now = capture(saved.textarea.id);
        return now && saved.textarea.isConnected && now.textarea === saved.textarea && now.cm === saved.cm && now.value === saved.value;
    }
    api.wrap = function (latex, display, saved) {
        if (!display) return '$' + latex + '$';
        const before = saved.value.slice(0, saved.start), after = saved.value.slice(saved.end);
        return (before && !before.endsWith('\n') ? '\n' : '') + '$$\n' + latex + '\n$$' + (after && !after.startsWith('\n') ? '\n' : '');
    };
    function insert(saved, text) {
        const target = saved.textarea;
        if (saved.cm) {
            saved.cm.focus();
            saved.cm.setSelection(saved.cm.posFromIndex(saved.start), saved.cm.posFromIndex(saved.end));
            saved.cm.replaceSelection(text, 'end', '+input'); saved.cm.save();
            saved.cm.scrollTo(saved.scroll.left, saved.scroll.top);
        } else {
            target.focus({ preventScroll: true }); target.setSelectionRange(saved.start, saved.end);
            if (!document.execCommand('insertText', false, text)) target.setRangeText(text, saved.start, saved.end, 'end');
            target.scrollTop = saved.scroll;
        }
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const groups = {
        'Structures': [['Fraction', '\\frac{#0}{#?}'], ['Power', '#0^{#?}'], ['Subscript', '#0_{#?}'], ['Square root', '\\sqrt{#0}'], ['Nth root', '\\sqrt[#?]{#0}'], ['Parentheses', '\\left(#0\\right)'], ['Brackets', '\\left[#0\\right]'], ['Absolute value', '\\left|#0\\right|']],
        'Annotations': [
            ["phase", "\\phase{#0}"],
            ["boxed", "\\boxed{#0}"],
            ["not", "\\not #0"],
            ["therefore", "\\therefore"],
            ["because", "\\because"],
            ["braket", "\\braket{#0|#?}"],
            ["Braket", "\\Braket{#0|#?}"],
            ["checkmark", "\\checkmark"],
            ["Underbrace", "\\underbrace{#0}_{#?}"],
            ["Overbrace", "\\overbrace{#0}^{#?}"],
            ["Cancel", "\\cancel{#0}"],
            ["rightarrow", "\\rightarrow"],
            ["leftarrow", "\\leftarrow"],
            ["Rightarrow", "\\Rightarrow"],
            ["Leftarrow", "\\Leftarrow"],
            ["longrightarrow", "\\longrightarrow"],
            ["longleftarrow", "\\longleftarrow"],
            ["Longrightarrow", "\\Longrightarrow"],
            ["Longleftarrow", "\\Longleftarrow"],
            ["longleftrightarrow", "\\longleftrightarrow"],
            ["Longleftrightarrow", "\\Longleftrightarrow"],
            ["mapsto", "\\mapsto"],
            ["longmapsto", "\\longmapsto"],
            ["uparrow", "\\uparrow"],
            ["downarrow", "\\downarrow"],
            ["Uparrow", "\\Uparrow"],
            ["Downarrow", "\\Downarrow"],
            ["updownarrow", "\\updownarrow"],
            ["Updownarrow", "\\Updownarrow"],
            ["hookrightarrow", "\\hookrightarrow"],
            ["hookleftarrow", "\\hookleftarrow"],
            ["rightharpoonup", "\\rightharpoonup"],
            ["leftharpoonup", "\\leftharpoonup"],
            ["rightharpoondown", "\\rightharpoondown"],
            ["leftharpoondown", "\\leftharpoondown"],
            ["searrow", "\\searrow"],
            ["nearrow", "\\nearrow"],
            ["swarrow", "\\swarrow"],
            ["nwarrow", "\\nwarrow"],
            ["dashrightarrow", "\\dashrightarrow"],
            ["dashleftarrow", "\\dashleftarrow"],
            ["Rrightarrow", "\\Rrightarrow"],
            ["Lleftarrow", "\\Lleftarrow"],
            ["leftrightarrows", "\\leftrightarrows"],
            ["rightleftarrows", "\\rightleftarrows"],
            ["curvearrowright", "\\curvearrowright"],
            ["curvearrowleft", "\\curvearrowleft"],
            ["rightrightarrows", "\\rightrightarrows"],
            ["leftleftarrows", "\\leftleftarrows"],
            ["upuparrows", "\\upuparrows"],
            ["downdownarrows", "\\downdownarrows"],
            ["leftarrowtail", "\\leftarrowtail"],
            ["rightarrowtail", "\\rightarrowtail"],
            ["looparrowright", "\\looparrowright"],
            ["looparrowleft", "\\looparrowleft"],
            ["twoheadleftarrow", "\\twoheadleftarrow"],
            ["twoheadrightarrow", "\\twoheadrightarrow"],
            ["rightleftharpoons", "\\rightleftharpoons"],
            ["leftrightharpoons", "\\leftrightharpoons"],
            ["Rsh", "\\Rsh"],
            ["Lsh", "\\Lsh"],
            ["circlearrowright", "\\circlearrowright"],
            ["circlearrowleft", "\\circlearrowleft"],
            ["restriction", "\\restriction"],
            ["upharpoonleft", "\\upharpoonleft"],
            ["downharpoonright", "\\downharpoonright"],
            ["downharpoonleft", "\\downharpoonleft"],
            ["rightsquigarrow", "\\rightsquigarrow"],
            ["leftrightsquigarrow", "\\leftrightsquigarrow"],
            ["multimap", "\\multimap"],
            ["nleftarrow", "\\nleftarrow"],
            ["nrightarrow", "\\nrightarrow"],
            ["nRightarrow", "\\nRightarrow"],
            ["nLeftarrow", "\\nLeftarrow"],
            ["nleftrightarrow", "\\nleftrightarrow"],
            ["nLeftrightarrow", "\\nLeftrightarrow"],
            ["leftrightarrow", "\\leftrightarrow"],
            ["Leftrightarrow", "\\Leftrightarrow"],
            ["overrightarrow", "\\overrightarrow{#0}"],
            ["overleftarrow", "\\overleftarrow{#0}"],
            ["Overrightarrow", "\\Overrightarrow{#0}"],
            ["overleftharpoon", "\\overleftharpoon{#0}"],
            ["overrightharpoon", "\\overrightharpoon{#0}"],
            ["overleftrightarrow", "\\overleftrightarrow{#0}"],
            ["underrightarrow", "\\underrightarrow{#0}"],
            ["underleftarrow", "\\underleftarrow{#0}"],
            ["underleftrightarrow", "\\underleftrightarrow{#0}"],
            ["xrightarrow", "\\xrightarrow[#?]{#?}"],
            ["xleftarrow", "\\xleftarrow[#?]{#?}"],
            ["xRightarrow", "\\xRightarrow[#?]{#?}"],
            ["xLeftarrow", "\\xLeftarrow[#?]{#?}"],
            ["xleftharpoonup", "\\xleftharpoonup[#?]{#?}"],
            ["xleftharpoondown", "\\xleftharpoondown[#?]{#?}"],
            ["xrightharpoonup", "\\xrightharpoonup[#?]{#?}"],
            ["xrightharpoondown", "\\xrightharpoondown[#?]{#?}"],
            ["xtwoheadleftarrow", "\\xtwoheadleftarrow[#?]{#?}"],
            ["xtwoheadrightarrow", "\\xtwoheadrightarrow[#?]{#?}"],
            ["xleftrightarrow", "\\xleftrightarrow[#?]{#?}"],
            ["xLeftrightarrow", "\\xLeftrightarrow[#?]{#?}"],
            ["xrightleftharpoons", "\\xrightleftharpoons[#?]{#?}"],
            ["xleftrightharpoons", "\\xleftrightharpoons[#?]{#?}"],
            ["xhookleftarrow", "\\xhookleftarrow[#?]{#?}"],
            ["xhookrightarrow", "\\xhookrightarrow[#?]{#?}"],
            ["xmapsto", "\\xmapsto[#?]{#?}"],
            ["xtofrom", "\\xtofrom[#?]{#?}"]
        ],
        'Calculus': [['Integral', '\\int_{#?}^{#?}#0\\,\\mathrm{d}x'], ["Indefinite integral", "\\int #0\\,\\mathrm{d}x"], ["Double integral", "\\iint_{#?}^{#?}#0\\,\\mathrm{d}A"], ["Triple integral", "\\iiint_{#?}^{#?}#0\\,\\mathrm{d}V"], ["Contour integral", "\\oint_{#?}#0\\,\\mathrm{d}l"], ["Closed surface integral", "\\oiint_{#?}#0\\,\\mathrm{d}A"], ["Closed volume integral", "\\oiiint_{#?}#0\\,\\mathrm{d}V"], ["Integral with centered limits", "\\intop_{#?}^{#?}#0\\,\\mathrm{d}x"], ["Small integral", "\\smallint #0\\,\\mathrm{d}x"], ['Sum', '\\sum_{n=#?}^{#?}#0'], ['Product', '\\prod_{n=#?}^{#?}#0'], ['Limit', '\\lim_{x\\to #?}#0'], ['Derivative', '\\frac{\\mathrm{d}#0}{\\mathrm{d}x}'], ['Partial derivative', '\\frac{\\partial #0}{\\partial x}']],
        'Matrices': [['2 × 2 matrix', '\\begin{bmatrix}#?&#?\\\\#?&#?\\end{bmatrix}'], ['3 × 3 matrix', '\\begin{bmatrix}#?&#?&#?\\\\#?&#?&#?\\\\#?&#?&#?\\end{bmatrix}'], ['Column vector', '\\begin{pmatrix}#?\\\\#?\\\\#?\\end{pmatrix}'], ['Cases', '\\begin{cases}#?&#?\\\\#?&#?\\end{cases}']],
        'Symbols': [['α', '\\alpha'], ['β', '\\beta'], ['γ', '\\gamma'], ['δ', '\\delta'], ['ε', '\\varepsilon'], ['θ', '\\theta'], ['λ', '\\lambda'], ['μ', '\\mu'], ['π', '\\pi'], ['ρ', '\\rho'], ['σ', '\\sigma'], ['ω', '\\omega'], ['Δ', '\\Delta'], ['Ω', '\\Omega'], ['±', '\\pm'], ['×', '\\times'], ['·', '\\cdot'], ['≤', '\\leq'], ['≥', '\\geq'], ['≈', '\\approx'], ['∞', '\\infty'], ['∠', '\\angle']],
        'Functions': [['sin', '\\sin\\left(#0\\right)'], ['cos', '\\cos\\left(#0\\right)'], ['tan', '\\tan\\left(#0\\right)'], ['ln', '\\ln\\left(#0\\right)'], ['log', '\\log_{#?}\\left(#0\\right)'], ['Exponential', 'e^{#0}'], ['Overbar', '\\overline{#0}'], ['Vector', '\\vec{#0}'], ['Text / units', '\\mathrm{#0}']]
    };
    api.findTemplates = function (query) {
        const words = query.toLowerCase().replace(/\\/g, '').trim().split(/\s+/).filter(Boolean);
        return Object.entries(groups).flatMap(([category, entries]) => entries
            .filter(([title, latex]) => words.every(word => (category + ' ' + title + ' ' + latex).toLowerCase().includes(word)))
            .map(([title, latex]) => ({ category, title, latex })));
    };
    const node = (tag, text, cls) => { const el = document.createElement(tag); if (text) el.textContent = text; if (cls) el.className = cls; return el; };
    const button = (text, fn) => { const el = node('button', text); el.type = 'button'; el.addEventListener('click', fn); return el; };
    api.open = function (edid = 'wiki__text') {
        if (document.querySelector('.fokus-equations')) return;
        const saved = capture(edid);
        if (!saved) return;
        const existing = api.equationAt(saved.value, saved.start, saved.end);
        if (existing) Object.assign(saved, { start: existing.start, end: existing.end });
        const initial = existing?.latex ?? saved.value.slice(saved.start, saved.end);
        const dialog = node('dialog', '', 'fokus-equations'); dialog.setAttribute('aria-label', 'Equation builder');
        const header = node('header'); header.append(node('h2', existing ? 'Edit equation' : 'Build equation'), button('Close', () => dialog.close()));
        const body = node('div', '', 'fokus-equations-body');
        const categories = node('nav'); categories.setAttribute('aria-label', 'Equation structures');
        const search = node('input', '', 'fokus-equations-search'); search.type = 'search';
        search.placeholder = 'Find syntax…'; search.setAttribute('aria-label', 'Find equation syntax'); search.disabled = true;
        const results = node('span', '', 'fokus-equations-results'); results.setAttribute('role', 'status');
        const searchbar = node('div', '', 'fokus-equations-searchbar'); searchbar.append(search, results);
        const palette = node('div', '', 'fokus-equations-palette');
        const fieldHost = node('div', '', 'fokus-equations-field');
        const help = node('p', 'Choose a structure, then fill its slots. Use Tab to move between slots. You can also type LaTeX.');
        const details = node('details'); details.append(node('summary', 'LaTeX source'));
        const source = node('textarea'); source.setAttribute('aria-label', 'LaTeX source'); source.spellcheck = false; source.value = initial; details.append(source);
        const status = node('p', 'Loading equation editor…', 'fokus-equations-status'); status.setAttribute('role', 'status');
        const footer = node('footer'), label = node('label', 'Placement '), placement = node('select'); placement.setAttribute('aria-label', 'Equation placement');
        [['Inline', 'inline'], ['Display equation', 'display']].forEach(([text, value]) => { const option = node('option', text); option.value = value; placement.append(option); });
        placement.value = existing?.display ? 'display' : 'inline'; label.append(placement);
        let field, keyboard, previousContainer;
        const blockVirtualKeyboard = event => { if (event.detail?.visible) event.preventDefault(); };
        const submit = button(existing ? 'Update equation' : 'Insert equation', () => {
            if (!unchanged(saved)) { status.textContent = 'The page editor changed. Close and reopen the equation builder before inserting.'; return; }
            const latex = source.value.trim();
            if (!latex || /\\placeholder\b/.test(latex)) { status.textContent = 'Complete the empty equation slots before inserting.'; return; }
            // Validate against the page renderer when its API is available.
            try { if (window.katex) window.katex.renderToString(latex, { throwOnError: true, displayMode: placement.value === 'display', macros: window.JSINFO?.plugins?.katex?.options?.macros || {}, trust: false }); }
            catch (error) { status.textContent = 'The page renderer cannot display this equation: ' + error.message; return; }
            const text = api.wrap(latex, placement.value === 'display', saved);
            dialog.close(); insert(saved, text);
        }); submit.disabled = true;
        footer.append(label, button('Cancel', () => dialog.close()), submit);
        body.append(categories, searchbar, palette, help, fieldHost, details, status); dialog.append(header, body, footer); document.body.append(dialog);
        dialog.addEventListener('close', () => {
            if (keyboard) {
                keyboard.hide();
                keyboard.removeEventListener('before-virtual-keyboard-toggle', blockVirtualKeyboard);
                keyboard.container = previousContainer;
            }
            dialog.remove();
            if (saved.textarea.isConnected) (saved.cm ? saved.cm : saved.textarea).focus();
        }, { once: true });
        // Keep the host editor's global shortcuts out of the equation dialog.
        for (const type of ['keydown', 'keyup', 'keypress']) dialog.addEventListener(type, event => event.stopPropagation());
        dialog.showModal();
        load().then(module => {
            if (!dialog.isConnected) return;
            field = new module.MathfieldElement(); field.mathVirtualKeyboardPolicy = 'manual'; field.value = initial;
            field.setAttribute('aria-label', 'Visual equation');
            keyboard = window.mathVirtualKeyboard; previousContainer = keyboard.container; keyboard.container = body;
            keyboard.addEventListener('before-virtual-keyboard-toggle', blockVirtualKeyboard);
            fieldHost.append(field);
            let activeCategory = 'Structures';
            const render = () => {
                const query = search.value.trim();
                const entries = query ? api.findTemplates(query) : groups[activeCategory].map(([title, latex]) => ({category: activeCategory, title, latex}));
                palette.replaceChildren(); palette.scrollTop = 0;
                palette.classList.toggle('fokus-equations-annotations', !!query || activeCategory === 'Annotations');
                for (const el of categories.children) el.setAttribute('aria-pressed', String(!query && el.textContent === activeCategory));
                results.textContent = query ? entries.length + (entries.length === 1 ? ' match' : ' matches') : '';
                if (!entries.length) palette.append(node('p', 'No matching syntax. Try a name or LaTeX command.'));
                entries.forEach(({category, title, latex}) => {
                    const choice = button('', () => { field.focus(); field.insert(latex, { selectionMode: 'placeholder' }); });
                    choice.title = category + ': ' + title + '\n' + latex; choice.setAttribute('aria-label', title);
                    const preview = node('span');
                    preview.innerHTML = module.convertLatexToMarkup(latex.replace(/#(?:0|\?)/g, '\\square'), { displayMode: false });
                    choice.append(preview, node('span', title)); palette.append(choice);
                });
            };
            Object.keys(groups).forEach(category => categories.append(button(category, () => {
                activeCategory = category; search.value = ''; render();
            })));
            search.disabled = false;
            search.addEventListener('input', render);
            search.addEventListener('keydown', event => {
                if (event.key === 'Escape' && search.value) { event.preventDefault(); search.value = ''; render(); }
            });
            render();
            field.addEventListener('input', () => { source.value = field.value; status.textContent = ''; });
            source.addEventListener('input', () => { field.setValue(source.value, { silenceNotifications: true }); status.textContent = ''; });
            submit.disabled = false; status.textContent = ''; field.focus();
        }).catch(error => {
            console.error('Fokus equation editor:', error);
            status.textContent = 'Could not load the equation editor: ' + (error?.message || String(error));
        });
    };
    window.addBtnActionFokusbutlerequations = function ($btn, props, edid) {
        $btn.on('click', event => { event.preventDefault(); api.open(edid); return false; }); return true;
    };
})();
