'use strict';

(function () {
  var input = document.getElementById('report');
  var search = document.getElementById('search');
  var status = document.getElementById('status');
  var fileCount = document.getElementById('file-count');
  var files = document.getElementById('files');
  var fileName = document.getElementById('file-name');
  var declarations = document.getElementById('declarations');
  var references = document.getElementById('references');
  var incoming = document.getElementById('incoming');
  var report = null;
  var selected = null;
  var request = 0;

  function valid(data) {
    if (!data || data.schemaVersion !== 0 || !data.scan || typeof data.scan.rootLabel !== 'string' ||
        !Array.isArray(data.nodes) || data.nodes.length > 10000 || !Array.isArray(data.edges) || data.edges.length > 100000) return false;
    var ids = new Set();
    for (var i = 0; i < data.nodes.length; i++) {
      var item = data.nodes[i];
      if (!item || typeof item.id !== 'string' || typeof item.path !== 'string' || ids.has(item.id) ||
          !Array.isArray(item.declarations) || typeof item.parseState !== 'string') return false;
      if (!item.declarations.every(function (declaration) {
        return declaration && typeof declaration.name === 'string' && typeof declaration.kind === 'string' &&
          Number.isInteger(declaration.line) && declaration.line > 0;
      })) return false;
      ids.add(item.id);
    }
    return data.edges.every(function (edge) {
      return edge && ids.has(edge.from) && (edge.to === null || ids.has(edge.to)) &&
        ['resolved', 'heuristic', 'unresolved'].indexOf(edge.confidence) !== -1 &&
        (edge.confidence !== 'unresolved' || edge.to === null) &&
        (edge.confidence !== 'resolved' || edge.to !== null) &&
        typeof edge.type === 'string' && Number.isInteger(edge.line) && edge.line > 0 && typeof edge.reasonCode === 'string';
    });
  }

  function addLine(list, text) {
    var item = document.createElement('li');
    item.textContent = text;
    list.appendChild(item);
  }

  function showFile(id) {
    selected = id;
    var file = report.nodes.find(function (node) { return node.id === id; });
    if (!file) return;
    fileName.textContent = file.path + ' — parse state: ' + file.parseState;
    declarations.replaceChildren();
    references.replaceChildren();
    incoming.replaceChildren();
    file.declarations.forEach(function (declaration) {
      addLine(declarations, declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line);
    });
    report.edges.forEach(function (edge) {
      if (edge.from === id) {
        addLine(references, edge.type + ' · line ' + edge.line + ' · ' + edge.confidence + ' · ' +
          (edge.to || edge.reasonCode));
      }
      if (edge.to === id && edge.from !== id) {
        addLine(incoming, edge.from + ' · ' + edge.type + ' · line ' + edge.line + ' · ' + edge.confidence);
      }
    });
    if (!declarations.children.length) addLine(declarations, 'No declarations found.');
    if (!references.children.length) addLine(references, 'No outgoing references found.');
    if (!incoming.children.length) addLine(incoming, 'No incoming references found.');
    Array.prototype.forEach.call(files.querySelectorAll('button'), function (button) {
      button.setAttribute('aria-pressed', String(button.dataset.fileId === selected));
    });
  }

  function renderFiles() {
    files.replaceChildren();
    var query = search.value.trim().toLowerCase();
    var matches = report.nodes.filter(function (node) { return node.path.toLowerCase().indexOf(query) !== -1; });
    fileCount.textContent = matches.length + ' of ' + report.nodes.length + ' files';
    matches.forEach(function (node) {
      var item = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.dataset.fileId = node.id;
      button.textContent = node.path;
      button.setAttribute('aria-pressed', String(node.id === selected));
      button.addEventListener('click', function () { showFile(node.id); });
      item.appendChild(button);
      files.appendChild(item);
    });
    if (!matches.length) addLine(files, 'No matching files.');
  }

  search.addEventListener('input', function () { if (report) renderFiles(); });
  input.addEventListener('change', async function () {
    var file = input.files[0];
    if (!file) return;
    var currentRequest = ++request;
    try {
      if (file.size > 16 * 1024 * 1024) throw new Error('Report exceeds the 16 MiB limit.');
      var candidate = JSON.parse(await file.text());
      if (!valid(candidate)) throw new Error('This is not a supported LegacyLens report.');
      if (currentRequest !== request) return;
      report = candidate;
      selected = null;
      search.disabled = false;
      search.value = '';
      fileName.textContent = 'Select a file to see its details.';
      declarations.replaceChildren();
      references.replaceChildren();
      incoming.replaceChildren();
      renderFiles();
      status.textContent = report.scan.rootLabel + ': ' + report.nodes.length + ' files, ' + report.edges.length + ' references.';
    } catch (error) {
      if (currentRequest === request) status.textContent = 'Could not load report: ' + error.message;
    }
    input.value = '';
  });
}());
