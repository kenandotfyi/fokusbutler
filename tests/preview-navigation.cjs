const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../modules/preview/script.js'), 'utf8');
const start = source.indexOf('    function scrollToFragment(');
const end = source.indexOf('\n    async function fill(', start);
const context = vm.createContext({
    URL, location: new URL('https://wiki.example/start'),
    getComputedStyle: () => ({ paddingTop: '16px' })
});
vm.runInContext(source.slice(start, end), context);

function record(hash, ids) {
    const nodes = ids.map(id => ({
        id: 'fokus-preview-2-' + id, dataset: { originalId: id },
        getBoundingClientRect: () => ({ top: 400 })
    }));
    return {
        target: { url: 'https://en.wikipedia.org/wiki/Example' + hash },
        body: {
            scrollTop: 20, clientTop: 0,
            getBoundingClientRect: () => ({ top: 100 }),
            querySelectorAll: () => nodes
        }
    };
}

for (const [hash, id] of [
    ['#History', 'History'],
    ['#Thomas%E2%80%93Fermi_model', 'Thomas–Fermi_model'],
    ['#Bad%escape', 'Bad%escape'],
    ['#Section:_(example)', 'Section:_(example)']
]) {
    const preview = record(hash, [id]);
    context.scrollToFragment(preview);
    assert.equal(preview.body.scrollTop, 304, `Failed to align ${hash} inside the preview`);
}

for (const hash of ['', '#Missing']) {
    const preview = record(hash, ['History']);
    context.scrollToFragment(preview);
    assert.equal(preview.body.scrollTop, 20, 'Missing targets must leave scrolling unchanged');
}

const wiki = record('#references', ['references']);
wiki.target.url = 'https://wiki.example/article#references';
context.scrollToFragment(wiki);
assert.equal(wiki.body.scrollTop, 304);

const first = record('#History', ['History']);
const second = record('#History', ['History']);
context.scrollToFragment(second);
assert.equal(first.body.scrollTop, 20, 'Other open previews must not move');
assert.equal(second.body.scrollTop, 304);
console.log('PASS: wiki and Wikipedia sections, scoped IDs, encoded and malformed fragments, missing targets, and independent preview scrolling.');
