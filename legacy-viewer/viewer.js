'use strict';

(function () {
  var input = document.getElementById('report');
  var status = document.getElementById('status');
  var files = document.getElementById('files');
  var references = document.getElementById('references');
  var report = null;

  function valid(data) {
    if (!data || data.schemaVersion !== 0 || !data.scan || typeof data.scan.rootLabel !== 'string' ||
        !Array.isArray(data.nodes) || data.nodes.length > 10000 || !Array.isArray(data.edges) || data.edges.length > 100000) return false;
    var ids = new Set();
    for (var i = 0; i < data.nodes.length; i++) {
      var node = data.nodes[i];
      if (!node || typeof node.id !== 'string' || typeof node.path !== 'string' || ids.has(node.id)) return false;
      ids.add(node.id);
    }
    return data.edges.every(function (edge) {
      return edge && ids.has(edge.from) && (edge.to === null || ids.has(edge.to)) &&
        ['resolved', 'heuristic', 'unresolved'].indexOf(edge.confidence) !== -1 &&
        (edge.confidence !== 'unresolved' || edge.to === null) &&
        (edge.confidence !== 'resolved' || edge.to !== null) &&
        typeof edge.type === 'string' && Number.isInteger(edge.line) && edge.line > 0 && typeof edge.reasonCode === 'string';
    });
  }

  function showFile(id) {
    references.replaceChildren();
    report.edges.filter(function (edge) { return edge.from === id; }).forEach(function (edge) {
      var item = document.createElement('li');
      item.textContent = edge.type + ' · line ' + edge.line + ' · ' + edge.confidence + ' · ' +
        (edge.to || edge.reasonCode);
      references.appendChild(item);
    });
    if (!references.children.length) {
      var empty = document.createElement('li');
      empty.textContent = 'No references found for this file.';
      references.appendChild(empty);
    }
  }

  input.addEventListener('change', async function () {
    var file = input.files[0];
    if (!file) return;
    try {
      if (file.size > 16 * 1024 * 1024) throw new Error('Report exceeds the 16 MiB limit.');
      var candidate = JSON.parse(await file.text());
      if (!valid(candidate)) throw new Error('This is not a supported LegacyLens report.');
      report = candidate;
      files.replaceChildren();
      references.replaceChildren();
      report.nodes.forEach(function (node) {
        var item = document.createElement('li');
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = node.path;
        button.addEventListener('click', function () { showFile(node.id); });
        item.appendChild(button);
        files.appendChild(item);
      });
      status.textContent = report.scan.rootLabel + ': ' + report.nodes.length + ' files, ' + report.edges.length + ' references.';
    } catch (error) {
      status.textContent = 'Could not load report: ' + error.message;
    }
    input.value = '';
  });
}());
