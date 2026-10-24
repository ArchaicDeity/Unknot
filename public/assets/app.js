import { readReportFile, validateReport, hotspots } from './report.js';
import { buildSearchIndex, searchFiles, fileMatchesQuery, visibleMethods } from './search.js';
import { expandNeighborhood } from './graph-model.js';
import { inDirectory } from './overview-model.js';
import { createOverviewController } from './overview.js';
import { createImpactController } from './impact.js';
import { createSourceCheckController } from './source-check.js';

const byId = id => document.getElementById(id);
const state = { report: null, searchIndex: [], directory: null, selected: null, selectedEdge: null, history: [], exploration: null, explorationMessage: '', sort: 'score', ascending: false, loadSequence: 0 };
const message = text => { byId('message').textContent = text; };
const node = (tag, text, className) => {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
};
let graphController = null;
let graphUnavailable = false;
const sourceCheck = createSourceCheckController({ input: byId('source-check-file'), choose: byId('source-check-choose'), status: byId('source-check-status') });
const impact = createImpactController({ type: byId('impact-type'), inferred: byId('impact-inferred'), status: byId('impact-status'), results: byId('impact-results') }, selectFile);
const overview = createOverviewController({
  scope: byId('overview-scope'), all: byId('directory-all'), directories: byId('directory-list'),
  more: byId('directory-more'), filterStatus: byId('directory-status'), reasons: byId('overview-reasons'),
}, directory => {
  state.directory = directory;
  state.exploration = null;
  state.explorationMessage = '';
  overview.setDirectory(directory);
  render();
});

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

function rememberSelection() {
  if (state.selected === null) return;
  state.history.push({ fileId: state.selected, edgeId: state.selectedEdge, query: byId('search').value, directory: state.directory });
  if (state.history.length > 100) state.history.shift();
}
function selectFile(id, focusDetails = false) {
  const file = state.report.nodes.find(item => item.id === id);
  if (!file) return;
  if (state.selected !== id) rememberSelection();
  if (state.exploration && !state.exploration.ids.includes(id)) {
    state.exploration = null;
    state.explorationMessage = 'Focused view closed because the selected file was outside it.';
  }
  if (!state.exploration && !inDirectory(file.path, state.directory)) {
    state.directory = null;
    overview.setDirectory(null);
  }
  const query = byId('search').value.trim().toLowerCase();
  if (focusDetails && !state.exploration && query && !fileMatchesQuery(state.searchIndex, id, query)) {
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
  if (state.selected !== edge.from) rememberSelection();
  const file = state.report.nodes.find(item => item.id === edge.from);
  if (!state.exploration && file && !inDirectory(file.path, state.directory)) { state.directory = null; overview.setDirectory(null); }
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

function details(report, filteredEdges) {
  const container = byId('details');
  container.replaceChildren();
  const file = report.nodes.find(item => item.id === state.selected);
  if (!file) { container.appendChild(node('p', 'Select a file to see its declarations and connections.', 'empty')); return; }
  const path = node('p', file.path, 'detail-path'); path.tabIndex = -1; container.appendChild(path);
  container.appendChild(node('p', 'Parse state: ' + file.parseState, 'muted'));
  container.appendChild(section('Declarations', file.declarations.map(item => item.kind + ' ' + item.name + ' · line ' + item.line)));
  if (report.schemaVersion === 0) {
    container.appendChild(section('Method declarations', ['Unavailable in this older report. Rescan to index methods.']));
  } else if (file.parseState !== 'clean') {
    container.appendChild(section('Method declarations', ['Unavailable because this file did not parse cleanly.']));
  } else {
    const shown = visibleMethods(file.methods, byId('search').value);
    const methods = section('Method declarations', shown.map(item => item.owner + '::' + item.name + ' · ' + item.visibility +
      (item.static ? ' static' : ' instance') + ' · line ' + item.line));
    if (file.methods.length > shown.length) methods.appendChild(node('p', 'Showing ' + shown.length + ' of ' + file.methods.length + ' methods. Search for a name to bring its matches first.', 'muted'));
    container.appendChild(methods);
  }
  const outgoing = filteredEdges.filter(edge => edge.from === file.id).map(edge => ({
    selected: edge.id === state.selectedEdge,
    prefix: (edge.id === state.selectedEdge ? 'Selected: ' : '') + edge.type + ' · line ' + edge.line + ' · ' + labelConfidence(edge.confidence) + ' · ',
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

function render() {
  const report = state.report;
  if (!report) return;
  byId('graph-clear').disabled = state.selected === null;
  byId('details-back').disabled = state.history.length === 0;
  byId('explore-start').disabled = state.selected === null;
  byId('explore-expand').disabled = !state.exploration;
  byId('explore-back').disabled = !state.exploration || state.exploration.history.length === 0;
  byId('explore-exit').disabled = !state.exploration;
  byId('graph-neighbors').disabled = !!state.exploration || !graphController;
  byId('exploration-status').textContent = state.explorationMessage || 'Select a file to explore its reported connections one step at a time.';
  byId('graph-selection').textContent = state.selected === null
    ? 'Select a file to highlight its connections. Hover over a node to see its full path.'
    : 'Selected: ' + state.selected.slice(5) + '. Its visible connections are highlighted.';
  const query = byId('search').value.trim().toLowerCase();
  const type = byId('type-filter').value, confidence = byId('confidence-filter').value;
  const focusedIds = state.exploration ? new Set(state.exploration.ids) : null;
  const results = searchFiles(state.searchIndex, focusedIds ? '' : query)
    .filter(result => focusedIds ? focusedIds.has(result.file.id) : inDirectory(result.file.path, state.directory));
  const visible = results.map(result => result.file);
  const filteredEdges = report.edges.filter(edge => (type === 'all' || edge.type === type) && (confidence === 'all' || edge.confidence === confidence));
  byId('list-count').textContent = focusedIds ? visible.length + ' in focused view' : visible.length + ' of ' + report.nodes.length;
  const fileList = byId('files'); fileList.replaceChildren();
  for (const result of results) {
    const file = result.file;
    const button = node('button', undefined, 'file-item' + (file.id === state.selected ? ' selected' : ''));
    button.type = 'button'; button.dataset.fileId = file.id; button.setAttribute('aria-pressed', String(file.id === state.selected));
    const label = node('span', undefined, 'file-label'); label.appendChild(node('span', file.path));
    for (const declaration of result.declarations) label.appendChild(node('span', declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line, 'search-match'));
    for (const method of result.methods) label.appendChild(node('span', 'method ' + method.owner + '::' + method.name + ' · ' + method.visibility + (method.static ? ' static' : ' instance') + ' · line ' + method.line, 'search-match'));
    if (result.extra) label.appendChild(node('span', result.extra + ' more matching declarations', 'search-match'));
    button.appendChild(label); button.appendChild(node('small', file.parseState));
    button.addEventListener('click', () => selectFile(file.id)); fileList.appendChild(button);
  }
  if (graphController) {
    graphController.update(visible, filteredEdges, state.selected, state.selectedEdge, {
      allNodes: report.nodes, includeNeighbors: !focusedIds && (query !== '' || state.directory !== null) && byId('graph-neighbors').checked,
      focusedExploration: !!focusedIds,
    }).catch(() => {
      byId('graph-status').textContent = 'The graph could not be drawn. The file list and details remain available.';
    });
  } else if (!graphUnavailable) {
    byId('graph-status').textContent = 'Preparing the dependency map…';
  }
  details(report, filteredEdges);
  sourceCheck.update(report, report.nodes.find(file => file.id === state.selected) || null);
  impact.setSelection(state.selected);
}

function renderHotspots() {
  const report = state.report;
  if (!report) return;
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
}

function renderReportSummary(report) {
  byId('dashboard').hidden = false;
  byId('project-name').textContent = report.scan.rootLabel;
  byId('file-count').textContent = report.nodes.length;
  byId('edge-count').textContent = report.edges.length;
  byId('unresolved-count').textContent = report.edges.filter(edge => edge.confidence === 'unresolved').length;
  byId('finding-count').textContent = (report.findings || []).length;
  byId('warning-count').textContent = report.scan.warnings.length + ' TOTAL';
  const warnings = byId('warnings'); warnings.replaceChildren();
  if (!report.scan.warnings.length) warnings.appendChild(node('li', 'No diagnostics in this report.'));
  else for (const warning of report.scan.warnings) warnings.appendChild(node('li', warning.fileId.slice(5) + (warning.line ? ':' + warning.line : '') + ' · ' + warning.code));
}

function setReport(report, isDemo = false) {
  state.report = validateReport(report);
  impact.setReport(report);
  byId('search-scope').textContent = report.schemaVersion === 0
    ? 'This older report does not index method declarations. Rescan to search methods. Method calls and runtime uses are not indexed.'
    : 'Searches paths and reported declarations, including methods on named types. Method calls and runtime uses are not indexed.';
  state.searchIndex = buildSearchIndex(report.nodes, report.schemaVersion >= 1);
  state.directory = null;
  overview.setReport(report);
  byId('demo-context').hidden = !isDemo;
  state.selected = null;
  state.selectedEdge = null;
  state.history = [];
  state.exploration = null;
  state.explorationMessage = '';
  byId('search').value = isDemo ? 'Capsule/Manager.php' : '';
  byId('confidence-filter').value = 'all';
  const filter = byId('type-filter'); filter.replaceChildren(node('option', 'All types'));
  filter.firstChild.value = 'all';
  for (const type of [...new Set(report.edges.map(edge => edge.type))].sort()) {
    const option = node('option', type); option.value = type; filter.appendChild(option);
  }
  message(''); renderReportSummary(report); renderHotspots(); render();
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
byId('search').addEventListener('input', () => {
  state.exploration = null;
  state.explorationMessage = '';
  render();
});
for (const id of ['type-filter', 'confidence-filter']) byId(id).addEventListener('change', () => {
  if (state.exploration) {
    state.exploration = { ids: [state.selected], history: [] };
    state.explorationMessage = 'Filters changed. The focused view restarted from the selected file.';
  }
  render();
});
byId('graph-zoom-in').addEventListener('click', () => graphController?.zoom(1.25));
byId('graph-zoom-out').addEventListener('click', () => graphController?.zoom(0.8));
byId('graph-fit').addEventListener('click', () => graphController?.fit());
byId('graph-focus').addEventListener('click', () => graphController?.focusSelection());
byId('graph-clear').addEventListener('click', () => { state.selected = null; state.selectedEdge = null; state.history = []; state.exploration = null; state.explorationMessage = ''; render(); });
byId('details-back').addEventListener('click', () => {
  const previous = state.history.pop();
  if (!previous) return;
  byId('search').value = previous.query;
  state.directory = previous.directory;
  overview.setDirectory(state.directory);
  if (state.exploration && !state.exploration.ids.includes(previous.fileId)) {
    state.exploration = null;
    state.explorationMessage = 'Focused view closed because the previous file was outside it.';
  }
  state.selected = previous.fileId; state.selectedEdge = previous.edgeId; render();
  byId('details').querySelector('.detail-path')?.focus({ preventScroll: true });
});
byId('explore-start').addEventListener('click', () => {
  if (state.selected === null) return;
  state.exploration = { ids: [state.selected], history: [] };
  state.explorationMessage = 'Focused view: 1 file. Expand to add its reported incoming and outgoing connections.';
  render();
});
byId('explore-expand').addEventListener('click', () => {
  if (!state.exploration) return;
  const ids = new Set(state.exploration.ids);
  const current = state.report.nodes.filter(file => ids.has(file.id));
  const type = byId('type-filter').value, confidence = byId('confidence-filter').value;
  const edges = state.report.edges.filter(edge => (type === 'all' || edge.type === type) && (confidence === 'all' || edge.confidence === confidence));
  const next = expandNeighborhood(current, state.report.nodes, edges, { includeSelf: byId('graph-self').checked });
  if (next.reason) {
    state.explorationMessage = next.reason === 'node-limit' ? 'Expansion would exceed the 500-file graph limit. Narrow the reference filters.'
      : 'Expansion would exceed the 2,000-connection graph limit. Narrow the reference filters.';
  } else if (next.nodes.length === current.length) {
    state.explorationMessage = 'No additional reported connections match the current filters.';
  } else {
    state.exploration.history.push(state.exploration.ids);
    state.exploration.ids = next.nodes.map(file => file.id);
    state.explorationMessage = 'Focused view: ' + next.nodes.length + ' files. Reported incoming and outgoing connections are included; inferred connections are dashed and unresolved targets are not drawn.';
  }
  render();
});
byId('explore-back').addEventListener('click', () => {
  if (!state.exploration || !state.exploration.history.length) return;
  state.exploration.ids = state.exploration.history.pop();
  if (!state.exploration.ids.includes(state.selected)) {
    state.selected = state.exploration.ids[0]; state.selectedEdge = null;
  }
  state.explorationMessage = 'Focused view: ' + state.exploration.ids.length + ' files after undoing the last expansion.';
  render();
});
byId('explore-exit').addEventListener('click', () => {
  state.exploration = null;
  state.explorationMessage = 'Showing search results again.';
  render();
});
byId('graph-layout').addEventListener('change', event => graphController?.setLayout(event.target.value));
byId('graph-self').addEventListener('change', event => graphController?.setIncludeSelf(event.target.checked));
byId('graph-neighbors').addEventListener('change', render);
document.querySelectorAll('[data-sort]').forEach(button => button.addEventListener('click', () => {
  const field = button.dataset.sort;
  state.ascending = state.sort === field ? !state.ascending : field === 'path'; state.sort = field; renderHotspots();
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
