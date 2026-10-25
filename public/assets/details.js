import { visibleMethods } from './search.js';

export function createDetailsRenderer({ container, search, selectFile, node }) {
  function labelConfidence(value) { return value === 'resolved' ? 'Resolved (static)' : value === 'heuristic' ? 'Inferred' : 'Unresolved'; }
  function section(title, rows) {
    const wrapper = node('section', undefined, 'detail-section');
    wrapper.appendChild(node('h3', title));
    if (!rows.length) { wrapper.appendChild(node('p', 'None found.', 'muted')); return wrapper; }
    const list = node('ul');
    for (const row of rows) {
      if (typeof row === 'string') { list.appendChild(node('li', row)); continue; }
      const item = node('li', undefined, row.selected ? 'reference-selected' : undefined);
      item.appendChild(document.createTextNode(row.prefix));
      if (row.fileId) {
        const link = node('button', row.path, 'reference-link');
        link.type = 'button'; link.setAttribute('aria-label', 'Open file ' + row.path);
        link.addEventListener('click', () => selectFile(row.fileId, true));
        item.appendChild(link);
      } else item.appendChild(document.createTextNode(row.path));
      if (row.suffix) item.appendChild(document.createTextNode(row.suffix));
      if (row.candidates?.length) {
        const candidates = node('ul', undefined, 'reference-candidates');
        for (const candidate of row.candidates.slice(0, 20)) {
          const candidateItem = node('li');
          const link = node('button', candidate.slice(5), 'reference-link');
          link.type = 'button'; link.setAttribute('aria-label', 'Open possible candidate ' + candidate.slice(5));
          link.addEventListener('click', () => selectFile(candidate, true));
          candidateItem.appendChild(link); candidates.appendChild(candidateItem);
        }
        if (row.candidates.length > 20) candidates.appendChild(node('li', (row.candidates.length - 20) + ' more candidates not shown.'));
        item.appendChild(node('p', 'Possible candidates (not confirmed):', 'muted'));
        item.appendChild(candidates);
      }
      list.appendChild(item);
    }
    wrapper.appendChild(list);
    return wrapper;
  }

  function renderDetails(report, filteredEdges, selection) {
    container.replaceChildren();
    const file = report.nodes.find(item => item.id === selection.selected);
    if (!file) { container.appendChild(node('p', 'Select a file to see its declarations and connections.', 'empty')); return; }
    const path = node('p', file.path, 'detail-path'); path.tabIndex = -1; container.appendChild(path);
    container.appendChild(node('p', 'Parse state: ' + file.parseState, 'muted'));
    container.appendChild(section('Declarations', file.declarations.map(item => item.kind + ' ' + item.name + ' · line ' + item.line)));
    if (report.schemaVersion === 0) {
      container.appendChild(section('Method declarations', ['Unavailable in this older report. Rescan to index methods.']));
    } else if (file.parseState !== 'clean') {
      container.appendChild(section('Method declarations', ['Unavailable because this file did not parse cleanly.']));
    } else {
      const shown = visibleMethods(file.methods, search.value);
      const methods = section('Method declarations', shown.map(item => item.owner + '::' + item.name + ' · ' + item.visibility +
        (item.static ? ' static' : ' instance') + ' · line ' + item.line));
      if (file.methods.length > shown.length) methods.appendChild(node('p', 'Showing ' + shown.length + ' of ' + file.methods.length + ' methods. Search for a name to bring its matches first.', 'muted'));
      container.appendChild(methods);
    }
    const outgoing = filteredEdges.filter(edge => edge.from === file.id).map(edge => ({
      selected: edge.id === selection.selectedEdge,
      prefix: (edge.id === selection.selectedEdge ? 'Selected: ' : '') + edge.type + ' · line ' + edge.line + ' · ' + labelConfidence(edge.confidence) + ' · ',
      fileId: edge.to, path: edge.to ? edge.to.slice(5) : edge.reasonCode,
      suffix: edge.to ? ' · ' + edge.reasonCode : '',
      candidates: edge.to === null ? edge.target.candidates : [],
    }));
    container.appendChild(section('Outgoing references', outgoing));
    container.appendChild(section('Incoming references', filteredEdges.filter(edge => edge.to === file.id && edge.from !== file.id)
      .map(edge => ({ prefix: '', fileId: edge.from, path: edge.from.slice(5),
        suffix: ' · ' + edge.type + ' · line ' + edge.line + ' · ' + labelConfidence(edge.confidence) + ' · ' + edge.reasonCode }))));
    container.appendChild(section('Database indicators', (report.findings || []).filter(item => item.fileId === file.id)
      .map(item => item.api + ' · line ' + item.line + ' · Inferred')));
  }

  return renderDetails;
}
