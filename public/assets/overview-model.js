/** Summarize only facts present in a validated report. */
export function directoryOf(path) {
  const slash = path.indexOf('/');
  return slash === -1 ? '' : path.slice(0, slash + 1);
}

export function inDirectory(path, directory) {
  return directory === null || directoryOf(path) === directory;
}

export function buildOverview(report) {
  const directories = new Map();
  const parse = { clean: 0, failed: 0, skipped: 0 };
  for (const file of report.nodes) {
    const directory = directoryOf(file.path);
    directories.set(directory, (directories.get(directory) || 0) + 1);
    parse[file.parseState]++;
  }
  const reasons = new Map();
  let unresolved = 0;
  for (const edge of report.edges) {
    if (edge.confidence !== 'unresolved') continue;
    unresolved++;
    reasons.set(edge.reasonCode, (reasons.get(edge.reasonCode) || 0) + 1);
  }
  return {
    directories: Array.from(directories, ([directory, count]) => ({ directory, count }))
      .sort((a, b) => a.directory.localeCompare(b.directory)),
    parse,
    warnings: report.scan.warnings.length,
    unresolved,
    reasons: Array.from(reasons, ([code, count]) => ({ code, count }))
      .sort((a, b) => b.count - a.count || a.code.localeCompare(b.code)),
  };
}
