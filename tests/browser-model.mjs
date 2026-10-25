import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/report.js', import.meta.url));
const { validateReport, readReportFile, hotspots } = await import('data:text/javascript;base64,' + source.toString('base64'));
const report = {
  schemaVersion: 0,
  scan: { rootLabel: 'fixture', fileCount: 2, warnings: [] },
  nodes: [
    { id: 'file:A.php', path: 'A.php', kind: 'file', parseState: 'clean', declarations: [] },
    { id: 'file:B.php', path: 'B.php', kind: 'file', parseState: 'clean', declarations: [] },
  ],
  edges: [
    { id: 'edge:1', from: 'file:A.php', to: 'file:B.php', type: 'instantiation', confidence: 'resolved', reasonCode: 'named-symbol', line: 2, target: { candidates: ['file:B.php'] } },
    { id: 'edge:2', from: 'file:A.php', to: 'file:B.php', type: 'static-call', confidence: 'resolved', reasonCode: 'named-symbol', line: 3, target: { candidates: ['file:B.php'] } },
    { id: 'edge:3', from: 'file:B.php', to: null, type: 'include', confidence: 'unresolved', reasonCode: 'dynamic-expression', line: 4, target: { candidates: [] } },
  ],
  findings: [{ id: 'finding:1', fileId: 'file:A.php', api: 'pdo::query', category: 'query', confidence: 'heuristic', line: 5 }],
};
assert.equal(validateReport(report), report);
const legacyWithExtension = structuredClone(report);
legacyWithExtension.extensions = { legacyMethodEvidence: { version: 1, files: report.nodes.map(node => ({ fileId: node.id, coverage: 'available', methods: [], calls: [] })) } };
assert.equal(validateReport(legacyWithExtension), legacyWithExtension, 'A schema-0 extension must not block existing file-level exploration.');
assert.deepEqual(hotspots(legacyWithExtension), hotspots(report), 'Unknown method evidence must not change file-level hotspot counts.');
assert.equal((await readReportFile({ size: 100, text: async () => JSON.stringify(report) })).scan.rootLabel, 'fixture');
let oversizedRead = false;
await assert.rejects(readReportFile({ size: 16 * 1024 * 1024 + 1, text: async () => { oversizedRead = true; return '{}'; } }), /16 MiB/);
assert.equal(oversizedRead, false, 'Oversized report imports are rejected before reading.');
await assert.rejects(readReportFile({ size: 1, text: async () => '{' }), SyntaxError);
const modern = structuredClone(report);
modern.schemaVersion = 1;
modern.nodes[0].declarations = [{ name: 'App\\Service', kind: 'class', line: 1 }];
modern.nodes[0].methods = [{ owner: 'App\\Service', ownerKind: 'class', ownerLine: 1, name: 'run', line: 2, visibility: 'public', static: false }];
modern.nodes[1].methods = [];
assert.equal(validateReport(modern), modern);
const fingerprinted = structuredClone(modern);
fingerprinted.schemaVersion = 2;
fingerprinted.nodes[0].sourceSha256 = 'a'.repeat(64);
fingerprinted.nodes[1].parseState = 'skipped';
fingerprinted.nodes[1].sourceSha256 = null;
assert.equal(validateReport(fingerprinted), fingerprinted);
for (const value of [undefined, null, 'A'.repeat(64), 'a'.repeat(64) + '\n', 'a'.repeat(63)]) {
  const invalid = structuredClone(fingerprinted);
  if (value === undefined) delete invalid.nodes[0].sourceSha256;
  else invalid.nodes[0].sourceSha256 = value;
  assert.throws(() => validateReport(invalid), /Malformed source fingerprint/);
}
const fingerprintOnSkipped = structuredClone(fingerprinted);
fingerprintOnSkipped.nodes[1].sourceSha256 = 'a'.repeat(64);
assert.throws(() => validateReport(fingerprintOnSkipped), /Malformed source fingerprint/);
const invalidMethod = structuredClone(modern);
invalidMethod.nodes[0].methods[0].owner = 'App\\Other';
assert.throws(() => validateReport(invalidMethod), /Malformed method/);
invalidMethod.nodes[0].methods[0].owner = 'App\\Service';
invalidMethod.nodes[0].methods[0].static = 'yes';
assert.throws(() => validateReport(invalidMethod), /Malformed method/);
delete invalidMethod.nodes[0].methods;
assert.throws(() => validateReport(invalidMethod), /Malformed method/);
assert.deepEqual(hotspots(report).map(({ path, inbound, outbound, database, score }) => ({ path, inbound, outbound, database, score })), [
  { path: 'A.php', inbound: 0, outbound: 1, database: 1, score: 4 },
  { path: 'B.php', inbound: 1, outbound: 0, database: 0, score: 2 },
]);
const bad = JSON.parse(JSON.stringify(report));
bad.edges[0].confidence = 'invented';
assert.throws(() => validateReport(bad), /Malformed reference/);
bad.edges[0].confidence = 'unresolved';
assert.throws(() => validateReport(bad), /Malformed reference/);
console.log('Browser report model checks passed.');
