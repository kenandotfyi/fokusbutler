const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../modules/preview/script.js'),'utf8');
const timers = new Map(); let timerId = 0;
const context = {window:{},document:{readyState:'loading',addEventListener(){}},
    setTimeout(fn){const id=++timerId;timers.set(id,fn);return id;},clearTimeout(id){timers.delete(id);}};
// Keep the production API private; expose state only within this isolated VM.
vm.runInNewContext(source.replace(/\}\)\(\);\s*$/, `window.fixture = {
    begin, open, startDrag, setDragging, windows,
    configure(value) { config = value; }, layer(value) { previewLayer = value; },
    get dragging() { return dragging; }, get pending() { return pending; }
}; })();`),context);
const api=context.window.fixture;
api.configure({openDelay:750});
const layer={hidden:false};api.layer(layer);
const sourceNode={isConnected:true,getAttribute(){return null;}};
const target={provider:'wiki',id:'test'};
api.begin(sourceNode,target);
assert.ok(api.pending); assert.equal(timers.size,1);
let removed=false;
api.windows.add({source:sourceNode,element:{remove(){removed=true;}},pinned:false});
const pinned={source:sourceNode,element:{},pinned:true};api.windows.add(pinned);
api.setDragging(true);
assert.equal(api.pending,null); assert.equal(timers.size,0);
assert.equal(removed,true); assert.equal(api.windows.size,1);assert.equal(api.windows.has(pinned),true);
assert.equal(layer.hidden,true);
api.begin(sourceNode,target);assert.equal(api.pending,null,'Hover and focus must not schedule previews while dragging');
assert.equal(api.open(sourceNode,false,target),undefined,'Explicit opens are also suppressed during a drag');
api.setDragging(false);assert.equal(layer.hidden,false);
api.windows.clear();api.begin(sourceNode,target);assert.ok(api.pending,'Preview scheduling resumes after the drag');
(async()=>{
    api.startDrag({defaultPrevented:true});await Promise.resolve();assert.equal(api.dragging,false,'Rejected dragstart restores previews without waiting for dragend');
    api.startDrag({defaultPrevented:false});await Promise.resolve();assert.equal(api.dragging,true);
    api.setDragging(false);
    console.log('PASS: drag cancels pending previews, removes transient previews, hides and preserves pinned previews, suppresses hover/focus/open, and restores after end or rejected start.');
})().catch(error=>{console.error(error);process.exitCode=1;});
