// The editor's live-editing client without a browser or server: transforms,
// diffs, and the client's handling of a scripted server. node tests/reliability.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { loadEditor, memoryStorage, read, root } = require('./harness.cjs');

const ok = (label) => console.log('  ok  ' + label);

// Every editor script parses, and the page's own inline script too.
for (const file of fs.readdirSync(root + '/js/editor')) new vm.Script(read('js/editor/' + file), { filename: file });
const page = read('edit.php');
for (const match of page.matchAll(/<script(?![^>]*\b(?:src|type)=)(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) new vm.Script(match[1].replace(/<\?[\s\S]*?\?>/g, 'null'));
assert.ok(!/document_parts|change_part|part_added|partButtons/.test(page + fs.readdirSync(root + '/js/editor').map((f) => read('js/editor/' + f)).join('')), 'nothing about sections is left');
ok('editor scripts parse, and nothing about sections is left');

/* ---------- Transforms and diffs ---------- */
const { Lx, context, stop } = loadEditor();
const Op = context.window.TextOperation;
const diff = Lx.collab._diff;
for (const [before, after] of [['hello', 'hello!'], ['A😀B', 'A😁B'], ['😀x', 'x😀'], ['', 'åäö'], ['abc', ''], ['same', 'same']]) {
    const ops = diff(before, after);
    assert.equal(before === after ? before : Op.fromJSON(ops).apply(before), after);
}
ok('diffs turn one text into another without splitting emoji');
let seed = 117;
const random = (n) => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed % n; };
for (let i = 0; i < 500; i++) {
    const base = 'abcdefghij';
    const change = () => { const at = random(base.length + 1); return base.slice(0, at) + ['X', 'å', '😀', ''][random(4)] + base.slice(at + random(base.length - at + 1)); };
    const make = (next) => (next === base ? new Op().retain(base.length) : Op.fromJSON(diff(base, next)));
    const a = make(change()), b = make(change());
    const [ap, bp] = Op.transform(a, b);
    assert.equal(ap.apply(b.apply(base)), bp.apply(a.apply(base)));
    const middle = a.apply(base);
    const next = '😀' + middle + 'å';
    assert.equal(Op.compose(a, Op.fromJSON(diff(middle, next))).apply(base), next);
}
ok('500 random concurrent edits converge, and composed buffers keep every edit');
const minimal = Lx.collab._minimalDiff;
assert.deepEqual(Array.from(minimal('abcdef', 'azcdyf')), [1, 'z', -1, 2, 'y', -1, 1], 'scattered changes stay separate');
for (let i = 0; i < 2000; i++) {
    let before = '';
    for (let j = random(30); j--;) before += ['a', 'b', '😀', '\n', 'c'][random(5)];
    let after = before;
    for (let j = random(6); j--;) { const chars = Array.from(after); const at = random(chars.length + 1); chars.splice(at, random(3), ...[['x'], ['😀'], []][random(3)]); after = chars.join(''); }
    const ops = minimal(before, after);
    assert.equal(before === after ? before : Op.fromJSON(ops).apply(before), after);
}
ok('the merge diff is minimal and correct on 2000 random edits, emoji included');
assert.equal(Lx.collab._transformIndex(5, [2, 'abc', 10]), 8);
assert.equal(Lx.collab._transformIndex(5, [2, -4, 6]), 2);
assert.equal(Lx.collab._transformIndex(1, [2, 'abc', 10]), 1);
ok('remote cursors move with edits before them');
stop();

/* ---------- A client against a scripted server ---------- */
class FakeSocket {
    static last = null;
    static OPEN = 1;
    constructor(url) { this.url = url; this.readyState = 0; this.sent = []; FakeSocket.last = this; setTimeout(() => { this.readyState = 1; this.onopen && this.onopen(); }, 0); }
    send(text) { this.sent.push(JSON.parse(text)); }
    close() { this.readyState = 3; this.onclose && this.onclose(); }
    receive(message) { this.onmessage({ data: JSON.stringify(message) }); }
    take(type) { const found = this.sent.filter((m) => m.type === type); this.sent = this.sent.filter((m) => m.type !== type); return found; }
}
const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

(async () => {
    const storage = memoryStorage();
    let page1 = loadEditor({ WebSocket: FakeSocket, sessionStorage: storage });
    page1.Lx.collab.connect();
    await tick();
    let socket = FakeSocket.last;
    const s = page1.Lx.collab.open('main.tex');
    const asked = socket.take('open_file');
    assert.equal(asked.length, 1);
    assert.equal(asked[0].path, 'main.tex');
    assert.equal(s.doc.replaceRange('x', 0), false, 'typing before the text arrives is refused');
    socket.receive({ type: 'file_state', path: 'main.tex', epoch: 'e1', rev: 3, content: 'hello' });
    assert.equal(s.doc.getValue(), 'hello');
    ok('opening a file asks the server and fills when it answers');

    s.doc.replaceRange(' world', 5);
    let [sent] = socket.take('ot_update');
    assert.deepEqual([sent.path, sent.base_rev, sent.epoch, JSON.stringify(sent.ops)], ['main.tex', 3, 'e1', JSON.stringify([5, ' world'])]);
    s.doc.replaceRange('!', 11);
    s.doc.replaceRange('?', 12);
    assert.equal(socket.take('ot_update').length, 0, 'one edit in flight at a time');
    assert.equal(s.queue.length, 2, 'later edits compose into one buffer');
    // Someone else typed at the start before our edit reached the server.
    socket.receive({ type: 'ot_applied', path: 'main.tex', rev: 4, ops: ['>> ', 5], client_id: 'other' });
    assert.equal(s.doc.getValue(), '>> hello world!?');
    socket.receive({ type: 'ot_applied', path: 'main.tex', rev: 5, ops: [8, ' world'], client_id: page1.Lx.collab.clientId, op_id: sent.op_id });
    [sent] = socket.take('ot_update');
    assert.equal(sent.base_rev, 5);
    assert.deepEqual(Array.from(sent.ops), [14, '!?'], 'the buffer was moved past the remote edit');
    ok('a remote edit under our pending ones is merged both ways');

    // Reload with the buffer still unconfirmed: it comes back from sessionStorage.
    await tick();
    page1.stop();
    let page2 = loadEditor({ WebSocket: FakeSocket, sessionStorage: storage });
    page2.Lx.collab.connect();
    await tick();
    socket = FakeSocket.last;
    const again = page2.Lx.collab.open('main.tex');
    assert.equal(again.doc.getValue(), '>> hello world!?');
    const [resume] = socket.take('open_file');
    assert.deepEqual([resume.known_rev, resume.epoch], [5, 'e1']);
    socket.receive({ type: 'file_resume', path: 'main.tex', rev: 5, epoch: 'e1', events: [] });
    [sent] = socket.take('ot_update');
    assert.deepEqual(Array.from(sent.ops), [14, '!?']);
    ok('a reload resumes unconfirmed edits from where the server is');

    // The server restarts: same text on disk as our edits were based on, new epoch. They are sent again.
    socket.close();
    await new Promise((resolve) => setTimeout(resolve, 1700));
    socket = FakeSocket.last;
    await tick();
    socket.take('open_file');
    socket.receive({ type: 'file_state', path: 'main.tex', epoch: 'e2', rev: 0, content: '>> hello world' });
    [sent] = socket.take('ot_update');
    assert.ok(sent && sent.epoch === 'e2' && sent.base_rev === 0 && JSON.stringify(sent.ops) === JSON.stringify([14, '!?']), 'edits re-sent after a restart');
    assert.equal(again.doc.getValue(), '>> hello world!?');
    ok('after a server restart, unconfirmed edits are sent again');

    // Restarted again, and someone else's edits got in first: ours are merged around theirs.
    socket.close();
    await new Promise((resolve) => setTimeout(resolve, 1700));
    socket = FakeSocket.last;
    await tick();
    socket.take('open_file');
    socket.take('ot_update');
    socket.receive({ type: 'file_state', path: 'main.tex', epoch: 'e3', rev: 2, content: '>> hello, wide world' });
    assert.equal(again.doc.getValue(), '>> hello, wide world!?');
    [sent] = socket.take('ot_update');
    assert.ok(sent && sent.epoch === 'e3' && sent.base_rev === 2);
    ok('after a restart, our edits are merged with edits that got in first');

    // The same server sends text that does not follow from ours: keep ours, tell the person.
    socket.receive({ type: 'file_state', path: 'main.tex', epoch: 'e3', rev: 5, content: 'someone replaced it' });
    assert.equal(again.doc.getValue(), '>> hello, wide world!?');
    assert.equal(again.error, 'unsynced');
    assert.ok(page2.context.toasts.some((t) => /could not be merged/.test(t)));
    assert.ok(page2.context.localStorage.data.size > 0, 'a recovery copy is kept');
    page2.Lx.collab.reload('main.tex');
    socket.receive({ type: 'file_state', path: 'main.tex', epoch: 'e3', rev: 0, content: 'someone replaced it' });
    assert.equal(again.doc.getValue(), 'someone replaced it');
    ok('text that cannot be merged is kept with a recovery copy until you choose');

    // Renames move the session; removals drop it.
    const ch = page2.Lx.collab.open('chapters/a.tex');
    socket.receive({ type: 'file_state', path: 'chapters/a.tex', epoch: 'e', rev: 0, content: 'A' });
    page2.Lx.collab.remap('chapters', 'parts');
    assert.equal(page2.Lx.collab.session('parts/a.tex'), ch);
    assert.equal(ch.path, 'parts/a.tex');
    ch.doc.replaceRange('!', 1);
    assert.equal(socket.take('ot_update')[0].path, 'parts/a.tex');
    page2.Lx.collab.removed('parts');
    assert.equal(page2.Lx.collab.session('parts/a.tex'), null);
    ok('sessions follow renames and go with removals');
    page2.stop();
    console.log('Live-editing client passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
