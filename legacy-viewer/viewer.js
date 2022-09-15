'use strict';

(function () {
  var input = document.getElementById('report');
  var search = document.getElementById('search');
  var status = document.getElementById('status');
  var activeReport = document.getElementById('active-report');
  var fileCount = document.getElementById('file-count');
  var overview = document.getElementById('overview');
  var directories = document.getElementById('directories');
  var allDirectories = document.getElementById('all-directories');
  var files = document.getElementById('files');
  var detailsHeading = document.getElementById('details-heading');
  var back = document.getElementById('back');
  var saveFile = document.getElementById('save-file');
  var readingList = document.getElementById('reading-list');
  var readingStatus = document.getElementById('reading-status');
  var fileName = document.getElementById('file-name');
  var declarations = document.getElementById('declarations');
  var methodsStatus = document.getElementById('methods-status');
  var methods = document.getElementById('methods');
  var callsStatus = document.getElementById('calls-status');
  var calls = document.getElementById('calls');
  var references = document.getElementById('references');
  var incoming = document.getElementById('incoming');
  var report = null;
  var methodEvidence = { state: 'unavailable', files: new Map() };
  var filesById = new Map();
  var selected = null;
  var history = [];
  var saved = [];
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

  function showMethodEvidence(id) {
    methods.replaceChildren();
    calls.replaceChildren();
    var entry = methodEvidence.files.get(id);
    if (!entry || entry.coverage !== 'available') {
      methodsStatus.textContent = methodEvidence.state === 'unsupported'
        ? 'This report uses an unsupported method-evidence version.'
        : 'Method evidence is unavailable for this file.';
      callsStatus.textContent = 'Call-expression evidence is unavailable for this file.';
      return;
    }
    methodsStatus.textContent = entry.methods.length + ' directly declared methods' + (entry.methods.length > 100 ? ' (first 100 shown).' : '.');
    callsStatus.textContent = entry.calls.length + ' static call expressions' + (entry.calls.length > 100 ? ' (first 100 shown).' : '.') +
      ' These are source expressions, not proof that a method ran.';
    entry.methods.slice(0, 100).forEach(function (method) {
      addLine(methods, method.visibility + (method.static ? ' static ' : ' ') + method.owner + '::' + method.name + ' · line ' + method.line);
    });
    entry.calls.slice(0, 100).forEach(function (call) {
      var caller = call.caller ? call.caller.owner + '::' + call.caller.method + ' → ' : '';
      var target = (call.className || '[dynamic class]') + '::' + (call.methodName || '[dynamic method]');
      addLine(calls, caller + target + ' · line ' + call.line + ' · ' + call.confidence + ' · ' + call.reason);
    });
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
    saveFile.disabled = saved.indexOf(id) !== -1 || saved.length >= 100;
    fileName.textContent = file.path + ' — parse state: ' + file.parseState;
    showMethodEvidence(id);
    declarations.replaceChildren();
    references.replaceChildren();
    incoming.replaceChildren();
    file.declarations.forEach(function (declaration) {
      addLine(declarations, declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line);
    });
    report.edges.forEach(function (edge) {
      if (edge.from === id) {
        var description = edge.type + ' · line ' + edge.line + ' · ' + edge.confidence +
          ' · ' + edge.reasonCode + ' · ';
        if (edge.to !== null) addFileLink(references, description, edge.to);
        else addLine(references, description + 'no confirmed destination');
      }
      if (edge.to === id && edge.from !== id) {
        addFileLink(incoming, edge.type + ' · line ' + edge.line + ' · ' + edge.confidence +
          ' · ' + edge.reasonCode + ' · ', edge.from);
      }
    });
    if (!declarations.children.length) addLine(declarations, file.parseState === 'clean'
      ? 'No declarations reported.' : 'No declarations reported; this file was not parsed cleanly.');
    if (!references.children.length) addLine(references, file.parseState === 'clean'
      ? 'No outgoing references reported.' : 'No outgoing references reported; this file was not parsed cleanly.');
    if (!incoming.children.length) addLine(incoming, 'No incoming references reported. Other files may not have been parsed.');
    Array.prototype.forEach.call(files.querySelectorAll('button'), function (button) {
      button.setAttribute('aria-pressed', String(button.dataset.fileId === selected));
    });
  }

  function renderReadingList() {
    readingList.replaceChildren();
    saved.forEach(function (id) {
      var item = document.createElement('li');
      var open = document.createElement('button');
      var remove = document.createElement('button');
      open.type = 'button';
      open.textContent = filesById.get(id).path;
      open.addEventListener('click', function () { showFile(id, true); detailsHeading.focus(); });
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', 'Remove ' + filesById.get(id).path + ' from reading list');
      remove.addEventListener('click', function () {
        saved = saved.filter(function (fileId) { return fileId !== id; });
        renderReadingList();
        if (selected !== null) saveFile.disabled = saved.indexOf(selected) !== -1;
        readingStatus.textContent = 'File removed from reading list.';
        document.getElementById('reading-heading').focus();
      });
      item.appendChild(open);
      item.appendChild(document.createTextNode(' '));
      item.appendChild(remove);
      readingList.appendChild(item);
    });
    if (!saved.length) addLine(readingList, 'No saved files.');
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
    var matches = [];
    report.nodes.forEach(function (node) {
      if (directoryFilter !== null && directoryOf(node.path) !== directoryFilter) return;
      var declarationsFound = query ? node.declarations.filter(function (declaration) {
        return declaration.name.toLowerCase().indexOf(query) !== -1 || declaration.kind.toLowerCase().indexOf(query) !== -1;
      }) : [];
      var entry = methodEvidence.files.get(node.id);
      var methodsFound = query && entry && entry.coverage === 'available' ? entry.methods.filter(function (method) {
        return method.name.toLowerCase().indexOf(query) !== -1 || method.owner.toLowerCase().indexOf(query) !== -1;
      }) : [];
      if (!query || node.path.toLowerCase().indexOf(query) !== -1 || declarationsFound.length || methodsFound.length) {
        matches.push({ node: node, declarations: declarationsFound, methods: methodsFound });
      }
    });
    fileCount.textContent = matches.length + ' of ' + report.nodes.length + ' files' +
      (matches.length > 500 ? ' (first 500 shown; refine your search).' : '');
    matches.slice(0, 500).forEach(function (match) {
      var node = match.node;
      var item = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.dataset.fileId = node.id;
      button.textContent = node.path;
      button.setAttribute('aria-pressed', String(node.id === selected));
      button.addEventListener('click', function () { showFile(node.id, true); });
      item.appendChild(button);
      if (query) {
        match.declarations.slice(0, 20).forEach(function (declaration) {
          var detail = document.createElement('div');
          detail.textContent = declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line;
          item.appendChild(detail);
        });
        match.methods.slice(0, 20).forEach(function (method) {
          var detail = document.createElement('div');
          detail.textContent = 'method ' + method.owner + '::' + method.name + ' · line ' + method.line;
          item.appendChild(detail);
        });
        if (match.declarations.length > 20 || match.methods.length > 20) {
          var notice = document.createElement('div');
          notice.textContent = 'Only the first 20 matches of each kind are shown here.';
          item.appendChild(notice);
        }
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
  saveFile.addEventListener('click', function () {
    if (selected === null || saved.indexOf(selected) !== -1 || saved.length >= 100) return;
    saved.push(selected);
    renderReadingList();
    saveFile.disabled = true;
    readingStatus.textContent = 'File added to reading list.';
    document.getElementById('reading-heading').focus();
  });
  input.addEventListener('change', async function () {
    var file = input.files[0];
    if (!file) return;
    var currentRequest = ++request;
    try {
      if (file.size > 16 * 1024 * 1024) throw new Error('Report exceeds the 16 MiB limit.');
      var candidate = JSON.parse(await file.text());
      if (!valid(candidate)) throw new Error('This is not a supported LegacyLens report.');
      var candidateMethodEvidence = LegacyMethodEvidence.read(candidate);
      if (currentRequest !== request) return;
      report = candidate;
      methodEvidence = candidateMethodEvidence;
      activeReport.textContent = 'Open report: ' + report.scan.rootLabel;
      filesById = new Map();
      report.nodes.forEach(function (node) { filesById.set(node.id, node); });
      selected = null;
      history = [];
      saved = [];
      saveFile.disabled = true;
      readingStatus.textContent = '';
      directoryFilter = null;
      back.disabled = true;
      search.disabled = false;
      search.value = '';
      fileName.textContent = 'Select a file to see its details.';
      declarations.replaceChildren();
      methods.replaceChildren();
      calls.replaceChildren();
      methodsStatus.textContent = 'Select a file to inspect method evidence.';
      callsStatus.textContent = 'These are source expressions, not proof that a method ran.';
      references.replaceChildren();
      incoming.replaceChildren();
      renderReadingList();
      renderDirectories();
      renderFiles();
      var incomplete = report.nodes.filter(function (node) { return node.parseState !== 'clean'; }).length;
      var warningSummary = Array.isArray(report.scan.warnings)
        ? report.scan.warnings.length + (report.scan.warnings.length === 1 ? ' scan warning.' : ' scan warnings.')
        : 'Scan warning count unavailable.';
      status.textContent = report.scan.rootLabel + ': ' + report.nodes.length + ' files, ' + report.edges.length +
        ' references. ' + incomplete + (incomplete === 1 ? ' file' : ' files') + ' not parsed cleanly. ' + warningSummary;
    } catch (error) {
      if (currentRequest === request) status.textContent = 'Could not load report: ' + error.message +
        (report ? ' The previous report is still open.' : ' Choose a valid LegacyLens report.');
    }
    input.value = '';
  });
}());
