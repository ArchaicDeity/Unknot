'use strict';

// The extension is separate from the original file-reference graph.
var LegacyMethodEvidence = (function () {
  var hasOwn = Object.prototype.hasOwnProperty;
  var encoder = new TextEncoder();

  function name(value) {
    return typeof value === 'string' && value.length > 0 && value.length <= 512 &&
      !/[\x00-\x1f\x7f]/.test(value) && encoder.encode(value).length <= 512;
  }

  function line(value) { return Number.isSafeInteger(value) && value > 0; }

  function keys(value, expected) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    var actual = Object.keys(value).sort();
    expected = expected.slice().sort();
    return actual.length === expected.length && actual.every(function (key, index) { return key === expected[index]; });
  }

  function read(report) {
    var empty = { state: 'unavailable', files: new Map() };
    if (report.extensions === undefined) return empty;
    if (!report.extensions || typeof report.extensions !== 'object' || Array.isArray(report.extensions)) throw new Error('Malformed report extensions.');
    if (!hasOwn.call(report.extensions, 'legacyMethodEvidence')) return empty;
    var extension = report.extensions.legacyMethodEvidence;
    if (!extension || !Number.isSafeInteger(extension.version) || extension.version < 1) throw new Error('Malformed method evidence.');
    if (extension.version !== 1) return { state: 'unsupported', files: new Map() };
    if (!keys(extension, ['version', 'files']) || !Array.isArray(extension.files) || extension.files.length !== report.nodes.length) throw new Error('Malformed method evidence.');
    var nodes = new Map(report.nodes.map(function (node) { return [node.id, node]; }));
    var files = new Map();
    var methodsTotal = 0;
    var callsTotal = 0;
    extension.files.forEach(function (entry) {
      var node = entry && nodes.get(entry.fileId);
      if (!keys(entry, ['fileId', 'coverage', 'methods', 'calls']) || !node || files.has(entry.fileId) || ['available', 'unavailable'].indexOf(entry.coverage) === -1 ||
          !Array.isArray(entry.methods) || !Array.isArray(entry.calls) ||
          (entry.coverage === 'available' && node.parseState !== 'clean') ||
          (entry.coverage === 'unavailable' && (entry.methods.length || entry.calls.length))) throw new Error('Malformed method evidence.');
      methodsTotal += entry.methods.length;
      callsTotal += entry.calls.length;
      if (methodsTotal > 100000 || callsTotal > 100000) throw new Error('Method evidence exceeds the limit.');
      var owners = new Set();
      node.declarations.forEach(function (item) { owners.add(JSON.stringify([item.kind, item.name, item.line])); });
      var methods = new Set();
      entry.methods.forEach(function (method) {
        if (!keys(method, ['owner', 'ownerKind', 'ownerLine', 'name', 'line', 'visibility', 'static']) || !name(method.owner) || !name(method.name) ||
            ['class', 'interface', 'trait'].indexOf(method.ownerKind) === -1 || !line(method.ownerLine) || !line(method.line) ||
            method.ownerLine > method.line || !owners.has(JSON.stringify([method.ownerKind, method.owner, method.ownerLine])) ||
            ['public', 'protected', 'private'].indexOf(method.visibility) === -1 || typeof method.static !== 'boolean') throw new Error('Malformed method evidence.');
        methods.add(JSON.stringify([method.owner, method.name]));
      });
      entry.calls.forEach(function (call) {
        if (!keys(call, ['line', 'form', 'caller', 'className', 'methodName', 'target', 'confidence', 'reason']) || !line(call.line) || call.form !== 'static' || !hasOwn.call(call, 'caller') ||
            !hasOwn.call(call, 'className') || !hasOwn.call(call, 'methodName') || !hasOwn.call(call, 'target') ||
            (call.className !== null && !name(call.className)) || (call.methodName !== null && !name(call.methodName)) ||
            call.target !== null || call.confidence !== 'unresolved' ||
            (call.caller !== null && (!keys(call.caller, ['owner', 'method']) || !name(call.caller.owner) || !name(call.caller.method) ||
              !methods.has(JSON.stringify([call.caller.owner, call.caller.method]))))) throw new Error('Malformed method evidence.');
        var reason = call.className === null ? 'dynamic-class' : (call.methodName === null ? 'dynamic-method' : 'static-target-unresolved');
        if (call.reason !== reason) throw new Error('Malformed method evidence.');
      });
      files.set(entry.fileId, entry);
    });
    return { state: 'available', files: files };
  }

  return { read: read };
}());
