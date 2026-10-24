// Paths, file kinds and renames of folders with similar names. node tests/workspace.cjs
const assert = require('node:assert/strict');
const { loadEditor } = require('./harness.cjs');

const { Lx, stop } = loadEditor();
assert.equal(Lx.kind('fig/plot.PNG'), 'image');
assert.equal(Lx.kind('paper.pdf'), 'pdf');
assert.equal(Lx.kind('refs.bib'), 'text');
assert.equal(Lx.kind('archive.zip'), 'binary');
assert.equal(Lx.modeFor('main.tex'), 'stex');
assert.equal(Lx.modeFor('notes.md'), 'markdown');
assert.equal(Lx.modeFor('README'), 'null');
console.log('  ok  file kinds and editor modes');

assert.ok(Lx.within('chapters/a.tex', 'chapters'));
assert.ok(Lx.within('chapters', 'chapters'));
assert.ok(!Lx.within('chapters2/a.tex', 'chapters'), 'a folder with a longer name is not inside');
assert.equal(Lx.moved('chapters/a.tex', 'chapters', 'parts'), 'parts/a.tex');
assert.equal(Lx.moved('chapters2/a.tex', 'chapters', 'parts'), 'chapters2/a.tex');
assert.equal(Lx.moved('main.tex', 'main.tex', 'thesis.tex'), 'thesis.tex');
console.log('  ok  renames move what is inside a folder, and nothing else');

Lx.collab.open('chapters/a.tex');
Lx.collab.open('chapters2/b.tex');
Lx.collab.remap('chapters', 'parts');
assert.ok(Lx.collab.session('parts/a.tex') && Lx.collab.session('chapters2/b.tex') && !Lx.collab.session('chapters/a.tex'));
Lx.collab.removed('parts');
assert.ok(!Lx.collab.session('parts/a.tex') && Lx.collab.session('chapters2/b.tex'));
console.log('  ok  open files follow a folder rename and go with its removal');
stop();
console.log('Workspace passed.');
