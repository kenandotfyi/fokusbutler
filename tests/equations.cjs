const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
const context = {window:{}, document:{currentScript:{src:'http://localhost/script.js'}}, URL};
vm.runInNewContext(fs.readFileSync(require('path').join(__dirname, '../modules/equations/script.js'),'utf8').replace('const groups = {', 'const groups = window.testGroups = {'),context);
const api=context.window.FokusButlerEquations;
for(const [text, display] of [['$a+b$',false],['$$a+b$$',true],['\\(a+b\\)',false],['\\[a+b\\]',true]]) {
 const result=api.equationAt('before '+text+' after',9,9); assert.equal(result.latex,'a+b');assert.equal(result.display,display);
 assert.equal(api.equationAt('before '+text+' after',0,3),null);
}
assert.equal(api.equationAt('cost \\$5 and text',8,8),null);
assert.equal(api.wrap('x',true,{value:'ab',start:1,end:1}),'\n$$\nx\n$$\n');
assert.equal(api.wrap('x',false,{value:'',start:0,end:0}),'$x$');
console.log('PASS: equation detection and inline/display insertion boundaries');

assert.ok(context.window.testGroups.Annotations.some(([name, latex]) => name === 'mapsto' && latex === '\\mapsto'), 'Annotations must include mapsto');

assert.ok(api.findTemplates('\\mapsto').some(item => item.title === 'mapsto'), 'LaTeX command search must ignore leading backslashes');
assert.ok(api.findTemplates('UNDERBRACE').some(item => item.title === 'Underbrace'), 'Search must ignore case');
assert.ok(api.findTemplates('closed surface').some(item => item.title === 'Closed surface integral'), 'Search must match multiple words');
assert.ok(api.findTemplates('calculus integral').length >= 8, 'Search must span categories');
assert.equal(api.findTemplates('no_such_equation_syntax').length, 0);
assert.equal(api.findTemplates(' ').length, Object.values(context.window.testGroups).reduce((count, entries) => count + entries.length, 0));
console.log('PASS: syntax search by command, title, category, multiple words, and empty results.');

// Every offered template must be valid for the installed page renderer.
const katex = require('../../katex/_assets/katex.min.js');
let templates = 0;
for (const entries of Object.values(context.window.testGroups)) {
 for (const [name, latex] of entries) {
  assert.ok(!/[\u0000-\u001f]/.test(latex), name + ' must not contain JavaScript escapes');
  katex.renderToString(latex.replace(/#(?:0|\?)/g, 'x'), {throwOnError:true, trust:false});
  templates++;
 }
}
console.log('PASS: ' + templates + ' equation templates render with the installed KaTeX.');

// Model MathLive's iframe proxy: assigning its container is illegal until
// the library mounts a keyboard in this browsing context.
(async () => {
 const iframe = {window: {}, document: {currentScript: {src:'http://localhost/script.js'}}, URL};
 let initialized = 0, imports = 0;
 iframe.window.mathVirtualKeyboard = {set container(value) {throw new Error('Container inside an iframe cannot be changed');}};
 iframe.window.importModule = async () => {
  imports++;
  return {MathfieldElement: class {}, initVirtualKeyboardInCurrentBrowsingContext() {
   initialized++; iframe.window.mathVirtualKeyboard = {container: null};
  }};
 };
 const script = fs.readFileSync(require('path').join(__dirname, '../modules/equations/script.js'),'utf8')
  .replace("import(new URL('vendor/mathlive.min.js', base).href)", 'window.importModule()')
  .replace('// Match complete supported math delimiters', 'api.loadForTest = load;\n    // Match complete supported math delimiters');
 vm.runInNewContext(script, iframe);
 await iframe.window.FokusButlerEquations.loadForTest();
 iframe.window.mathVirtualKeyboard.container = {};
 await iframe.window.FokusButlerEquations.loadForTest();
 assert.equal(initialized, 1); assert.equal(imports, 1);
 console.log('PASS: iframe keyboard mounted locally before container assignment; lazy loading reused.');
})().catch(error => { console.error(error); process.exitCode = 1; });
