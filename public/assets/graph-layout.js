/** Arrange each visible graph from a known starting point before applying its layout. */
export function runGraphLayout(graph, layout) {
  const count = graph.nodes().length;
  if (!count) return;

  if (layout === 'cose') {
    // CoSE cannot reliably separate nodes that all start at the same position.
    graph.layout({ name: 'circle', animate: false, fit: false,
      radius: Math.max(100, Math.sqrt(count) * 35) }).run();
    graph.layout({ name: 'cose', animate: false, randomize: false, numIter: 250, fit: true, padding: 45,
      nodeDimensionsIncludeLabels: true, nodeRepulsion: () => 10000, idealEdgeLength: () => 100, componentSpacing: 100 }).run();
    return;
  }

  graph.layout({ name: 'breadthfirst', directed: true, circle: false, spacingFactor: 1.6,
    nodeDimensionsIncludeLabels: true, fit: true, padding: 45, animate: false }).run();
}
