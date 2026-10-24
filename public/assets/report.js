const MAX_REPORT_BYTES = 16 * 1024 * 1024;
const text = value => typeof value === 'string' && value.length > 0 && value.length <= 4096;
const line = value => Number.isInteger(value) && value > 0;

export async function readReportFile(file) {
  if (file.size > MAX_REPORT_BYTES) throw new Error('The report exceeds the 16 MiB limit.');
  return validateReport(JSON.parse(await file.text()));
}

export function validateReport(report) {
  if (!report || ![0, 1, 2].includes(report.schemaVersion) || !report.scan || !text(report.scan.rootLabel) ||
      !Array.isArray(report.scan.warnings) || report.scan.warnings.length > 5000 ||
      !Array.isArray(report.nodes) || report.nodes.length > 10000 || report.scan.fileCount !== report.nodes.length ||
      !Array.isArray(report.edges) || report.edges.length > 100000 ||
      (report.findings !== undefined && (!Array.isArray(report.findings) || report.findings.length > 100000))) {
    throw new Error('Unsupported or malformed report.');
  }
  const ids = new Set();
  let methodCount = 0;
  for (const node of report.nodes) {
    if (!node || !text(node.path) || node.id !== 'file:' + node.path || ids.has(node.id) || node.kind !== 'file' ||
        !['clean', 'failed', 'skipped'].includes(node.parseState) || !Array.isArray(node.declarations) || node.declarations.length > 100000) throw new Error('Malformed file entry.');
    const owners = new Set();
    for (const declaration of node.declarations) {
      if (!declaration || !text(declaration.name) || !['class', 'interface', 'trait', 'enum', 'function'].includes(declaration.kind) || !line(declaration.line)) throw new Error('Malformed declaration.');
      if (declaration.kind !== 'function') owners.add(JSON.stringify([declaration.kind, declaration.name, declaration.line]));
    }
    if (report.schemaVersion >= 1) {
      if (!Array.isArray(node.methods) || node.methods.length > 100000 || (node.parseState !== 'clean' && node.methods.length)) throw new Error('Malformed method declarations.');
      for (const method of node.methods) {
        if (!method || !text(method.owner) || !['class', 'interface', 'trait', 'enum'].includes(method.ownerKind) || !line(method.ownerLine) ||
            !owners.has(JSON.stringify([method.ownerKind, method.owner, method.ownerLine])) || !text(method.name) || !line(method.line) || method.ownerLine > method.line ||
            !['public', 'protected', 'private'].includes(method.visibility) || typeof method.static !== 'boolean' || ++methodCount > 100000) throw new Error('Malformed method declaration.');
      }
    }
    if (report.schemaVersion === 2 && (!Object.hasOwn(node, 'sourceSha256') || (node.parseState === 'clean'
      ? typeof node.sourceSha256 !== 'string' || node.sourceSha256.length !== 64 || !/^[0-9a-f]{64}$/.test(node.sourceSha256)
      : node.sourceSha256 !== null))) throw new Error('Malformed source fingerprint.');
    ids.add(node.id);
  }
  const edgeIds = new Set();
  for (const edge of report.edges) {
    if (!edge || !text(edge.id) || edgeIds.has(edge.id) || !ids.has(edge.from) || (edge.to !== null && !ids.has(edge.to)) ||
        !['instantiation', 'static-call', 'include', 'extends', 'implements', 'trait-use'].includes(edge.type) ||
        !['resolved', 'heuristic', 'unresolved'].includes(edge.confidence) ||
        (edge.confidence === 'resolved' && edge.to === null) || (edge.confidence === 'unresolved' && edge.to !== null) ||
        !text(edge.reasonCode) || !line(edge.line) || !edge.target || !Array.isArray(edge.target.candidates) ||
        !edge.target.candidates.every(id => ids.has(id))) throw new Error('Malformed reference.');
    edgeIds.add(edge.id);
  }
  for (const warning of report.scan.warnings) {
    if (!warning || !text(warning.fileId) || !text(warning.code) || (warning.line != null && !line(warning.line))) throw new Error('Malformed diagnostic.');
  }
  const findingIds = new Set();
  for (const finding of report.findings || []) {
    if (!finding || !text(finding.id) || findingIds.has(finding.id) || !ids.has(finding.fileId) || !text(finding.api) ||
        !['query', 'connection'].includes(finding.category) || finding.confidence !== 'heuristic' || !line(finding.line)) throw new Error('Malformed database indicator.');
    findingIds.add(finding.id);
  }
  return report;
}

export function hotspots(report) {
  const rows = new Map(report.nodes.map(node => [node.id, { id: node.id, path: node.path, incoming: new Set(), outgoing: new Set(), database: 0 }]));
  for (const edge of report.edges) {
    if (edge.confidence === 'resolved' && edge.to && edge.to !== edge.from) {
      rows.get(edge.from).outgoing.add(edge.to);
      rows.get(edge.to).incoming.add(edge.from);
    }
  }
  for (const finding of report.findings || []) if (finding.category === 'query') rows.get(finding.fileId).database++;
  return Array.from(rows.values(), row => ({ id: row.id, path: row.path, inbound: row.incoming.size,
    outbound: row.outgoing.size, database: row.database, score: 2 * row.incoming.size + row.outgoing.size + 3 * row.database }));
}
