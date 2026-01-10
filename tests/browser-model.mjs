import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/report.js', import.meta.url));
const { validateReport, hotspots } = await import('data:text/javascript;base64,' + source.toString('base64'));
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
