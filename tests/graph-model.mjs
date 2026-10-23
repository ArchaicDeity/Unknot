import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import cytoscape from 'cytoscape';

const source = await readFile(new URL('../public/assets/graph-model.js', import.meta.url));
const { graphModel, connectedNodes, expandNeighborhood, effectiveGraphLayout, GRAPH_NODE_LIMIT, GRAPH_EDGE_LIMIT, GRAPH_CLUSTER_LIMIT } = await import('data:text/javascript;base64,' + source.toString('base64'));
const layoutSource = await readFile(new URL('../public/assets/graph-layout.js', import.meta.url));
const { runGraphLayout } = await import('data:text/javascript;base64,' + layoutSource.toString('base64'));
const selectionSource = await readFile(new URL('../public/assets/graph-selection.js', import.meta.url));
const { applyGraphSelection } = await import('data:text/javascript;base64,' + selectionSource.toString('base64'));
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
const firstStep = expandNeighborhood([files[0]], files, edges);
assert.deepEqual(firstStep.nodes.map(file => file.id), ['file:A', 'file:B']);
assert.equal(firstStep.reason, null);
assert.deepEqual(expandNeighborhood(firstStep.nodes, files, edges).nodes.map(file => file.id), ['file:A', 'file:B', 'file:C']);
assert.deepEqual(expandNeighborhood([files[0]], files, edges.filter(edge => edge.confidence === 'heuristic')).nodes.map(file => file.id), ['file:A', 'file:B']);
assert.deepEqual(expandNeighborhood([files[2]], files, []).nodes.map(file => file.id), ['file:C']);
assert.deepEqual(model.edges.map(edge => edge.id), ['edge:1', 'edge:2']);
assert.equal(model.hiddenSelfReferences, 1);
const withSelf = graphModel(files.slice(0, 2), edges, { includeSelf: true });
assert.deepEqual(withSelf.edges.map(edge => edge.id), ['edge:1', 'edge:2', 'edge:5']);
assert.equal(graphModel([], edges).reason, 'empty');
assert.equal(graphModel(Array.from({ length: GRAPH_NODE_LIMIT + 1 }, (_, i) => ({ id: String(i) })), []).reason, 'node-limit');
const many = Array.from({ length: GRAPH_EDGE_LIMIT + 1 }, (_, i) => ({ id: 'edge:' + i, from: 'file:A', to: 'file:B' }));
assert.equal(graphModel(files, many).reason, 'edge-limit');
const edgeLimited = expandNeighborhood([files[0]], files, many);
assert.equal(edgeLimited.reason, 'edge-limit');
assert.deepEqual(edgeLimited.nodes, [files[0]], 'A rejected expansion retains the previous view.');
const wideNodes = Array.from({ length: GRAPH_NODE_LIMIT + 1 }, (_, i) => ({ id: 'file:' + i }));
const wideEdges = wideNodes.slice(1).map((item, i) => ({ id: 'edge:' + i, from: 'file:0', to: item.id }));
const nodeLimited = expandNeighborhood([wideNodes[0]], wideNodes, wideEdges);
assert.equal(nodeLimited.reason, 'node-limit');
assert.deepEqual(nodeLimited.nodes, [wideNodes[0]]);
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

const connectedModel = graphModel(files, edges, { includeSelf: true });
const selectionGraph = cytoscape({ headless: true, elements: [
  ...files.map(file => ({ data: { id: file.id } })),
  ...connectedModel.edges.map(edge => ({ data: { id: edge.id, source: edge.from, target: edge.to } })),
] });
assert.equal(applyGraphSelection(selectionGraph, 'file:B', 'edge:1'), true);
assert.ok(selectionGraph.getElementById('file:B').hasClass('current'));
assert.ok(selectionGraph.getElementById('file:A').hasClass('related'));
assert.ok(selectionGraph.getElementById('edge:1').hasClass('incoming'));
assert.ok(selectionGraph.getElementById('edge:1').hasClass('current'));
assert.ok(selectionGraph.getElementById('edge:5').hasClass('dimmed'));
assert.equal(selectionGraph.edges().length, connectedModel.edges.length, 'Highlighting must not remove references.');
assert.equal(applyGraphSelection(selectionGraph, 'file:A', null), true);
assert.ok(selectionGraph.getElementById('edge:1').hasClass('outgoing'));
assert.ok(!selectionGraph.getElementById('edge:1').hasClass('incoming'));
assert.ok(selectionGraph.getElementById('file:C').hasClass('dimmed'));
assert.equal(applyGraphSelection(selectionGraph, 'file:A', null, { dimUnrelated: false }), true);
assert.equal(selectionGraph.elements('.dimmed').length, 0, 'Focused exploration must keep expanded layers visible.');
assert.ok(selectionGraph.getElementById('edge:1').hasClass('outgoing'), 'Direct relationship direction remains highlighted.');
assert.equal(applyGraphSelection(selectionGraph, null, null), false);
assert.equal(selectionGraph.elements('.dimmed').length, 0, 'Clearing a selection restores the whole graph.');
assert.equal(applyGraphSelection(selectionGraph, 'file:missing', null), false);
selectionGraph.destroy();

// Exercise the real demo-sized graph: a cluster layout must separate initially coincident nodes.
const demo = JSON.parse(await readFile(new URL('../demo/laravel-database.json', import.meta.url)));
const demoModel = graphModel(demo.nodes, demo.edges);
assert.equal(demoModel.reason, null);
assert.ok(demoModel.nodes.length > 150 && demoModel.nodes.length <= GRAPH_CLUSTER_LIMIT);
const demoGraph = cytoscape({ headless: true, styleEnabled: true, elements: [
  ...demoModel.nodes.map(node => ({ data: { id: node.id } })),
  ...demoModel.edges.map(edge => ({ data: { id: edge.id, source: edge.from, target: edge.to } })),
] });
runGraphLayout(demoGraph, 'cose');
const positions = demoGraph.nodes().map(node => node.position());
assert.ok(positions.every(position => Number.isFinite(position.x) && Number.isFinite(position.y)));
assert.ok(new Set(positions.map(position => Math.round(position.x) + ',' + Math.round(position.y))).size > 200,
  'The demo nodes should be spread across the cluster layout.');
runGraphLayout(demoGraph, 'breadthfirst');
assert.ok(demoGraph.nodes().every(node => Number.isFinite(node.position('x')) && Number.isFinite(node.position('y'))));
demoGraph.destroy();
console.log('Graph model and Cytoscape interaction checks passed.');
