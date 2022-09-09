'use strict';

(function () {
  var input = document.getElementById('report');
  var search = document.getElementById('search');
  var status = document.getElementById('status');
  var fileCount = document.getElementById('file-count');
  var overview = document.getElementById('overview');
  var directories = document.getElementById('directories');
  var allDirectories = document.getElementById('all-directories');
  var files = document.getElementById('files');
  var detailsHeading = document.getElementById('details-heading');
  var back = document.getElementById('back');
  var fileName = document.getElementById('file-name');
  var declarations = document.getElementById('declarations');
  var references = document.getElementById('references');
  var incoming = document.getElementById('incoming');
  var report = null;
  var filesById = new Map();
  var selected = null;
  var history = [];
  var directoryFilter = null;
  var request = 0;

  function valid(data) {
    if (!data || data.schemaVersion !== 0 || !data.scan || typeof data.scan.rootLabel !== 'string' ||
        (data.scan.warnings !== undefined && !Array.isArray(data.scan.warnings)) ||
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

  function showFile(id, remember) {
    var file = filesById.get(id);
    if (!file) return;
    if (remember && selected !== null && selected !== id) {
      history.push(selected);
      if (history.length > 100) history.shift();
    }
    selected = id;
    back.disabled = history.length === 0;
    fileName.textContent = file.path + ' — parse state: ' + file.parseState;
    declarations.replaceChildren();
    references.replaceChildren();
    incoming.replaceChildren();
    file.declarations.forEach(function (declaration) {
      addLine(declarations, declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line);
    });
    report.edges.forEach(function (edge) {
      if (edge.from === id) {
        var description = edge.type + ' · line ' + edge.line + ' · ' + edge.confidence + ' · ';
        if (edge.to !== null) addFileLink(references, description, edge.to);
        else addLine(references, description + edge.reasonCode);
      }
      if (edge.to === id && edge.from !== id) {
        addFileLink(incoming, edge.type + ' · line ' + edge.line + ' · ' + edge.confidence + ' · ', edge.from);
      }
    });
    if (!declarations.children.length) addLine(declarations, 'No declarations found.');
    if (!references.children.length) addLine(references, 'No outgoing references found.');
    if (!incoming.children.length) addLine(incoming, 'No incoming references found.');
    Array.prototype.forEach.call(files.querySelectorAll('button'), function (button) {
      button.setAttribute('aria-pressed', String(button.dataset.fileId === selected));
    });
  }

  function addFileLink(list, description, id) {
    var target = filesById.get(id);
    var item = document.createElement('li');
    var button = document.createElement('button');
    item.appendChild(document.createTextNode(description));
    button.type = 'button';
    button.textContent = target.path;
    button.addEventListener('click', function () {
      showFile(id, true);
      detailsHeading.focus();
    });
    item.appendChild(button);
    list.appendChild(item);
  }

  function renderDirectories() {
    var counts = new Map();
    report.nodes.forEach(function (node) {
      var directory = directoryOf(node.path);
      counts.set(directory, (counts.get(directory) || 0) + 1);
    });
    directories.replaceChildren();
    Array.from(counts.keys()).sort().forEach(function (directory) {
      var count = counts.get(directory);
      var item = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = directory + ' — ' + count + (count === 1 ? ' file' : ' files');
      button.setAttribute('aria-pressed', String(directory === directoryFilter));
      button.addEventListener('click', function () {
        directoryFilter = directory;
        renderDirectories();
        renderFiles();
        allDirectories.focus();
      });
      item.appendChild(button);
      directories.appendChild(item);
    });
    if (!counts.size) addLine(directories, 'No files in this report.');
    allDirectories.disabled = directoryFilter === null;
    overview.hidden = false;
  }

  function directoryOf(path) {
    var slash = path.indexOf('/');
    return slash === -1 ? '(root)' : path.slice(0, slash) + '/';
  }

  function renderFiles() {
    files.replaceChildren();
    var query = search.value.trim().toLowerCase();
    var matches = report.nodes.filter(function (node) {
      if (directoryFilter !== null && directoryOf(node.path) !== directoryFilter) return false;
      return node.path.toLowerCase().indexOf(query) !== -1 || node.declarations.some(function (declaration) {
        return declaration.name.toLowerCase().indexOf(query) !== -1 || declaration.kind.toLowerCase().indexOf(query) !== -1;
      });
    });
    fileCount.textContent = matches.length + ' of ' + report.nodes.length + ' files';
    matches.forEach(function (node) {
      var item = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.dataset.fileId = node.id;
      button.textContent = node.path;
      button.setAttribute('aria-pressed', String(node.id === selected));
      button.addEventListener('click', function () { showFile(node.id, true); });
      item.appendChild(button);
      if (query) {
        node.declarations.filter(function (declaration) {
          return declaration.name.toLowerCase().indexOf(query) !== -1 || declaration.kind.toLowerCase().indexOf(query) !== -1;
        }).forEach(function (declaration) {
          var match = document.createElement('div');
          match.textContent = declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line;
          item.appendChild(match);
        });
      }
      files.appendChild(item);
    });
    if (!matches.length) addLine(files, 'No matching files.');
  }

  search.addEventListener('input', function () { if (report) renderFiles(); });
  allDirectories.addEventListener('click', function () {
    directoryFilter = null;
    renderDirectories();
    renderFiles();
    overview.querySelector('summary').focus();
  });
  back.addEventListener('click', function () {
    if (!history.length) return;
    showFile(history.pop(), false);
    detailsHeading.focus();
  });
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
      filesById = new Map();
      report.nodes.forEach(function (node) { filesById.set(node.id, node); });
      selected = null;
      history = [];
      directoryFilter = null;
      back.disabled = true;
      search.disabled = false;
      search.value = '';
      fileName.textContent = 'Select a file to see its details.';
      declarations.replaceChildren();
      references.replaceChildren();
      incoming.replaceChildren();
      renderDirectories();
      renderFiles();
      var incomplete = report.nodes.filter(function (node) { return node.parseState !== 'clean'; }).length;
      var warningSummary = Array.isArray(report.scan.warnings)
        ? report.scan.warnings.length + (report.scan.warnings.length === 1 ? ' scan warning.' : ' scan warnings.')
        : 'Scan warning count unavailable.';
      status.textContent = report.scan.rootLabel + ': ' + report.nodes.length + ' files, ' + report.edges.length +
        ' references. ' + incomplete + (incomplete === 1 ? ' file' : ' files') + ' not parsed cleanly. ' + warningSummary;
    } catch (error) {
      if (currentRequest === request) status.textContent = 'Could not load report: ' + error.message;
    }
    input.value = '';
  });
}());
