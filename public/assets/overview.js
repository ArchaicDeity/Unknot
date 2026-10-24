import { buildOverview } from './overview-model.js';

const PAGE_SIZE = 30;
const LABELS = {
  'symbol-not-found': 'Symbol not found among scanned declarations',
  'late-static-binding': 'Late static binding depends on runtime context',
  'dynamic-expression': 'Dynamic expression cannot be resolved statically',
  'trait-context-dependent': 'Trait reference depends on the using class',
  'ambiguous-symbol': 'Multiple possible declarations',
  'conditional-symbol': 'Declaration is conditional',
  'symbol-kind-mismatch': 'Declaration kind does not match',
  'include-target-not-resolved': 'Include target could not be resolved',
};

export function createOverviewController(elements, onDirectoryChange) {
  let overview = null;
  let active = null;
  let visible = PAGE_SIZE;
  let buttons = [];

  function updateSelection() {
    elements.all.setAttribute('aria-pressed', String(active === null));
    for (const entry of buttons) entry.button.setAttribute('aria-pressed', String(active === entry.directory));
    elements.filterStatus.textContent = active === null ? 'Showing all report files.'
      : 'Directory filter: ' + (active === '' ? '(root files)' : active);
  }

  function renderDirectories() {
    elements.directories.replaceChildren();
    buttons = [];
    for (const item of overview.directories.slice(0, visible)) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'directory-button';
      button.textContent = (item.directory === '' ? '(root files)' : item.directory) + ' · ' + item.count;
      button.addEventListener('click', () => onDirectoryChange(item.directory));
      elements.directories.appendChild(button);
      buttons.push({ directory: item.directory, button });
    }
    elements.more.hidden = visible >= overview.directories.length;
    elements.more.textContent = 'Show more folders (' + Math.max(0, overview.directories.length - visible) + ' remaining)';
    updateSelection();
  }

  elements.all.addEventListener('click', () => onDirectoryChange(null));
  elements.more.addEventListener('click', () => {
    visible += PAGE_SIZE;
    renderDirectories();
    if (elements.more.hidden && buttons.length) buttons[buttons.length - 1].button.focus();
  });

  return {
    setReport(report) {
      overview = buildOverview(report);
      active = null;
      visible = PAGE_SIZE;
      const { clean, failed, skipped } = overview.parse;
      elements.scope.textContent = report.nodes.length + (report.nodes.length === 1 ? ' report file: ' : ' report files: ') + clean + ' parsed cleanly, ' + failed +
        ' failed, ' + skipped + ' skipped; ' + overview.warnings + (overview.warnings === 1 ? ' diagnostic.' : ' diagnostics.') +
        ' Excluded or unseen files may be absent.';
      elements.reasons.replaceChildren();
      if (overview.unresolved === 0) {
        const item = document.createElement('li');
        item.textContent = 'No unresolved references recorded.';
        elements.reasons.appendChild(item);
      } else {
        for (const reason of overview.reasons.slice(0, 8)) {
          const item = document.createElement('li');
          item.textContent = (LABELS[reason.code] || reason.code) + ' · ' + reason.count;
          elements.reasons.appendChild(item);
        }
        if (overview.reasons.length > 8) {
          const remaining = overview.reasons.slice(8).reduce((sum, item) => sum + item.count, 0);
          const item = document.createElement('li');
          item.textContent = remaining + ' references with other reasons.';
          elements.reasons.appendChild(item);
        }
      }
      renderDirectories();
    },
    setDirectory(directory) { active = directory; updateSelection(); },
  };
}
