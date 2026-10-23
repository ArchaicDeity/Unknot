// The report contains declarations, not a complete method or runtime-use index.
export function buildSearchIndex(nodes) {
  return nodes.map(file => ({
    file,
    path: file.path.toLowerCase(),
    declarations: file.declarations.map(item => ({ item, name: item.name.toLowerCase(), kind: item.kind.toLowerCase() })),
  }));
}

export function searchFiles(index, query) {
  const term = query.trim().toLowerCase();
  return index.flatMap(entry => {
    if (!term) return [{ file: entry.file, declarations: [], extra: 0 }];
    const matches = entry.declarations.filter(item => item.name.includes(term) || item.kind.includes(term));
    if (!entry.path.includes(term) && !matches.length) return [];
    return [{ file: entry.file, declarations: matches.slice(0, 3).map(match => match.item), extra: Math.max(0, matches.length - 3) }];
  });
}

export function fileMatchesQuery(index, fileId, query) {
  const entry = index.find(item => item.file.id === fileId);
  return entry !== undefined && searchFiles([entry], query).length > 0;
}
