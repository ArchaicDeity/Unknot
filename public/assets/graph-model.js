export const GRAPH_NODE_LIMIT = 500;
export const GRAPH_EDGE_LIMIT = 2000;
export const GRAPH_CLUSTER_LIMIT = GRAPH_NODE_LIMIT;

export function effectiveGraphLayout(requested, nodeCount) {
  return requested === 'cose' && nodeCount <= GRAPH_CLUSTER_LIMIT ? 'cose' : 'breadthfirst';
}

/** Expand a search by one hop, using only the currently filtered, known connections. */
export function connectedNodes(matches, nodes, edges) {
  const matched = new Set(matches.map(node => node.id));
  const included = new Set(matched);
  for (const edge of edges) {
    if (edge.to === null) continue;
    if (matched.has(edge.from)) included.add(edge.to);
    if (matched.has(edge.to)) included.add(edge.from);
  }
  return nodes.filter(node => included.has(node.id));
}

/** Only references with a visible destination can be drawn as edges. */
export function graphModel(nodes, edges, options = {}) {
  if (nodes.length > GRAPH_NODE_LIMIT) {
    return { nodes: [], edges: [], hiddenSelfReferences: 0, reason: 'node-limit' };
  }
  const visible = new Set(nodes.map(node => node.id));
  const connections = [];
  let hiddenSelfReferences = 0;
  for (const edge of edges) {
    if (edge.to !== null && visible.has(edge.from) && visible.has(edge.to)) {
      if (!options.includeSelf && edge.from === edge.to) {
        hiddenSelfReferences++;
        continue;
      }
      connections.push(edge);
      if (connections.length > GRAPH_EDGE_LIMIT) {
        return { nodes: [], edges: [], hiddenSelfReferences, reason: 'edge-limit' };
      }
    }
  }
  return { nodes, edges: connections, hiddenSelfReferences, reason: nodes.length ? null : 'empty' };
}
