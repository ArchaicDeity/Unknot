/** Emphasize the selected file and its visible, directly connected references. */
export function applyGraphSelection(graph, selectedId, selectedEdgeId) {
  graph.elements().removeClass('dimmed related incoming outgoing current');
  const selected = selectedId === null ? graph.collection() : graph.getElementById(selectedId);
  if (selected.empty() || !selected.isNode()) return false;

  const edges = selected.connectedEdges();
  graph.elements().addClass('dimmed');
  edges.removeClass('dimmed');
  edges.connectedNodes().removeClass('dimmed').addClass('related');
  selected.removeClass('dimmed related').addClass('current');
  edges.forEach(edge => {
    if (edge.source().id() === selectedId) edge.addClass('outgoing');
    else edge.addClass('incoming');
  });
  if (selectedEdgeId !== null) graph.getElementById(selectedEdgeId).addClass('current');
  return true;
}
