import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import cytoscape from 'cytoscape';

const source = await readFile(new URL('../public/assets/graph-model.js', import.meta.url));
const { graphModel, connectedNodes, effectiveGraphLayout, GRAPH_NODE_LIMIT, GRAPH_EDGE_LIMIT, GRAPH_CLUSTER_LIMIT } = await import('data:text/javascript;base64,' + source.toString('base64'));
assert.equal(effectiveGraphLayout('cose', GRAPH_CLUSTER_LIMIT), 'cose');
assert.equal(effectiveGraphLayout('cose', GRAPH_CLUSTER_LIMIT + 1), 'breadthfirst');
const files = ['A', 'B', 'C'].map(id => ({ id: 'file:' + id, path: id }));
const edges = [
  { id: 'edge:1', from: 'file:A', to: 'file:B', confidence: 'resolved' },
  { id: 'edge:2', from: 'file:A', to: 'file:B', confidence: 'heuristic' },
  { id: 'edge:3', from: 'file:A', to: null, confidence: 'unresolved' },
  { id: 'edge:4', from: 'file:C', to: 'file:B', confidence: 'resolved' },
  { id: 'edge:5', from: 'file:A', to: 'file:A', confidence: 'resolved' },
];
const model = graphModel(files.slice(0, 2), edges);
assert.deepEqual(connectedNodes([files[0]], files, edges).map(file => file.id), ['file:A', 'file:B']);
assert.deepEqual(connectedNodes([files[1]], files, edges).map(file => file.id), ['file:A', 'file:B', 'file:C']);
assert.deepEqual(connectedNodes([], files, edges), []);
assert.deepEqual(model.edges.map(edge => edge.id), ['edge:1', 'edge:2']);
assert.equal(model.hiddenSelfReferences, 1);
const withSelf = graphModel(files.slice(0, 2), edges, { includeSelf: true });
assert.deepEqual(withSelf.edges.map(edge => edge.id), ['edge:1', 'edge:2', 'edge:5']);
assert.equal(graphModel([], edges).reason, 'empty');
assert.equal(graphModel(Array.from({ length: GRAPH_NODE_LIMIT + 1 }, (_, i) => ({ id: String(i) })), []).reason, 'node-limit');
const many = Array.from({ length: GRAPH_EDGE_LIMIT + 1 }, (_, i) => ({ id: 'edge:' + i, from: 'file:A', to: 'file:B' }));
assert.equal(graphModel(files, many).reason, 'edge-limit');
assert.equal(graphModel([files[2]], many).reason, null, 'Hidden endpoints must not exhaust the visible edge budget.');

const graph = cytoscape({ headless: true, elements: [
  ...model.nodes.map(node => ({ data: { id: node.id } })),
  ...withSelf.edges.map(edge => ({ data: { id: edge.id, source: edge.from, target: edge.to }, classes: edge.confidence })),
] });
assert.equal(graph.nodes().length, 2);
assert.equal(graph.edges().length, 3, 'Parallel references and self-references must remain distinct.');
assert.equal(graph.edges('.heuristic').length, 1);
graph.layout({ name: 'breadthfirst', directed: true, animate: false }).run();
let tapped = null;
graph.on('tap', 'node', event => { tapped = event.target.id(); });
graph.getElementById('file:A').emit('tap');
assert.equal(tapped, 'file:A');
graph.destroy();
console.log('Graph model and Cytoscape interaction checks passed.');
