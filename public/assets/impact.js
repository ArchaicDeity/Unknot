import { analyzeImpact } from './impact-model.js';

const make = (tag, text, className) => {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
};

export function createImpactController(elements, selectFile) {
  let report = null, selected = null, previous = null;
  function render() {
    const key = [report, selected, elements.type.value, elements.inferred.checked];
    if (previous && key.every((value, index) => value === previous[index])) return;
    previous = key;
    elements.results.replaceChildren();
    if (!report || !selected) {
      elements.status.textContent = 'Select a file to inspect reported dependents.';
      return;
    }
    const analysis = analyzeImpact(report, selected, { type: elements.type.value, includeInferred: elements.inferred.checked });
    const limits = { 'file-limit': '500-file', 'edge-limit': '2,000-reference', 'depth-limit': 'five-hop' };
    elements.status.textContent = analysis.results.length + ' possible dependents of ' + selected.slice(5) + '.' +
      (analysis.truncated ? ' Exploration stopped at the ' + limits[analysis.truncated] + ' limit; additional results may exist.' : '');
    if (!analysis.results.length) elements.results.appendChild(make('p', 'No reported dependents found with these filters.', 'muted'));
    const list = make('ol', undefined, 'impact-list');
    for (const result of analysis.results) {
      const item = make('li');
      const heading = make('p', undefined, 'impact-heading');
      const file = make('button', result.file.path, 'reference-link'); file.type = 'button';
      file.addEventListener('click', () => selectFile(result.id, true)); heading.appendChild(file);
      heading.appendChild(document.createTextNode(' · ' + (result.depth === 1 ? 'direct' : result.depth + ' hops') + (result.inferred ? ' · includes inference' : ' · resolved (static)')));
      item.appendChild(heading);
      const path = make('ol', undefined, 'impact-path');
      for (const edge of result.path) {
        const step = make('li');
        const source = make('button', edge.from.slice(5), 'reference-link'); source.type = 'button';
        source.addEventListener('click', () => selectFile(edge.from, true)); step.appendChild(source);
        step.appendChild(document.createTextNode(':' + edge.line + ' · ' + edge.type + ' · ' + (edge.confidence === 'heuristic' ? 'inferred' : 'resolved (static)') + ' · ' + edge.reasonCode + ' → '));
        const target = make('button', edge.to.slice(5), 'reference-link'); target.type = 'button';
        target.addEventListener('click', () => selectFile(edge.to, true)); step.appendChild(target);
        path.appendChild(step);
      }
      item.appendChild(path); list.appendChild(item);
    }
    if (analysis.results.length) elements.results.appendChild(list);
  }
  elements.type.addEventListener('change', render);
  elements.inferred.addEventListener('change', render);
  return {
    setReport(value) {
      report = value; selected = null; previous = null;
      elements.inferred.checked = false;
      elements.type.replaceChildren();
      const all = make('option', 'All types'); all.value = 'all'; elements.type.appendChild(all);
      for (const type of [...new Set(report.edges.map(edge => edge.type))].sort()) {
        const option = make('option', type); option.value = type; elements.type.appendChild(option);
      }
      render();
    },
    setSelection(value) { selected = value; render(); },
  };
}
