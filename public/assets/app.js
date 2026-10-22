import { readReportFile, validateReport, hotspots } from './report.js';

const byId = id => document.getElementById(id);
const state = { report: null, selected: null, selectedEdge: null, sort: 'score', ascending: false, loadSequence: 0 };
const message = text => { byId('message').textContent = text; };
const node = (tag, text, className) => {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
};
let graphController = null;
let graphUnavailable = false;

async function prepareGraph() {
  try {
    const { createGraphController } = await import('./graph.js');
    graphController = createGraphController(byId('graph'), byId('graph-tooltip'), byId('graph-status'), byId('graph-zoom-level'), selectFile, selectReference, (available, clustersAllowed, layout) => {
      for (const id of ['graph-zoom-in', 'graph-zoom-out', 'graph-fit', 'graph-layout']) byId(id).disabled = !available;
      if (available) {
        byId('graph-layout').querySelector('option[value="cose"]').disabled = !clustersAllowed;
        byId('graph-layout').value = layout;
      }
    }, available => { byId('graph-focus').disabled = !available; });
    for (const id of ['graph-self', 'graph-neighbors']) byId(id).disabled = false;
    if (state.report) render();
  } catch (error) {
    graphUnavailable = true;
    byId('graph-status').textContent = 'The graph could not be loaded. The file list and details remain available.';
  }
}

function selectFile(id, focusDetails = false) {
  const file = state.report.nodes.find(item => item.id === id);
  const query = byId('search').value.trim().toLowerCase();
  if (focusDetails && file && query && !file.path.toLowerCase().includes(query)) {
    byId('search').value = file.path;
  }
  state.selected = id; state.selectedEdge = null; render();
  if (focusDetails) { byId('details').querySelector('.detail-path')?.focus({ preventScroll: true }); return; }
  const selected = Array.from(byId('files').querySelectorAll('button')).find(button => button.dataset.fileId === id);
  if (selected) selected.focus({ preventScroll: true });
}
function selectReference(id) {
  const edge = state.report.edges.find(item => item.id === id);
  if (!edge) return;
  state.selected = edge.from;
  state.selectedEdge = edge.id;
  render();
}
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
    list.appendChild(item);
  }
  wrapper.appendChild(list);
  return wrapper;
}

function details(report, filteredEdges) {
  const container = byId('details');
  container.replaceChildren();
  const file = report.nodes.find(item => item.id === state.selected);
  if (!file) { container.appendChild(node('p', 'Select a file to see its declarations and connections.', 'empty')); return; }
  const path = node('p', file.path, 'detail-path'); path.tabIndex = -1; container.appendChild(path);
  container.appendChild(node('p', 'Parse state: ' + file.parseState, 'muted'));
  container.appendChild(section('Declarations', file.declarations.map(item => item.kind + ' ' + item.name + ' · line ' + item.line)));
  const outgoing = filteredEdges.filter(edge => edge.from === file.id).map(edge => ({
    selected: edge.id === state.selectedEdge,
    prefix: (edge.id === state.selectedEdge ? 'Selected: ' : '') + edge.type + ' · line ' + edge.line + ' · ' + labelConfidence(edge.confidence) + ' · ',
    fileId: edge.to, path: edge.to ? edge.to.slice(5) : edge.reasonCode,
    suffix: edge.target.candidates.length && !edge.to ? ' · candidates: ' + edge.target.candidates.map(id => id.slice(5)).join(', ') : '',
  }));
  container.appendChild(section('Outgoing references', outgoing));
  container.appendChild(section('Incoming references', filteredEdges.filter(edge => edge.to === file.id && edge.from !== file.id)
    .map(edge => ({ prefix: '', fileId: edge.from, path: edge.from.slice(5),
      suffix: ' · ' + edge.type + ' · line ' + edge.line + ' · ' + labelConfidence(edge.confidence) }))));
  container.appendChild(section('Database indicators', (report.findings || []).filter(item => item.fileId === file.id)
    .map(item => item.api + ' · line ' + item.line + ' · Inferred')));
}

function render() {
  const report = state.report;
  if (!report) return;
  byId('dashboard').hidden = false;
  byId('project-name').textContent = report.scan.rootLabel;
  byId('file-count').textContent = report.nodes.length;
  byId('edge-count').textContent = report.edges.length;
  byId('unresolved-count').textContent = report.edges.filter(edge => edge.confidence === 'unresolved').length;
  byId('finding-count').textContent = (report.findings || []).length;
  byId('warning-count').textContent = report.scan.warnings.length + ' TOTAL';
  byId('graph-clear').disabled = state.selected === null;
  byId('graph-selection').textContent = state.selected === null
    ? 'Select a file to highlight its connections. Hover over a node to see its full path.'
    : 'Selected: ' + state.selected.slice(5) + '. Its visible connections are highlighted.';
  const query = byId('search').value.trim().toLowerCase();
  const type = byId('type-filter').value, confidence = byId('confidence-filter').value;
  const visible = report.nodes.filter(file => file.path.toLowerCase().includes(query));
  const filteredEdges = report.edges.filter(edge => (type === 'all' || edge.type === type) && (confidence === 'all' || edge.confidence === confidence));
  byId('list-count').textContent = visible.length + ' of ' + report.nodes.length;
  const fileList = byId('files'); fileList.replaceChildren();
  for (const file of visible) {
    const button = node('button', undefined, 'file-item' + (file.id === state.selected ? ' selected' : ''));
    button.type = 'button'; button.dataset.fileId = file.id; button.setAttribute('aria-pressed', String(file.id === state.selected));
    button.appendChild(node('span', file.path)); button.appendChild(node('small', file.parseState));
    button.addEventListener('click', () => selectFile(file.id)); fileList.appendChild(button);
  }
  if (graphController) {
    graphController.update(visible, filteredEdges, state.selected, state.selectedEdge, {
      allNodes: report.nodes, includeNeighbors: query !== '' && byId('graph-neighbors').checked,
    }).catch(() => {
      byId('graph-status').textContent = 'The graph could not be drawn. The file list and details remain available.';
    });
  } else if (!graphUnavailable) {
    byId('graph-status').textContent = 'Preparing the dependency map…';
  }
  details(report, filteredEdges);
  const rows = hotspots(report).sort((a, b) => {
    const comparison = typeof a[state.sort] === 'string' ? a[state.sort].localeCompare(b[state.sort]) : a[state.sort] - b[state.sort];
    return (state.ascending ? comparison : -comparison) || a.path.localeCompare(b.path);
  });
  const body = byId('hotspots'); body.replaceChildren();
  for (const row of rows) {
    const tr = node('tr');
    const cell = node('td'); const button = node('button', row.path, 'file-item'); button.type = 'button';
    button.addEventListener('click', () => selectFile(row.id)); cell.appendChild(button); tr.appendChild(cell);
    for (const field of ['inbound', 'outbound', 'database', 'score']) tr.appendChild(node('td', String(row[field])));
    body.appendChild(tr);
  }
  const warnings = byId('warnings'); warnings.replaceChildren();
  if (!report.scan.warnings.length) warnings.appendChild(node('li', 'No diagnostics in this report.'));
  else for (const warning of report.scan.warnings) warnings.appendChild(node('li', warning.fileId.slice(5) + (warning.line ? ':' + warning.line : '') + ' · ' + warning.code));
}

function setReport(report, isDemo = false) {
  state.report = validateReport(report);
  byId('demo-context').hidden = !isDemo;
  state.selected = null;
  state.selectedEdge = null;
  byId('search').value = isDemo ? 'Capsule/Manager.php' : '';
  byId('confidence-filter').value = 'all';
  const filter = byId('type-filter'); filter.replaceChildren(node('option', 'All types'));
  filter.firstChild.value = 'all';
  for (const type of [...new Set(report.edges.map(edge => edge.type))].sort()) {
    const option = node('option', type); option.value = type; filter.appendChild(option);
  }
  message(''); render();
}

function download(name, contents, mime) {
  const url = URL.createObjectURL(new Blob([contents], { type: mime }));
  const link = node('a'); link.href = url; link.download = name; document.body.appendChild(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

byId('import').addEventListener('change', async event => {
  const file = event.target.files[0]; if (!file) return;
  const sequence = ++state.loadSequence;
  try { const report = await readReportFile(file); if (sequence === state.loadSequence) setReport(report); }
  catch (error) { if (sequence === state.loadSequence) message('Could not open report. The previous report is unchanged: ' + error.message); }
  event.target.value = '';
});
for (const id of ['search', 'type-filter', 'confidence-filter']) byId(id).addEventListener(id === 'search' ? 'input' : 'change', render);
byId('graph-zoom-in').addEventListener('click', () => graphController?.zoom(1.25));
byId('graph-zoom-out').addEventListener('click', () => graphController?.zoom(0.8));
byId('graph-fit').addEventListener('click', () => graphController?.fit());
byId('graph-focus').addEventListener('click', () => graphController?.focusSelection());
byId('graph-clear').addEventListener('click', () => { state.selected = null; state.selectedEdge = null; render(); });
byId('graph-layout').addEventListener('change', event => graphController?.setLayout(event.target.value));
byId('graph-self').addEventListener('change', event => graphController?.setIncludeSelf(event.target.checked));
byId('graph-neighbors').addEventListener('change', render);
document.querySelectorAll('[data-sort]').forEach(button => button.addEventListener('click', () => {
  const field = button.dataset.sort;
  state.ascending = state.sort === field ? !state.ascending : field === 'path'; state.sort = field; render();
}));
byId('export-json').addEventListener('click', () => download('unknot-report.json', JSON.stringify(state.report, null, 2) + '\n', 'application/json'));
byId('export-summary').addEventListener('click', () => {
  const report = state.report;
  const top = hotspots(report).sort((a, b) => b.score - a.score || a.path.localeCompare(b.path)).slice(0, 10);
  const lines = ['Unknot findings summary', 'Project: ' + report.scan.rootLabel, 'Files: ' + report.nodes.length,
    'References: ' + report.edges.length, 'Unresolved: ' + report.edges.filter(edge => edge.confidence === 'unresolved').length,
    'Diagnostics: ' + report.scan.warnings.length, 'Database indicators: ' + (report.findings || []).length,
    '', 'Hotspot score: 2 × inbound + outbound + 3 × query indicators (navigation aid, not risk).',
    ...top.map(row => row.path + ': ' + row.score + ' (in ' + row.inbound + ', out ' + row.outbound + ', DB queries ' + row.database + ')'),
    '', 'Static analysis is incomplete. Inferred relationships and database indicators are not verified runtime behavior.'];
  download('unknot-summary.txt', lines.join('\n') + '\n', 'text/plain');
});

prepareGraph();
const token = location.hash.slice(1);
const isDemo = new URLSearchParams(location.search).get('demo') === 'laravel';
history.replaceState(null, '', location.pathname);
if (/^[a-f0-9]{64}$/.test(token)) {
  const sequence = ++state.loadSequence;
  fetch('/api/report', { headers: { Authorization: 'Bearer ' + token }, cache: 'no-store', credentials: 'omit' })
    .then(response => { if (!response.ok) throw new Error('The selected report is unavailable.'); return response.json(); })
    .then(report => { if (sequence === state.loadSequence) setReport(report, isDemo); })
    .catch(error => { if (sequence === state.loadSequence) message(error.message + ' Choose a local report to continue.'); });
} else message('Choose a local JSON report to begin.');
