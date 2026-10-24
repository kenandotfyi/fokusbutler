const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../modules/todoist/script.js'), 'utf8');
const requests = [];
const events = [];
const parent = {
    warning: null,
    querySelector() { return this.warning; },
    insertBefore(node) { this.warning = node; node.parent = this; }
};
function node() { return {
    children: [], appendChild(child) { this.children.push(child); },
    setAttribute(key, value) { this[key] = value; }, remove() { this.parent.warning = null; }
}; }
function list() { return {
    dataset: {page:'tasks'}, parentElement:parent, rows:[],
    replaceChildren(fragment) { this.rows = fragment.children.length ? fragment.children : [fragment]; }
}; }
const context = vm.createContext({
    document: {createElement: node, createDocumentFragment: node, dispatchEvent(event) { events.push(event); }},
    jQuery: {ajax(options) {
        const request = {options}; requests.push(request);
        return {done(fn) { request.done = fn; return this; }, fail(fn) { request.fail = fn; return this; }, always(fn) { request.always = fn; return this; }};
    }},
    CustomEvent: class { constructor(type) { this.type = type; } },
    ajaxUrl: () => '/ajax.php', renderIncomingTask: task => ({task})
});
vm.runInContext(source.slice(source.indexOf('    function textElement('), source.indexOf('    function taskDate(')) +
    source.slice(source.indexOf('    function loadIncomingTasks('), source.indexOf('    function scanIncoming(')), context);
function response(target, result, fail = false) {
    context.loadIncomingTasks(target);
    const request = requests.at(-1);
    fail ? request.fail({}) : request.done(result);
    request.always();
}
const target = list();
response(target, {ok:true, available:true, tasks:[{id:'remote'}], error:''});
assert.equal(target.rows[0].task.id, 'remote');
assert.equal(events.at(-1).type, 'fokus:statuschange');
context.loadIncomingTasks(target);
const before = requests.length;
context.loadIncomingTasks(target);
assert.equal(requests.length, before, 'Concurrent refreshes must share the in-flight request');
requests.at(-1).done({ok:true, available:true, tasks:[], error:''}); requests.at(-1).always();
assert.equal(target.rows[0].textContent, 'No Todoist tasks.', 'A deleted last task must become a real empty list');
response(target, {ok:true, available:true, tasks:[{id:'current'}], error:''});
response(target, null, true);
assert.equal(target.rows[0].task.id, 'current', 'Network failure must retain last displayed rows');
assert.match(parent.warning.textContent, /out of date/);
assert.equal(parent.warning.role, 'status');
response(target, {ok:false,error:'Unauthorized'});
assert.equal(parent.warning.textContent, 'Unauthorized', 'Application errors must not be silent after the first load');
response(target, {ok:true,available:false,tasks:[],error:'Refresh failed'});
assert.equal(target.rows[0].textContent, 'Todoist tasks are unavailable.', 'An unknown snapshot must not masquerade as an empty project');
response(target, {ok:true,available:true,tasks:[],error:''});
assert.equal(target.rows[0].textContent, 'No Todoist tasks.');
assert.equal(parent.warning, null, 'Recovery must remove the cached-data warning');
console.log('PASS: task deletion, empty/unavailable states, statusbar notifications, concurrent requests and visible refresh-failure recovery.');
