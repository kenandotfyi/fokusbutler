const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../modules/memos/script.js'),'utf8');
let event;
const context = vm.createContext({
    snapshot:{memos:[{},{}],syncedAt:123,syncing:true,syncError:'Previous failure'},
    refreshing:null,errorMessage:'',
    document:{dispatchEvent(value){event=value;}},
    CustomEvent:class {constructor(type,options){this.type=type;this.detail=options.detail;}}
});
vm.runInContext(source.slice(source.indexOf('    function publishJotsStatus('),source.indexOf('    async function readLocal(')),context);
context.publishJotsStatus(true);
assert.equal(event.type,'fokus:jots-status');
assert.equal(event.detail.total,2);assert.equal(event.detail.syncedAt,123);
assert.equal(event.detail.syncing,true);assert.equal(event.detail.stale,false,'Retrying replaces a previous failure with an active state');
context.errorMessage='Connection failed';context.publishJotsStatus(false);
assert.equal(event.detail.syncing,false,'A persisted failed stage is not a running sync');
assert.equal(event.detail.stale,true);assert.equal(event.detail.syncedAt,123,'Failure retains the last successful sync time');
context.errorMessage='';context.snapshot={memos:[{},{},{}],syncedAt:456,syncing:false,syncError:''};context.publishJotsStatus(false);
assert.equal(event.detail.total,3);assert.equal(event.detail.syncedAt,456);assert.equal(event.detail.syncing,false);assert.equal(event.detail.stale,false);
console.log('PASS: live Memos sync start, failure and success events preserve timestamps and Jots totals.');
