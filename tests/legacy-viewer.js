'use strict';

// Exercise the report viewer without requiring a browser or loading target code.
var assert = require('assert');
var fs = require('fs');
var vm = require('vm');

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
Element.prototype.focus = function () {};
Element.prototype.querySelector = function () { return this.summary; };
Element.prototype.querySelectorAll = function () {
  return this.children.map(function (item) { return item.children[0]; }).filter(Boolean);
};

var ids = ['report', 'search', 'status', 'file-count', 'overview', 'directories', 'all-directories',
  'files', 'details-heading', 'back', 'save-file', 'reading-heading', 'reading-list', 'reading-status',
  'file-name', 'declarations', 'references', 'incoming'];
var elements = {};
ids.forEach(function (id) { elements[id] = new Element(); });
elements.overview.summary = new Element();
var document = {
  getElementById: function (id) { return elements[id]; },
  createElement: function () { return new Element(); },
  createTextNode: function (text) { var element = new Element(); element.textContent = text; return element; }
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../legacy-viewer/viewer.js', 'utf8'), { document: document });

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

  elements.search.value = 'demo\\shared';
  elements.search.events.input();
  assert.strictEqual(elements.files.children.length, 2, 'Duplicate declaration names remain separate results.');
  assert.match(elements.files.children[0].children[1].textContent, /line 3/);
  assert.match(elements.files.children[1].children[1].textContent, /line 8/);

  elements.files.children[0].children[0].events.click();
  assert.match(elements.references.children[1].textContent, /unresolved.*symbol-not-found/);
  assert.strictEqual(elements.references.children[1].children.length, 0, 'An unknown target is not a link.');
  elements.references.children[0].children[1].events.click();
  assert.match(elements['file-name'].textContent, /second\/B\.php/);
  elements.back.events.click();
  assert.match(elements['file-name'].textContent, /first\/A\.php/);
  elements['save-file'].events.click();
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
  assert.strictEqual(elements.files.children.length, 3, 'An invalid import preserves the prior report.');
  assert.strictEqual(elements['reading-list'].children[0].children[0].textContent, 'first/A.php', 'An invalid import preserves the reading list.');
  await load(report);
  assert.strictEqual(elements['reading-list'].children[0].textContent, 'No saved files.', 'Loading a report clears the old reading list.');
  console.log('LegacyLens viewer checks passed.');
}()).catch(function (error) { console.error(error); process.exitCode = 1; });
