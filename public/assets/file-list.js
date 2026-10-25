export function createFileListRenderer({ container, selectFile, node }) {
  return (results, selectedId) => {
    container.replaceChildren();
    for (const result of results) {
      const file = result.file;
      const button = node('button', undefined, 'file-item' + (file.id === selectedId ? ' selected' : ''));
      button.type = 'button';
      button.dataset.fileId = file.id;
      button.setAttribute('aria-pressed', String(file.id === selectedId));
      const label = node('span', undefined, 'file-label');
      label.appendChild(node('span', file.path));
      for (const declaration of result.declarations) label.appendChild(node('span', declaration.kind + ' ' + declaration.name + ' · line ' + declaration.line, 'search-match'));
      for (const method of result.methods) label.appendChild(node('span', 'method ' + method.owner + '::' + method.name + ' · ' + method.visibility + (method.static ? ' static' : ' instance') + ' · line ' + method.line, 'search-match'));
      if (result.extra) label.appendChild(node('span', result.extra + ' more matching declarations', 'search-match'));
      button.appendChild(label);
      button.appendChild(node('small', file.parseState));
      button.addEventListener('click', () => selectFile(file.id));
      container.appendChild(button);
    }
  };
}
