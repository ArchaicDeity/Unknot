import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const modelSource = await readFile(new URL('../public/assets/overview-model.js', import.meta.url));
const modelUrl = 'data:text/javascript;base64,' + modelSource.toString('base64');
const { buildOverview, directoryOf, inDirectory } = await import(modelUrl);
const report = {
  scan: { warnings: [{ code: 'skipped-file' }] },
  nodes: [
    { path: 'Root.php', parseState: 'clean' },
    { path: 'A/One.php', parseState: 'failed' },
    { path: 'A/Two.php', parseState: 'clean' },
    { path: 'AB/Three.php', parseState: 'skipped' },
    { path: '(root)/Four.php', parseState: 'clean' },
  ],
  edges: [
    { confidence: 'unresolved', reasonCode: 'symbol-not-found' },
    { confidence: 'unresolved', reasonCode: 'symbol-not-found' },
    { confidence: 'unresolved', reasonCode: 'dynamic-expression' },
    { confidence: 'resolved', reasonCode: 'named-symbol' },
  ],
};
const summary = buildOverview(report);
assert.equal(summary.unresolved, 3);
assert.deepEqual(summary.parse, { clean: 3, failed: 1, skipped: 1 });
assert.deepEqual(summary.reasons, [{ code: 'symbol-not-found', count: 2 }, { code: 'dynamic-expression', count: 1 }]);
assert.equal(summary.warnings, 1);
assert.equal(summary.directories.find(item => item.directory === 'A/').count, 2);
assert.equal(directoryOf('Root.php'), '');
assert.equal(directoryOf('(root)/Four.php'), '(root)/');
assert.equal(inDirectory('A/One.php', 'A/'), true);
assert.equal(inDirectory('AB/Three.php', 'A/'), false, 'A folder filter must not match a sibling prefix.');
assert.equal(inDirectory('Root.php', ''), true);
assert.equal(inDirectory('(root)/Four.php', ''), false, 'Root files and a folder named (root) are distinct.');
assert.equal(inDirectory('AB/Three.php', null), true);

const demo = JSON.parse(await readFile(new URL('../demo/laravel-database.json', import.meta.url)));
const demoSummary = buildOverview(demo);
assert.equal(demoSummary.unresolved, 1070);
assert.deepEqual(demoSummary.reasons.map(({ code, count }) => [code, count]), [
  ['symbol-not-found', 789], ['late-static-binding', 186], ['dynamic-expression', 73], ['trait-context-dependent', 22],
]);
assert.deepEqual(demoSummary.parse, { clean: 254, failed: 0, skipped: 0 });

// Exercise the folder controller without needing to load a local source tree.
class Element {
  constructor() { this.children = []; this.attributes = {}; this.events = {}; this.textContent = ''; this.hidden = false; }
  set innerHTML(value) { throw new Error('Report labels must not be interpreted as HTML.'); }
  appendChild(child) { this.children.push(child); }
  replaceChildren() { this.children = []; }
  addEventListener(name, handler) { this.events[name] = handler; }
  setAttribute(name, value) { this.attributes[name] = value; }
  focus() { this.focused = true; }
}
globalThis.document = { createElement: () => new Element() };
const uiSource = (await readFile(new URL('../public/assets/overview.js', import.meta.url), 'utf8'))
  .replace("'./overview-model.js'", "'" + modelUrl + "'");
const { createOverviewController } = await import('data:text/javascript;base64,' + Buffer.from(uiSource).toString('base64'));
const elements = Object.fromEntries(['scope', 'all', 'directories', 'more', 'filterStatus', 'reasons'].map(id => [id, new Element()]));
let selected = 'unchanged';
const controller = createOverviewController(elements, directory => { selected = directory; controller.setDirectory(directory); });
controller.setReport(report);
assert.match(elements.scope.textContent, /5 report files: 3 parsed cleanly, 1 failed, 1 skipped; 1 diagnostic\./);
assert.match(elements.reasons.children[0].textContent, /2$/);
const folder = elements.directories.children.find(button => button.textContent.startsWith('A/'));
folder.events.click();
assert.equal(selected, 'A/');
assert.equal(folder.attributes['aria-pressed'], 'true');
elements.all.events.click();
assert.equal(selected, null);
assert.equal(elements.all.attributes['aria-pressed'], 'true');
const many = { scan: { warnings: [] }, nodes: Array.from({ length: 35 }, (_, i) => ({ path: 'Folder' + i + '/file.php', parseState: 'clean' })), edges: [] };
controller.setReport(many);
assert.equal(elements.directories.children.length, 30);
assert.equal(elements.more.hidden, false);
elements.more.events.click();
assert.equal(elements.directories.children.length, 35);
assert.equal(elements.more.hidden, true);
assert.equal(elements.reasons.children[0].textContent, 'No unresolved references recorded.');
const markupDirectory = '<img onerror=alert(1)>/';
const markupReason = '<svg onload=alert(1)>';
controller.setReport({ scan: { warnings: [] }, nodes: [{ path: markupDirectory + 'file.php', parseState: 'clean' }],
  edges: [{ confidence: 'unresolved', reasonCode: markupReason }] });
assert.ok(elements.directories.children[0].textContent.includes(markupDirectory), 'Folder names must remain literal text.');
assert.ok(elements.reasons.children[0].textContent.includes(markupReason), 'Unknown diagnostic labels must remain literal text.');
delete globalThis.document;
console.log('Report overview checks passed.');
