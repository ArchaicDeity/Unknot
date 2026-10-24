export const IMPACT_FILE_LIMIT = 500;
export const IMPACT_EDGE_LIMIT = 2000;
export const IMPACT_DEPTH_LIMIT = 5;

const compare = (a, b) => a < b ? -1 : a > b ? 1 : 0;

/** Find one shortest, evidenced path from each reported dependent to the selected file. */
export function analyzeImpact(report, selected, options = {}) {
  const files = new Map(report.nodes.map(file => [file.id, file]));
  if (!files.has(selected)) return { results: [], truncated: null };
  const incoming = new Map();
  for (const edge of report.edges) {
    if (edge.to === null || (edge.confidence !== 'resolved' && !(options.includeInferred && edge.confidence === 'heuristic')) ||
        (options.type && options.type !== 'all' && edge.type !== options.type)) continue;
    if (!incoming.has(edge.to)) incoming.set(edge.to, []);
    incoming.get(edge.to).push(edge);
  }
  for (const edges of incoming.values()) {
    edges.sort((a, b) => compare(files.get(a.from).path, files.get(b.from).path) || compare(a.id, b.id));
  }
  const visited = new Set([selected]);
  const results = [];
  let frontier = [{ id: selected, path: [], inferred: false }];
  let examined = 0;
  for (let depth = 1; depth <= IMPACT_DEPTH_LIMIT + 1 && frontier.length; depth++) {
    const next = new Map();
    for (const parent of frontier) {
      for (const edge of incoming.get(parent.id) || []) {
        if (++examined > IMPACT_EDGE_LIMIT) return { results, truncated: 'edge-limit' };
        if (visited.has(edge.from) || next.has(edge.from)) continue;
        if (depth > IMPACT_DEPTH_LIMIT) return { results, truncated: 'depth-limit' };
        next.set(edge.from, { id: edge.from, path: [edge, ...parent.path], inferred: parent.inferred || edge.confidence === 'heuristic' });
        if (results.length + next.size > IMPACT_FILE_LIMIT) return { results, truncated: 'file-limit' };
      }
    }
    frontier = Array.from(next.values());
    for (const item of frontier) {
      visited.add(item.id);
      results.push({ ...item, depth, file: files.get(item.id) });
    }
  }
  return { results, truncated: null };
}
