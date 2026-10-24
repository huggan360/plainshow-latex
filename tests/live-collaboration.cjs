// Several editors typing at once against the real collaboration server, over
// real WebSockets, on a disposable project: with latency, a rename while
// everyone types, and a server restart. Every editor and the file on disk must
// end with the same text, and no keystroke may be lost.
//   CLIENTS=5 ROUNDS=10 LATENCY=40 node tests/live-collaboration.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const { loadEditor, root } = require('./harness.cjs');

const count = Number(process.env.CLIENTS || 5);
const rounds = Number(process.env.ROUNDS || 10);
const latency = Number(process.env.LATENCY || 40);
const port = 18000 + Math.floor(Math.random() * 1000);
if (count > 50 || rounds > 50) throw Error('Test bounds exceeded.');
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const fixture = (action, input) => JSON.parse(execFileSync('php', [path.join(root, 'tests/live-fixture.php'), action], { input: JSON.stringify(input || {}), encoding: 'utf8' }));

let server = null;
function startServer() {
    server = spawn('php', [path.join(root, 'websocket/server.php')], { env: { ...process.env, PLAINSHOW_WS_PORT: String(port) }, stdio: ['ignore', 'ignore', 'inherit'] });
    return sleep(700);
}
async function stopServer() { server.kill('SIGKILL'); await sleep(200); }

/** A WebSocket whose messages each way arrive after a delay, in order. */
function slowSocket(delay) {
    return class {
        static OPEN = 1;
        constructor(url) {
            this.ws = new WebSocket(url);
            this.inbox = Promise.resolve();
            this.outbox = Promise.resolve();
            this.ws.onopen = () => this.onopen && this.onopen();
            this.ws.onclose = () => { this.inbox = this.inbox.then(() => this.onclose && this.onclose()); };
            this.ws.onerror = () => {};
            this.ws.onmessage = (event) => { this.inbox = this.inbox.then(() => sleep(delay)).then(() => this.onmessage && this.onmessage({ data: event.data })); };
        }
        get readyState() { return this.ws.readyState; }
        send(text) { this.outbox = this.outbox.then(() => sleep(delay)).then(() => { if (this.ws.readyState === 1) this.ws.send(text); }); }
        close() { this.ws.close(); }
    };
}

(async () => {
    const project = fixture('create');
    const users = Array.from({ length: count }, (_, i) => 2000000000 + i);
    const tickets = fixture('tickets', { ...project, users });
    const disk = (file) => fs.readFileSync(path.join(project.dir, file), 'utf8');
    const editors = [];
    try {
        await startServer();
        for (let i = 0; i < count; i++) {
            const page = loadEditor({
                project: { id: project.documentId, userId: users[i], userName: 'Load ' + i, ticket: tickets[users[i]], socketPath: '/' },
                location: { protocol: 'http:', host: '127.0.0.1:' + port },
                WebSocket: slowSocket(latency + i * 7),
                fetch: async () => ({ json: async () => ({ ticket: tickets[users[i]] }) }),
            });
            // What the file tree does on someone else's rename.
            page.Lx.on('workspace', (event) => { if (event.action === 'renamed') page.Lx.collab.remap(event.old_path, event.path); });
            page.Lx.collab.connect();
            editors.push(page);
        }
        await sleep(500);
        const sessions = editors.map((page) => ({ main: page.Lx.collab.open('main.tex'), chapter: page.Lx.collab.open('chapters/a.tex'), page }));
        const until = async (check, label, timeout = 30000) => {
            const deadline = Date.now() + timeout;
            while (!check()) { if (Date.now() > deadline) throw Error('Timed out: ' + label); await sleep(25); }
        };
        await until(() => sessions.every((s) => s.main.ready && s.chapter.ready), 'everyone loaded');

        const typed = []; // every word typed, which must all survive
        let seed = 42;
        const random = (n) => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed % n; };
        const type = (session, i, round) => {
            const doc = session.doc;
            const word = `<${i}.${round}>`;
            // Between words, so another person's word never lands inside this one.
            const text = doc.getValue();
            const gaps = [0, text.length];
            for (let at = 1; at < text.length; at++) if (text[at - 1] === '>' || text[at] === '<') gaps.push(at);
            doc.replaceRange(word, gaps[random(gaps.length)]);
            typed.push([session, word]);
        };
        let chapterName = 'chapters/a.tex';
        for (let round = 0; round < rounds; round++) {
            sessions.forEach((s, i) => { type(s.main, i, round); type(s.chapter, i, round); });
            if (round === Math.floor(rounds / 3)) {
                // Editor 0 renames the chapter while everyone keeps typing in it.
                const page = sessions[0].page;
                const held = await page.Lx.collab.hold('chapters/a.tex');
                fs.renameSync(path.join(project.dir, 'chapters/a.tex'), path.join(project.dir, 'chapters/b.tex'));
                page.Lx.collab.remap('chapters/a.tex', 'chapters/b.tex');
                page.Lx.collab.workspaceEvent('renamed', { old_path: 'chapters/a.tex', path: 'chapters/b.tex', entry_type: 'file' });
                page.Lx.collab.release(held);
                chapterName = 'chapters/b.tex';
            }
            if (!process.env.NORESTART && round === Math.floor((2 * rounds) / 3)) {
                await stopServer();
                sessions.forEach((s, i) => { type(s.main, i, 'offline'); });
                await startServer();
            }
            await sleep(30 + random(60));
        }
        await until(() => sessions.every((s) => !s.page.Lx.collab.pending()), 'every edit confirmed', 60000);
        // Others' last edits may still be on their way over a slow connection.
        await until(() => sessions.every((s) => s.main.doc.getValue() === sessions[0].main.doc.getValue() && s.chapter.doc.getValue() === sessions[0].chapter.doc.getValue()), 'everyone caught up', 15000).catch(() => {});

        const main = sessions[0].main.doc.getValue();
        const chapter = sessions[0].chapter.doc.getValue();
        assert.ok(sessions.every((s) => s.main.doc.getValue() === main), 'every editor shows the same main.tex');
        assert.ok(sessions.every((s) => s.chapter.doc.getValue() === chapter && s.chapter.path === 'chapters/b.tex'), 'every editor shows the same renamed chapter');
        assert.equal(disk('main.tex'), main, 'main.tex on disk matches');
        assert.equal(disk(chapterName), chapter, 'the renamed chapter on disk matches');
        assert.ok(!fs.existsSync(path.join(project.dir, 'chapters/a.tex')), 'no file left under the old name');
        const lost = typed.filter(([session, word]) => !session.doc.getValue().includes(word)).map(([session, word]) => session.path + ' ' + word);
        assert.deepEqual(lost, [], 'no keystroke lost');
        assert.ok(fs.readdirSync(project.dir).every((f) => !f.endsWith('.tmp')), 'no temporary files left');
        console.log(`  ok  ${count} editors, ${typed.length} edits, a rename and a restart: all converge on disk`);
        console.log('Live collaboration passed.');
    } finally {
        editors.forEach((page) => page.stop());
        if (server) server.kill('SIGKILL');
        fixture('delete', project);
        setTimeout(() => process.exit(process.exitCode || 0), 100);
    }
})().catch((error) => { console.error(error); process.exitCode = 1; });
