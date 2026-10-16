import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

for (const [installed, bundled] of [
  ['../node_modules/cytoscape/dist/cytoscape.esm.min.mjs', '../public/assets/vendor/cytoscape.esm.min.mjs'],
  ['../node_modules/cytoscape/LICENSE', '../public/assets/vendor/cytoscape.LICENSE.txt'],
]) {
  const expected = await readFile(new URL(installed, import.meta.url));
  const actual = await readFile(new URL(bundled, import.meta.url));
  assert.ok(actual.equals(expected), `Bundled asset differs from pinned dependency: ${bundled}`);
}
console.log('Bundled Cytoscape distribution and licence match the pinned package.');
