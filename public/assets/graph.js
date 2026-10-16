import { graphModel, connectedNodes, effectiveGraphLayout, GRAPH_NODE_LIMIT, GRAPH_EDGE_LIMIT, GRAPH_CLUSTER_LIMIT } from './graph-model.js';

const STYLES = [
  { selector: 'node', style: { 'background-color': '#e5eee6', 'border-color': '#6e8d79', 'border-width': 2,
    width: 24, height: 24, label: 'data(label)', color: '#293b3d', 'font-size': 11,
    'text-valign': 'bottom', 'text-margin-y': 6, 'text-max-width': 150, 'text-wrap': 'ellipsis' } },
  { selector: 'node.current', style: { 'background-color': '#f5ddcc', 'border-color': '#a3502b', 'border-width': 4 } },
  { selector: 'edge', style: { width: 2, 'line-color': '#9cb5a5', 'target-arrow-color': '#799886',
    'target-arrow-shape': 'triangle', 'curve-style': 'bezier', 'arrow-scale': 0.8 } },
  { selector: 'edge.heuristic', style: { 'line-color': '#c48d6e', 'target-arrow-color': '#a86a48', 'line-style': 'dashed' } },
  { selector: 'edge.current', style: { width: 4, 'line-color': '#a3502b', 'target-arrow-color': '#a3502b' } },
];

/** Keep one graph instance so selecting a file does not reset pan, zoom or dragged nodes. */
export function createGraphController(container, status, zoomIndicator, selectFile, selectReference, setAvailable) {
  let graph = null;
  let library = null;
  let signature = null;
  let generation = 0;
  let selected = null;
  let selectedEdge = null;
  let layout = 'breadthfirst';
  let includeSelf = false;
  let lastInput = null;

  // The canvas must track its container when the dashboard changes width.
  const resizeGraph = () => { if (graph) graph.resize(); };
  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(resizeGraph).observe(container);
  } else {
    window.addEventListener('resize', resizeGraph);
  }

  function zoomText() {
    zoomIndicator.textContent = graph ? Math.round(graph.zoom() * 100) + '%' : '—';
  }

  function updateSelection() {
    if (!graph) return;
    graph.nodes('.current').removeClass('current');
    graph.edges('.current').removeClass('current');
    if (selected !== null) graph.getElementById(selected).addClass('current');
    if (selectedEdge !== null) graph.getElementById(selectedEdge).addClass('current');
  }

  function layoutGraph() {
    if (!graph || graph.nodes().empty()) return;
    const options = layout === 'cose'
      ? { name: 'cose', animate: false, randomize: false, numIter: 500, fit: true, padding: 45,
        nodeDimensionsIncludeLabels: true, nodeRepulsion: () => 10000, idealEdgeLength: () => 100, componentSpacing: 100 }
      : { name: 'breadthfirst', directed: true, circle: false, spacingFactor: 1.25, fit: true, padding: 35 };
    graph.layout(options).run();
    zoomText();
  }

  async function update(nodes, edges, current, currentEdge, options = {}) {
    lastInput = { nodes, edges, current, currentEdge, options };
    selected = current;
    selectedEdge = currentEdge;
    const expanded = options.includeNeighbors ? connectedNodes(nodes, options.allNodes, edges) : nodes;
    const model = graphModel(expanded, edges, { includeSelf });
    const request = ++generation;
    if (model.reason !== null) {
      if (graph) { graph.destroy(); graph = null; }
      signature = null;
      container.replaceChildren();
      zoomText();
      setAvailable(false);
      status.textContent = model.reason === 'empty' ? 'No files match the current search.'
        : model.reason === 'node-limit' ? `Graph limit: ${GRAPH_NODE_LIMIT} files. Narrow the search; the file list remains available.`
          : `Graph limit: ${GRAPH_EDGE_LIMIT} visible connections. Narrow the search or filters; the file list remains available.`;
      return;
    }
    const nextSignature = JSON.stringify([model.nodes.map(node => node.id),
      model.edges.map(edge => [edge.id, edge.from, edge.to, edge.confidence])]);
    const extra = expanded.length - nodes.length;
    const neighborNote = extra > 0 ? ` Includes ${extra} connected ${extra === 1 ? 'file' : 'files'}.` : '';
    const selfNote = model.hiddenSelfReferences ? ` ${model.hiddenSelfReferences} same-file ${model.hiddenSelfReferences === 1 ? 'reference' : 'references'} hidden.` : '';
    const layoutNote = model.nodes.length > GRAPH_CLUSTER_LIMIT ? ` Cluster layout requires ${GRAPH_CLUSTER_LIMIT} files or fewer.` : '';
    const description = `${model.nodes.length} ${model.nodes.length === 1 ? 'file' : 'files'}, ${model.edges.length} visible ${model.edges.length === 1 ? 'connection' : 'connections'}.${neighborNote}${selfNote}${layoutNote} Dashed lines are inferred; unresolved references appear in file details.`;
    if (graph && signature === nextSignature) {
      updateSelection();
      status.textContent = description;
      return;
    }
    status.textContent = 'Preparing the dependency map…';
    try {
      if (!library) library = (await import('./vendor/cytoscape.esm.min.mjs')).default;
      if (request !== generation) return;
      if (graph) { graph.destroy(); graph = null; }
      const elements = model.nodes.map(node => ({ data: { id: node.id, label: node.path.split('/').pop(), path: node.path } }));
      for (const edge of model.edges) {
        elements.push({ data: { id: edge.id, source: edge.from, target: edge.to }, classes: edge.confidence });
      }
      graph = library({ container, elements, style: STYLES, layout: { name: 'preset', fit: false },
        minZoom: 0.1, maxZoom: 4, boxSelectionEnabled: false });
      graph.on('tap', 'node', event => selectFile(event.target.id()));
      graph.on('tap', 'edge', event => selectReference(event.target.id()));
      graph.on('zoom', zoomText);
      layout = effectiveGraphLayout(layout, model.nodes.length);
      setAvailable(true, model.nodes.length <= GRAPH_CLUSTER_LIMIT, layout);
      signature = nextSignature;
      updateSelection();
      layoutGraph();
      status.textContent = description;
    } catch (error) {
      if (request !== generation) return;
      if (graph) { graph.destroy(); graph = null; }
      signature = null;
      container.replaceChildren();
      zoomText();
      setAvailable(false);
      status.textContent = 'The graph could not be drawn. The file list and details remain available.';
    }
  }

  return {
    update,
    fit() { if (graph && graph.nodes().length) { graph.fit(undefined, 35); zoomText(); } },
    zoom(factor) {
      if (!graph) return;
      graph.zoom({ level: Math.max(graph.minZoom(), Math.min(graph.maxZoom(), graph.zoom() * factor)),
        renderedPosition: { x: graph.width() / 2, y: graph.height() / 2 } });
      zoomText();
    },
    setLayout(value) {
      if (!['breadthfirst', 'cose'].includes(value)) return;
      if (value === 'cose' && graph && graph.nodes().length > GRAPH_CLUSTER_LIMIT) return;
      layout = value;
      layoutGraph();
    },
    setIncludeSelf(value) {
      includeSelf = value;
      if (lastInput) update(lastInput.nodes, lastInput.edges, lastInput.current, lastInput.currentEdge, lastInput.options);
    },
  };
}
