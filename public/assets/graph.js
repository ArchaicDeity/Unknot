const NS = 'http://www.w3.org/2000/svg';
function element(name, attributes) {
  const node = document.createElementNS(NS, name);
  for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
  return node;
}

export function renderGraph(container, nodes, edges, selected, select) {
  container.replaceChildren();
  if (nodes.length > 500 || edges.length > 2000) return 'Graph limit reached. Narrow the search or filters; the file list remains available.';
  if (!nodes.length) return 'No files match the current search.';
  const svg = element('svg', { viewBox: '0 0 720 240', role: 'group', 'aria-label': 'File connections. Use the file list for keyboard navigation.' });
  const defs = element('defs', {});
  const marker = element('marker', { id: 'arrow', viewBox: '0 0 10 10', refX: '9', refY: '5', markerWidth: '5', markerHeight: '5', orient: 'auto-start-reverse' });
  marker.appendChild(element('path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: '#9bada2' }));
  defs.appendChild(marker); svg.appendChild(defs);
  const positions = new Map();
  const columns = Math.max(1, Math.ceil(Math.sqrt(nodes.length * 3)));
  const rows = Math.ceil(nodes.length / columns);
  nodes.forEach((node, index) => positions.set(node.id, { x: (index % columns + .5) * 720 / columns, y: (Math.floor(index / columns) + .5) * 240 / rows }));
  for (const edge of edges) {
    if (!edge.to || !positions.has(edge.from) || !positions.has(edge.to) || edge.from === edge.to) continue;
    const a = positions.get(edge.from), b = positions.get(edge.to);
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    const endX = b.x - 9 * (b.x - a.x) / length, endY = b.y - 9 * (b.y - a.y) / length;
    svg.appendChild(element('line', { x1: a.x, y1: a.y, x2: endX, y2: endY, 'marker-end': 'url(#arrow)', class: 'graph-edge ' + edge.confidence }));
  }
  for (const node of nodes) {
    const point = positions.get(node.id);
    const group = element('g', { class: 'graph-node' + (selected === node.id ? ' selected' : ''), role: 'button', tabindex: '0', 'aria-label': 'Select ' + node.path });
    group.appendChild(element('circle', { cx: point.x, cy: point.y, r: 7 }));
    if (nodes.length <= 35) {
      const label = element('text', { x: point.x + 11, y: point.y + 4 });
      const name = node.path.split('/').pop();
      label.textContent = name.length > 20 ? name.slice(0, 17) + '…' : name;
      group.appendChild(label);
    }
    group.addEventListener('click', () => select(node.id));
    group.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(node.id); } });
    svg.appendChild(group);
  }
  container.appendChild(svg);
  return 'Lines show known or inferred connections; unresolved references appear in file details.';
}
