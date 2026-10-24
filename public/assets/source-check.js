const SOURCE_LIMIT = 2 * 1024 * 1024;

/** Compare only the bytes explicitly supplied through the browser's file picker. */
export async function compareSelectedFile(file, expected, cryptoApi = globalThis.crypto) {
  if (!file || !Number.isSafeInteger(file.size) || file.size < 0 || file.size > SOURCE_LIMIT) {
    return 'too-large';
  }
  if (!cryptoApi?.subtle) return 'unavailable';
  try {
    // A bounded slice also limits the read if the underlying file changes after selection.
    const bytes = await file.slice(0, SOURCE_LIMIT + 1).arrayBuffer();
    if (bytes.byteLength !== file.size || bytes.byteLength > SOURCE_LIMIT) return 'changed';
    const digest = await cryptoApi.subtle.digest('SHA-256', bytes);
    const actual = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
    return actual === expected ? 'match' : 'mismatch';
  } catch {
    return 'unreadable';
  }
}

export function createSourceCheckController(elements, compare = compareSelectedFile) {
  let report = null, selected = null, generation = 0;
  const messages = {
    match: 'The selected bytes match the report fingerprint. This does not verify the project or report; the file may change afterward.',
    mismatch: 'The selected bytes do not match. The file may have changed, or you may have chosen a different file or report.',
    'too-large': 'The selected file exceeds the 2 MiB limit or its size is unavailable.',
    changed: 'The selected file changed while it was being read. Choose it again to retry.',
    unavailable: 'Fingerprint comparison is unavailable in this browser context.',
    unreadable: 'The selected file could not be read. Choose it again to retry.',
  };
  elements.input.addEventListener('change', async () => {
    const file = elements.input.files?.[0];
    elements.input.value = '';
    if (!file || !selected || !report || report.schemaVersion !== 2 || !selected.sourceSha256) return;
    const request = ++generation;
    elements.status.textContent = 'Comparing the selected file locally…';
    const result = await compare(file, selected.sourceSha256);
    if (request === generation) elements.status.textContent = messages[result] || messages.unreadable;
  });
  return {
    update(nextReport, nextSelected) {
      if (report === nextReport && selected === nextSelected) return;
      report = nextReport; selected = nextSelected; generation++;
      elements.input.value = '';
      const available = !!report && report.schemaVersion === 2 && !!selected?.sourceSha256;
      elements.choose.hidden = !available;
      if (!selected) elements.status.textContent = 'Select a report file to compare it with a file you choose.';
      else if (report.schemaVersion < 2) elements.status.textContent = 'This report has no source fingerprints. Rescan with --source-fingerprints to compare a file.';
      else if (!available) elements.status.textContent = 'No fingerprint is available for this file because it was not parsed cleanly.';
      else elements.status.textContent = 'Choose a file to compare its bytes with this report. File contents stay in your browser and are not displayed.';
    },
  };
}
