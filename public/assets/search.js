// Method declarations do not establish method calls or runtime dispatch.
export function buildSearchIndex(nodes, includeMethods = false) {
  return nodes.map(file => ({
    file,
    path: file.path.toLowerCase(),
    declarations: file.declarations.map(item => ({ item, name: item.name.toLowerCase(), kind: item.kind.toLowerCase() })),
    methods: (includeMethods ? file.methods : []).map(item => ({ item, name: item.name.toLowerCase(), qualified: (item.owner + '::' + item.name).toLowerCase() })),
  }));
}

export function searchFiles(index, query) {
  const term = query.trim().toLowerCase();
  return index.flatMap(entry => {
    if (!term) return [{ file: entry.file, declarations: [], methods: [], extra: 0 }];
    const matches = entry.declarations.filter(item => item.name.includes(term) || item.kind.includes(term));
    const methods = entry.methods.filter(item => item.name.includes(term) || item.qualified.includes(term));
    if (!entry.path.includes(term) && !matches.length && !methods.length) return [];
    const shownDeclarations = matches.slice(0, 3);
    const shownMethods = methods.slice(0, 3 - shownDeclarations.length);
    return [{ file: entry.file, declarations: shownDeclarations.map(match => match.item), methods: shownMethods.map(match => match.item),
      extra: Math.max(0, matches.length + methods.length - 3) }];
  });
}

export function fileMatchesQuery(index, fileId, query) {
  const entry = index.find(item => item.file.id === fileId);
  return entry !== undefined && searchFiles([entry], query).length > 0;
}

/** Bound detail rendering and put method matches before unrelated declarations. */
export function visibleMethods(methods, query, limit = 100) {
  const term = query.trim().toLowerCase();
  const matching = [], other = [];
  for (const method of methods) {
    const text = (method.owner + '::' + method.name).toLowerCase();
    const bucket = term && text.includes(term) ? matching : other;
    if (bucket.length < limit) bucket.push(method);
  }
  return matching.concat(other).slice(0, limit);
}
