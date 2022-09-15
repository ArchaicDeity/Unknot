'use strict';

// Exercise the report viewer without requiring a browser or loading target code.
var assert = require('assert');
var fs = require('fs');
var vm = require('vm');
var focused = null;
var createdTags = [];

function Element() {
  this.children = [];
  this.events = {};
  this.dataset = {};
  this.attributes = {};
  this.value = '';
  this.textContent = '';
}
Element.prototype.addEventListener = function (name, handler) { this.events[name] = handler; };
Element.prototype.appendChild = function (child) { this.children.push(child); };
Element.prototype.replaceChildren = function () { this.children = []; };
Element.prototype.setAttribute = function (name, value) { this.attributes[name] = value; };
Element.prototype.focus = function () { focused = this; };
Object.defineProperty(Element.prototype, 'innerHTML', { set: function () { throw new Error('Report data must not be rendered as HTML.'); } });
Element.prototype.querySelector = function () { return this.summary; };
Element.prototype.querySelectorAll = function () {
  return this.children.map(function (item) { return item.children[0]; }).filter(Boolean);
};

var ids = ['report', 'search', 'status', 'active-report', 'file-count', 'overview', 'directories', 'all-directories',
  'files', 'details-heading', 'back', 'save-file', 'reading-heading', 'reading-list', 'reading-status',
  'file-name', 'declarations', 'methods-status', 'methods', 'calls-status', 'calls', 'references', 'incoming'];
var elements = {};
ids.forEach(function (id) { elements[id] = new Element(); });
elements.overview.summary = new Element();
var document = {
  getElementById: function (id) { return elements[id]; },
  createElement: function (tag) { createdTags.push(tag); return new Element(); },
  createTextNode: function (text) { var element = new Element(); element.textContent = text; return element; }
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../legacy-viewer/method-evidence.js', 'utf8') + '\n' +
  fs.readFileSync(__dirname + '/../legacy-viewer/viewer.js', 'utf8'), { document: document, TextEncoder: TextEncoder });

var report = {
  schemaVersion: 0, scan: { rootLabel: 'example', warnings: [{ code: 'symbol-not-found' }] },
  nodes: [
    { id: 'a', path: 'first/A.php', parseState: 'clean', declarations: [{ name: 'Demo\\Shared', kind: 'class', line: 3 }] },
    { id: 'b', path: 'second/B.php', parseState: 'clean', declarations: [{ name: 'Demo\\Shared', kind: 'class', line: 8 }] },
    { id: 'c', path: 'Broken.php', parseState: 'failed', declarations: [] }
  ],
  edges: [
    { from: 'a', to: 'b', confidence: 'heuristic', type: 'include', line: 7, reasonCode: 'source-relative-include' },
    { from: 'a', to: null, confidence: 'unresolved', type: 'instantiation', line: 9, reasonCode: 'symbol-not-found' }
  ]
};

async function load(value) {
  elements.report.files = [{ size: 100, text: async function () { return JSON.stringify(value); } }];
  await elements.report.events.change();
}

(async function () {
  await load(report);
  assert.match(elements.status.textContent, /1 file not parsed cleanly\. 1 scan warning\./);
  assert.strictEqual(elements.files.children.length, 3);

  var extended = JSON.parse(JSON.stringify(report));
  extended.extensions = { legacyMethodEvidence: { version: 1, files: report.nodes.map(function (node) {
    return { fileId: node.id, coverage: node.parseState === 'clean' ? 'available' : 'unavailable', methods: [], calls: [] };
  }) } };
  extended.extensions.legacyMethodEvidence.files[0].methods.push({ owner: 'Demo\\Shared', ownerKind: 'class', ownerLine: 3,
    name: 'run', line: 4, visibility: 'public', static: false });
  extended.extensions.legacyMethodEvidence.files[0].calls.push({ line: 5, form: 'static', caller: { owner: 'Demo\\Shared', method: 'run' },
    className: 'Demo\\Other', methodName: 'work', target: null, confidence: 'unresolved', reason: 'static-target-unresolved' });
  await load(extended);
  assert.strictEqual(elements.files.children.length, 3, 'A schema-0 extension does not change existing file exploration.');
  elements.files.children[0].children[0].events.click();
  assert.match(elements.methods.children[0].textContent, /Demo\\Shared::run · line 4/);
  assert.match(elements.calls.children[0].textContent, /Demo\\Other::work · line 5 · unresolved/);
  elements.search.value = 'run';
  elements.search.events.input();
  assert.strictEqual(elements.files.children.length, 1, 'A declared method is searchable by name.');
  assert.match(elements.files.children[0].children[1].textContent, /method Demo\\Shared::run/);
  elements.search.value = '';
  elements.search.events.input();
  var malformedExtension = JSON.parse(JSON.stringify(extended));
  malformedExtension.extensions.legacyMethodEvidence.files[0].calls[0].confidence = 'resolved';
  await load(malformedExtension);
  assert.match(elements.status.textContent, /previous report is still open/, 'Malformed method evidence must not replace a report.');
  assert.match(elements.calls.children[0].textContent, /Demo\\Other::work/, 'The previous method evidence remains visible.');
  for (var variant = 0; variant < 5; variant++) {
    malformedExtension = JSON.parse(JSON.stringify(extended));
    if (variant === 0) malformedExtension.extensions.legacyMethodEvidence.files[1].fileId = 'a';
    if (variant === 1) malformedExtension.extensions.legacyMethodEvidence.files[2].coverage = 'available';
    if (variant === 2) malformedExtension.extensions.legacyMethodEvidence.files[0].calls[0].caller.method = 'missing';
    if (variant === 3) malformedExtension.extensions.legacyMethodEvidence.files[0].calls[0].target = { fileId: 'b', owner: 'Demo\\Shared', method: 'work' };
    if (variant === 4) malformedExtension.extensions.legacyMethodEvidence.files[0].calls[0].unexpectedSource = 'untrusted';
    await load(malformedExtension);
    assert.match(elements.status.textContent, /previous report is still open/, 'Invalid coverage, identity or target must be rejected.');
  }
  var manyMethods = JSON.parse(JSON.stringify(extended));
  while (manyMethods.extensions.legacyMethodEvidence.files[0].methods.length < 101) {
    manyMethods.extensions.legacyMethodEvidence.files[0].methods.push(manyMethods.extensions.legacyMethodEvidence.files[0].methods[0]);
  }
  await load(manyMethods);
  elements.files.children[0].children[0].events.click();
  assert.strictEqual(elements.methods.children.length, 100, 'Method details remain bounded in the viewer.');
  assert.match(elements['methods-status'].textContent, /101.*first 100 shown/);
  var futureExtension = JSON.parse(JSON.stringify(extended));
  futureExtension.extensions.legacyMethodEvidence.version = 2;
  await load(futureExtension);
  elements.files.children[0].children[0].events.click();
  assert.match(elements['methods-status'].textContent, /unsupported/, 'Unknown versions remain explicitly unavailable.');
  await load(report);

  elements.search.value = 'demo\\shared';
  elements.search.events.input();
  assert.strictEqual(elements.files.children.length, 2, 'Duplicate declaration names remain separate results.');
  assert.match(elements.files.children[0].children[1].textContent, /line 3/);
  assert.match(elements.files.children[1].children[1].textContent, /line 8/);

  elements.files.children[0].children[0].events.click();
  assert.match(elements.references.children[0].children[0].textContent, /heuristic.*source-relative-include/);
  assert.match(elements.references.children[1].textContent, /unresolved.*symbol-not-found/);
  assert.strictEqual(elements.references.children[1].children.length, 0, 'An unknown target is not a link.');
  elements.references.children[0].children[1].events.click();
  assert.match(elements['file-name'].textContent, /second\/B\.php/);
  assert.strictEqual(focused, elements['details-heading'], 'Following a reference moves focus to the details heading.');
  elements.back.events.click();
  assert.match(elements['file-name'].textContent, /first\/A\.php/);
  assert.strictEqual(focused, elements['details-heading'], 'Going back returns focus to the details heading.');
  elements['save-file'].events.click();
  assert.strictEqual(focused, elements['reading-heading'], 'Saving moves focus to the reading list heading.');
  assert.strictEqual(elements['reading-list'].children[0].children[0].textContent, 'first/A.php');
  assert.strictEqual(elements['save-file'].disabled, true, 'A saved file cannot be added twice.');
  elements['reading-list'].children[0].children[2].events.click();
  assert.strictEqual(elements['save-file'].disabled, false, 'A removed file can be saved again.');
  elements['save-file'].events.click();

  elements.search.value = '';
  elements.search.events.input();
  elements.directories.children[1].children[0].events.click();
  assert.strictEqual(elements.files.children.length, 1, 'Directory filter limits the file list.');
  elements['all-directories'].events.click();
  assert.strictEqual(elements.files.children.length, 3, 'Clearing the filter restores all files.');

  await load({ schemaVersion: 9 });
  assert.match(elements.status.textContent, /Could not load report/);
  assert.match(elements.status.textContent, /previous report is still open/);
  assert.strictEqual(elements['active-report'].textContent, 'Open report: example', 'An invalid import retains the active report label.');
  assert.strictEqual(elements.files.children.length, 3, 'An invalid import preserves the prior report.');
  assert.strictEqual(elements['reading-list'].children[0].children[0].textContent, 'first/A.php', 'An invalid import preserves the reading list.');
  await load(report);
  assert.strictEqual(elements['reading-list'].children[0].textContent, 'No saved files.', 'Loading a report clears the old reading list.');
  elements.files.children[2].children[0].events.click();
  assert.match(elements.declarations.children[0].textContent, /not parsed cleanly/);
  assert.match(elements.references.children[0].textContent, /not parsed cleanly/);
  assert.match(elements.incoming.children[0].textContent, /may not have been parsed/);

  for (var schema = 1; schema <= 2; schema++) {
    await load({ schemaVersion: schema });
    assert.match(elements.status.textContent, /previous report is still open/, 'Modern schemas must not replace a schema-0 report.');
    assert.match(elements['file-name'].textContent, /Broken\.php/);
  }
  await load({ schemaVersion: 0, scan: { rootLabel: 'oversized' }, nodes: new Array(10001), edges: [] });
  assert.match(elements.status.textContent, /previous report is still open/, 'Reports above the node limit must be rejected.');

  var large = { schemaVersion: 0, scan: { rootLabel: 'large', warnings: [] }, nodes: [], edges: [] };
  for (var number = 0; number < 501; number++) {
    large.nodes.push({ id: 'large' + number, path: 'item' + number + '.php', parseState: 'clean', declarations: [] });
  }
  await load(large);
  assert.strictEqual(elements.files.children.length, 500, 'Large file lists are bounded.');
  assert.match(elements['file-count'].textContent, /501.*first 500 shown/);
  elements.search.value = 'item500.php';
  elements.search.events.input();
  assert.strictEqual(elements.files.children.length, 1, 'Searching finds files beyond the initial visible limit.');

  var many = { schemaVersion: 0, scan: { rootLabel: 'many', warnings: [] }, nodes: [], edges: [] };
  for (var n = 0; n < 101; n++) {
    many.nodes.push({ id: String(n), path: 'file' + n + '.php', parseState: 'clean', declarations: [] });
  }
  await load(many);
  for (var index = 0; index < 100; index++) {
    elements.files.children[index].children[0].events.click();
    elements['save-file'].events.click();
  }
  elements.files.children[100].children[0].events.click();
  assert.strictEqual(elements['save-file'].disabled, true, 'The reading list is limited to 100 files.');
  elements['reading-list'].children[0].children[2].events.click();
  assert.strictEqual(elements['save-file'].disabled, false, 'Removing a file makes room in the reading list.');
  await load(report);
  assert.strictEqual(elements['reading-list'].children[0].textContent, 'No saved files.', 'Replacing the report clears a full reading list.');

  var payload = '<img src=x onerror=alert(1)>.php';
  var hostile = { schemaVersion: 0, scan: { rootLabel: '<script>alert(1)</script>', warnings: [] },
    nodes: [{ id: 'unsafe', path: payload, parseState: 'clean', declarations: [{ name: '<svg onload=alert(1)>', kind: 'class', line: 1 }] }], edges: [] };
  hostile.extensions = { legacyMethodEvidence: { version: 1, files: [{ fileId: 'unsafe', coverage: 'available', calls: [],
    methods: [{ owner: '<svg onload=alert(1)>', ownerKind: 'class', ownerLine: 1, name: '<img src=x>', line: 1, visibility: 'public', static: false }] }] } };
  var tagStart = createdTags.length;
  await load(hostile);
  assert.strictEqual(elements.files.children[0].children[0].textContent, payload, 'A report filename remains literal text.');
  elements.files.children[0].children[0].events.click();
  assert.match(elements.declarations.children[0].textContent, /<svg onload=alert\(1\)>/);
  assert.match(elements.methods.children[0].textContent, /<img src=x>/, 'Method text remains inert.');
  assert.ok(!createdTags.slice(tagStart).some(function (tag) { return tag === 'img' || tag === 'script' || tag === 'svg'; }), 'Report markup must not create DOM elements.');

  var releaseOld;
  elements.report.files = [{ size: 100, text: function () { return new Promise(function (resolve) { releaseOld = resolve; }); } }];
  var olderLoad = elements.report.events.change();
  await load(report);
  releaseOld(JSON.stringify(hostile));
  await olderLoad;
  assert.strictEqual(elements.files.children.length, 3, 'A slow older import cannot replace the newer report.');
  assert.match(elements.status.textContent, /^example:/);

  console.log('LegacyLens viewer checks passed.');
}()).catch(function (error) { console.error(error); process.exitCode = 1; });
