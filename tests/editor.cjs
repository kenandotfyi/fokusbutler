const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../core/editor.js'), 'utf8');
const context = { window: {
    toolbar: [{type:'format', title:'Core'}, {type:'fokusbutlersnips', list:[]}, {type:'external',title:'Other plugin'}, {type:'fokusbutlertodoist'}, {type:'fokusbutlermedia'}],
    FokusButlerEditorConfig: {toolbar:[{type:'fokusbutlersnips',list:[{title:'Newly saved snippet'}]}]}
}};
vm.runInNewContext(script, context);
assert.equal(JSON.stringify(context.window.toolbar), JSON.stringify([
    {type:'format',title:'Core'}, {type:'external',title:'Other plugin'}, {type:'fokusbutlersnips',list:[{title:'Newly saved snippet'}]}
]));
vm.runInNewContext(script, context);
assert.equal(context.window.toolbar.length, 3, 'Reloading must not duplicate toolbar buttons');
context.window.FokusButlerEditorConfig.toolbar = [];
vm.runInNewContext(script, context);
assert.equal(JSON.stringify(context.window.toolbar.map(item=>item.type)), JSON.stringify(['format','external']));
console.log('PASS: fresh snippet definitions, disabled-module removal, third-party preservation, and repeat initialization.');

context.window.toolbar.push({type:'mediapopup'});
context.window.FokusButlerEditorConfig.toolbar = [{type:'fokusbutlermedia'}];
vm.runInNewContext(script, context);
assert.equal(context.window.toolbar.some(entry => entry.type === 'mediapopup'), false, 'Media Library replaces the stock editor popup');
assert.equal(context.window.toolbar.filter(entry => entry.type === 'fokusbutlermedia').length, 1);
