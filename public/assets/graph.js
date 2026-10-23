import { graphModel, connectedNodes, effectiveGraphLayout, GRAPH_NODE_LIMIT, GRAPH_EDGE_LIMIT, GRAPH_CLUSTER_LIMIT } from './graph-model.js';
import { runGraphLayout } from './graph-layout.js';
import { applyGraphSelection } from './graph-selection.js';

const STYLES = [
  { selector: 'node', style: { 'background-color': '#2a3440', 'border-color': '#6ef2c5', 'border-width': 2,
    width: 26, height: 26, label: 'data(label)', color: '#f0f3f1', 'font-size': 13,
    'text-valign': 'bottom', 'text-margin-y': 7, 'text-max-width': 180, 'text-wrap': 'ellipsis', 'min-zoomed-font-size': 8,
    'text-background-color': '#10191e', 'text-background-opacity': 0.9, 'text-background-padding': 3 } },
  { selector: 'node.related', style: { 'background-color': '#344f4a', 'border-color': '#a0edda', 'border-width': 3 } },
  { selector: 'node.current', style: { 'background-color': '#6ef2c5', 'border-color': '#f0f3f1', 'border-width': 4,
    'min-zoomed-font-size': 0, 'text-max-width': 400, 'text-wrap': 'wrap' } },
  { selector: 'node.hovered', style: { 'background-color': '#a6ffdf', 'border-color': '#f0f3f1', 'border-width': 4 } },
  { selector: 'edge', style: { width: 2.5, 'line-color': '#789d98', 'target-arrow-color': '#a1c8bd',
    'target-arrow-shape': 'triangle', 'curve-style': 'bezier', 'arrow-scale': 0.8 } },
  { selector: 'edge.heuristic', style: { 'line-color': '#e3b87c', 'target-arrow-color': '#e3b87c', 'line-style': 'dashed' } },
  { selector: 'node.dimmed', style: { opacity: 0.16 } },
  { selector: 'node.dimmed.hovered', style: { opacity: 1 } },
  { selector: 'edge.dimmed', style: { opacity: 0.10 } },
  { selector: 'edge.incoming', style: { width: 4, 'line-color': '#80bdff', 'target-arrow-color': '#80bdff' } },
  { selector: 'edge.outgoing', style: { width: 4, 'line-color': '#6ef2c5', 'target-arrow-color': '#6ef2c5' } },
  { selector: 'edge.current', style: { width: 5, 'line-color': '#f0f3f1', 'target-arrow-color': '#f0f3f1' } },
];

/** Keep one graph instance so selecting a file does not reset pan, zoom or dragged nodes. */
export function createGraphController(container, tooltip, status, zoomIndicator, selectFile, selectReference, setAvailable, setFocusAvailable) {
  let graph = null;
  let library = null;
  let signature = null;
  let generation = 0;
  let selected = null;
  let selectedEdge = null;
  let layout = 'cose';
  let includeSelf = false;
  let focusedExploration = false;
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
    if (graph) graph.nodes('.current').style('font-size', Math.max(13, Math.min(80, 13 / graph.zoom())));
  }

  function updateSelection() {
    if (!graph) return;
    graph.nodes('.current').removeStyle('font-size');
    setFocusAvailable(applyGraphSelection(graph, selected, selectedEdge, { dimUnrelated: !focusedExploration }));
    zoomText();
  }

  function layoutGraph() {
    if (!graph || graph.nodes().empty()) return;
    runGraphLayout(graph, layout);
    if (graph.nodes().length > 80 && graph.edges().some(edge => edge.source().id() !== edge.target().id())) {
      const components = graph.elements().components();
      const largest = components.reduce((best, component) =>
        component.nodes().length > best.nodes().length ? component : best, components[0]);
      graph.fit(largest, 45);
      if (graph.zoom() < 0.35) {
        graph.zoom({ level: 0.35, renderedPosition: { x: graph.width() / 2, y: graph.height() / 2 } });
      }
    }
    zoomText();
  }

  async function update(nodes, edges, current, currentEdge, options = {}) {
    lastInput = { nodes, edges, current, currentEdge, options };
    selected = current;
    selectedEdge = currentEdge;
    focusedExploration = options.focusedExploration === true;
    const expanded = options.includeNeighbors ? connectedNodes(nodes, options.allNodes, edges) : nodes;
    const model = graphModel(expanded, edges, { includeSelf });
    const request = ++generation;
    if (model.reason !== null) {
      if (graph) { graph.destroy(); graph = null; }
      signature = null;
      container.replaceChildren();
      zoomText();
      setAvailable(false);
      setFocusAvailable(false);
      tooltip.hidden = true;
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
    const focusNote = model.nodes.length > 80 && model.edges.some(edge => edge.from !== edge.to)
      ? ' Starting near the largest connected group; use Fit graph to see all files.' : '';
    const description = `${model.nodes.length} ${model.nodes.length === 1 ? 'file' : 'files'}, ${model.edges.length} visible ${model.edges.length === 1 ? 'connection' : 'connections'}.${neighborNote}${selfNote}${layoutNote}${focusNote} Dashed lines are inferred; unresolved references appear in file details.`;
    if (graph && signature === nextSignature) {
      updateSelection();
      status.textContent = description;
      return;
    }
    status.textContent = 'Preparing the dependency map…';
    try {
      if (!library) library = (await import('./vendor/cytoscape.esm.min.mjs')).default;
      if (request !== generation) return;
      tooltip.hidden = true;
      if (graph) { graph.destroy(); graph = null; }
      const elements = model.nodes.map(node => ({ data: { id: node.id, label: node.path.split('/').pop(), path: node.path } }));
      for (const edge of model.edges) {
        elements.push({ data: { id: edge.id, source: edge.from, target: edge.to }, classes: edge.confidence });
      }
      graph = library({ container, elements, style: STYLES, layout: { name: 'preset', fit: false },
        minZoom: 0.1, maxZoom: 4, boxSelectionEnabled: false });
      graph.on('tap', 'node', event => selectFile(event.target.id()));
      graph.on('tap', 'edge', event => selectReference(event.target.id()));
      graph.on('mouseover', 'node', event => {
        event.target.addClass('hovered');
        tooltip.textContent = event.target.data('path');
        tooltip.hidden = false;
      });
      graph.on('mouseout', 'node', event => { event.target.removeClass('hovered'); tooltip.hidden = true; });
      graph.on('pan zoom', () => { tooltip.hidden = true; graph.nodes('.hovered').removeClass('hovered'); });
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
      setFocusAvailable(false);
      tooltip.hidden = true;
      status.textContent = 'The graph could not be drawn. The file list and details remain available.';
    }
  }

  return {
    update,
    fit() { if (graph && graph.nodes().length) { graph.fit(undefined, 35); zoomText(); } },
    focusSelection() {
      if (!graph || selected === null) return;
      const file = graph.getElementById(selected);
      if (file.empty() || !file.isNode()) return;
      graph.fit(file.closedNeighborhood(), 70);
      if (graph.zoom() < 0.7) graph.zoom({ level: 0.7, renderedPosition: { x: graph.width() / 2, y: graph.height() / 2 } });
      if (graph.zoom() > 1.5) graph.zoom({ level: 1.5, renderedPosition: { x: graph.width() / 2, y: graph.height() / 2 } });
      zoomText();
    },
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
