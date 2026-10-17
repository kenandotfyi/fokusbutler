(function () {
    function init() {
        const root = document.querySelector('.fokus-settings');
        if (!root) return;
        const links = [...root.querySelectorAll('.fokus-settings-nav a')];
        const panels = [...root.querySelectorAll('.fokus-settings-section')];
        function show(id) {
            if (!panels.some(panel => panel.id === id)) id = panels[0]?.id;
            panels.forEach(panel => { panel.hidden = panel.id !== id; });
            links.forEach(link => {
                if (link.hash === '#' + id) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
        }
        links.forEach(link => link.addEventListener('click', event => {
            event.preventDefault();
            history.replaceState(null, '', link.hash);
            show(link.hash.slice(1));
        }));
        root.querySelectorAll('[data-fokus-color]').forEach(picker => {
            const text = document.getElementById(picker.dataset.fokusColor);
            picker.addEventListener('input', () => { text.value = picker.value; });
            text.addEventListener('input', () => { if (/^#[a-f0-9]{6}$/i.test(text.value)) picker.value = text.value; });
        });
        const interwikiSelector = root.querySelector('#fokus-interwiki-prefix');
        const interwikiPanels = [...root.querySelectorAll('[data-fokus-interwiki-prefix]')];
        function showInterwiki(prefix) {
            if (interwikiSelector) interwikiSelector.value = prefix;
            interwikiPanels.forEach(panel => { panel.hidden = panel.dataset.fokusInterwikiPrefix !== prefix; });
        }
        interwikiSelector?.addEventListener('change', () => showInterwiki(interwikiSelector.value));
        // Serialize a single validated configuration value, rather than posting
        // hundreds of controls and hitting PHP's input-variable limit.
        function collectInterwiki() {
            const hidden = root.querySelector('[data-fokus-interwiki-styles]');
            if (!hidden || hidden.disabled) return;
            const styles = Object.create(null);
            interwikiPanels.forEach(panel => {
                const rules = Object.create(null);
                panel.querySelectorAll('[data-fokus-interwiki-field]').forEach(wrapper => {
                    const input = wrapper.querySelector('input:not([type="color"]),select');
                    const value = input?.value.trim();
                    if (value) rules[wrapper.dataset.fokusInterwikiField] = value;
                });
                if (Object.keys(rules).length) styles[panel.dataset.fokusInterwikiPrefix] = rules;
            });
            hidden.value = JSON.stringify(styles);
        }
        // Keep the selected section after a save. The form sends all settings,
        // including other sections, and server validation is authoritative.
        const form = root.querySelector('form');
        if (form) {
            let submitting = false;
            let dirty = false;
            const status = document.createElement('span');
            status.className = 'fokus-settings-unsaved';
            status.setAttribute('role', 'status');
            status.textContent = 'Unsaved changes';
            status.hidden = true;
            root.querySelector('h1')?.after(status);

            function snapshot() {
                return [...form.querySelectorAll('input,select,textarea')].map(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') return `${input.type}:${input.checked ? 1 : 0}`;
                    return `${input.type || input.tagName}:${input.value}`;
                }).join('\u0000');
            }
            const initialState = snapshot();
            function updateDirtyState() {
                dirty = snapshot() !== initialState;
                status.hidden = !dirty;
            }
            form.addEventListener('input', updateDirtyState);
            form.addEventListener('change', updateDirtyState);
            window.addEventListener('beforeunload', event => {
                if (!dirty || submitting) return;
                event.preventDefault();
                event.returnValue = '';
            });
            form.addEventListener('submit', () => {
                collectInterwiki();
                submitting = true;
                dirty = false;
                status.hidden = true;
                form.querySelector('[name="fokus_settings_section"]').value = (location.hash || '#fokus-settings-appearance').replace('#fokus-settings-', '');
                form.action = form.action.split('#')[0] + location.hash;
            });
        }
        show(location.hash.slice(1));
        window.addEventListener('hashchange', () => show(location.hash.slice(1)));
        root.querySelectorAll('input,select').forEach(input => input.addEventListener('invalid', () => {
            const panel = input.closest('.fokus-settings-section');
            if (panel) show(panel.id);
            const interwiki = input.closest('[data-fokus-interwiki-prefix]');
            if (interwiki) showInterwiki(interwiki.dataset.fokusInterwikiPrefix);
        }));
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
    else init();
})();
