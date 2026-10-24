import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/search.js', import.meta.url));
const { buildSearchIndex, searchFiles, fileMatchesQuery, visibleMethods } = await import('data:text/javascript;base64,' + source.toString('base64'));
const files = [
  { id: 'file:first.php', path: 'first.php', declarations: [{ name: 'App\\Shared', kind: 'class', line: 3 }] },
  { id: 'file:second.php', path: 'second.php', declarations: [{ name: 'App\\Shared', kind: 'class', line: 8 }] },
  { id: 'file:contracts.php', path: 'contracts.php', declarations: [{ name: 'App\\Contract', kind: 'interface', line: 4 }] },
];
const index = buildSearchIndex(files);
assert.deepEqual(searchFiles(index, ' APP\\SHARED ').map(row => row.file.id), ['file:first.php', 'file:second.php']);
assert.deepEqual(searchFiles(index, 'interface').map(row => row.file.id), ['file:contracts.php']);
assert.deepEqual(searchFiles(index, 'first.php').map(row => row.file.id), ['file:first.php']);
assert.equal(searchFiles(index, 'methodNotInReport').length, 0);
assert.equal(searchFiles(index, '').length, 3);
assert.equal(searchFiles(index, 'shared')[1].declarations[0].line, 8);
assert.equal(fileMatchesQuery(index, 'file:first.php', 'app\\shared'), true);
assert.equal(fileMatchesQuery(index, 'file:missing.php', ''), false);
const crowded = buildSearchIndex([{ id: 'file:many.php', path: 'many.php', declarations: Array.from({ length: 7 }, (_, n) => ({ name: 'Match' + n, kind: 'class', line: n + 1 })) }]);
assert.equal(searchFiles(crowded, 'match')[0].declarations.length, 3);
assert.equal(searchFiles(crowded, 'match')[0].extra, 4);
assert.equal(files[0].declarations[0].name, 'App\\Shared');
const withMethods = [
  { id: 'file:one.php', path: 'one.php', declarations: [], methods: [
    { owner: 'App\\First', name: 'handleRequest', line: 7, visibility: 'public', static: false },
    { owner: 'App\\First', name: 'boot', line: 9, visibility: 'protected', static: true },
  ] },
  { id: 'file:two.php', path: 'two.php', declarations: [], methods: [
    { owner: 'App\\Second', name: 'handleRequest', line: 17, visibility: 'private', static: false },
  ] },
];
const methodIndex = buildSearchIndex(withMethods, true);
assert.deepEqual(searchFiles(methodIndex, 'HANDLEREQUEST').map(row => row.file.id), ['file:one.php', 'file:two.php']);
assert.equal(searchFiles(methodIndex, 'app\\first::boot')[0].methods[0].static, true);
assert.equal(searchFiles(methodIndex, 'handleRequest')[1].methods[0].line, 17);
assert.equal(searchFiles(buildSearchIndex(withMethods), 'handleRequest').length, 0, 'Old reports must not infer method coverage from unvalidated extra fields.');
assert.equal(fileMatchesQuery(methodIndex, 'file:one.php', 'First::boot'), true);
const manyMethods = Array.from({ length: 150 }, (_, n) => ({ owner: 'App\\Large', name: 'method' + n }));
assert.equal(visibleMethods(manyMethods, '').length, 100);
assert.equal(visibleMethods(manyMethods, 'method149')[0].name, 'method149');
assert.equal(visibleMethods(manyMethods, 'no-match').length, 100);
console.log('Declaration search checks passed.');
