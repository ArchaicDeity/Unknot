import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/impact-model.js', import.meta.url));
const { analyzeImpact } = await import('data:text/javascript;base64,' + source.toString('base64'));
const file = name => ({ id: 'file:' + name, path: name });
const edge = (id, from, to, confidence = 'resolved', type = 'instantiation') => ({
  id, from: 'file:' + from, to: to === null ? null : 'file:' + to, confidence, type, line: 1, reasonCode: confidence,
});
const report = { nodes: ['A', 'B', 'C', 'D', 'E', 'F'].map(file), scan: { warnings: [{ code: 'parse-error' }] }, edges: [
  edge('2', 'B', 'A'), edge('1', 'C', 'B'), edge('3', 'D', 'B', 'heuristic'),
  edge('4', 'A', 'C'), edge('5', 'E', null, 'unresolved'), edge('6', 'C', 'B'),
  edge('7', 'F', 'A', 'resolved', 'include'),
] };
report.nodes[4].parseState = 'failed';
report.edges[4].target = { candidates: ['file:A'] };
let result = analyzeImpact(report, 'file:A');
assert.deepEqual(result.results.map(item => [item.id, item.depth, item.path.map(e => e.id)]), [
  ['file:B', 1, ['2']], ['file:F', 1, ['7']], ['file:C', 2, ['1', '2']],
]);
assert.equal(result.truncated, null, 'Cycles, failed files and unresolved candidates do not invent paths.');
result = analyzeImpact(report, 'file:A', { includeInferred: true });
assert.deepEqual(result.results.find(item => item.id === 'file:D').path.map(e => e.id), ['3', '2']);
assert.equal(result.results.find(item => item.id === 'file:D').inferred, true);
assert.deepEqual(analyzeImpact(report, 'file:A', { type: 'include' }).results.map(item => item.id), ['file:F']);
assert.deepEqual(analyzeImpact(report, 'file:missing').results, []);
const shuffled = { ...report, edges: [...report.edges].reverse() };
assert.deepEqual(analyzeImpact(shuffled, 'file:A').results.map(x => x.path.map(e => e.id)), analyzeImpact(report, 'file:A').results.map(x => x.path.map(e => e.id)));

const long = { nodes: Array.from({ length: 7 }, (_, i) => file(String(i))), edges: Array.from({ length: 6 }, (_, i) => edge(String(i), String(i + 1), String(i))) };
assert.equal(analyzeImpact(long, 'file:0').results.length, 5);
assert.equal(analyzeImpact(long, 'file:0').truncated, 'depth-limit');
long.edges.pop();
assert.equal(analyzeImpact(long, 'file:0').truncated, null, 'A path ending at the depth limit is complete.');

const fanout = { nodes: [file('root'), ...Array.from({ length: 501 }, (_, i) => file(String(i)))], edges: Array.from({ length: 501 }, (_, i) => edge(String(i), String(i), 'root')) };
result = analyzeImpact(fanout, 'file:root');
assert.equal(result.truncated, 'file-limit');
assert.equal(result.results.length, 0, 'A partial layer is omitted when the file limit is reached.');
const dense = { nodes: [file('root'), file('parent'), file('other')], edges: [edge('direct', 'parent', 'root'), ...Array.from({ length: 2000 }, (_, i) => edge(String(i), 'other', 'parent'))] };
result = analyzeImpact(dense, 'file:root');
assert.equal(result.truncated, 'edge-limit');
assert.deepEqual(result.results.map(x => x.id), ['file:parent'], 'A partial layer is omitted when the reference limit is reached.');

const demo = JSON.parse(await readFile(new URL('../demo/laravel-database.json', import.meta.url)));
result = analyzeImpact(demo, 'file:Connectors/ConnectionFactory.php');
const capsule = result.results.find(item => item.id === 'file:Capsule/Manager.php');
assert.equal(capsule.depth, 1);
assert.equal(capsule.path[0].line, 60);
assert.equal(capsule.path[0].type, 'instantiation');
const indirect = analyzeImpact(demo, 'file:Connection.php').results.find(item => item.id === 'file:Capsule/Manager.php');
assert.equal(indirect.depth, 2);
assert.deepEqual(indirect.path.map(edge => [edge.from, edge.line, edge.to]), [
  ['file:Capsule/Manager.php', 60, 'file:Connectors/ConnectionFactory.php'],
  ['file:Connectors/ConnectionFactory.php', 304, 'file:Connection.php'],
]);
console.log('Change-impact model checks passed.');
