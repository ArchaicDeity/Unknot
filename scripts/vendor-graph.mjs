import { copyFile, mkdir } from 'node:fs/promises';

const destination = new URL('../public/assets/vendor/', import.meta.url);
await mkdir(destination, { recursive: true });
await copyFile(new URL('../node_modules/cytoscape/dist/cytoscape.esm.min.mjs', import.meta.url), new URL('cytoscape.esm.min.mjs', destination));
await copyFile(new URL('../node_modules/cytoscape/LICENSE', import.meta.url), new URL('cytoscape.LICENSE.txt', destination));
console.log('Copied the pinned Cytoscape distribution and licence.');
