/*
 * Live editing together. Every open text file has a session: a CodeMirror
 * document, the text as the server last confirmed it plus our own edits
 * (the shadow), the server revision, and a queue of edits not yet confirmed.
 *
 * At most one edit is in flight per file; later edits are composed into one
 * buffered edit. When someone else's edit arrives, our queue is transformed
 * against it, and it against our queue, so both sides end with the same text.
 * Unconfirmed edits are kept in sessionStorage (a reload resumes them) and as
 * a recovery copy in localStorage.
 */
(function (Lx) {
    'use strict';

    var project = Lx.project;
    var sessions = new Map();
    var presence = {};        // client (one open editor) -> { user_id, user_name, path }
    var me = null;            // our own client key
    var socket = null;
    var reconnectAttempt = 0;
    var lastMessageAt = Date.now();
    var suppress = 0;
    var recovered = false;
    var clientId = (function () {
        var key = 'plainshow-client:' + project.userId + ':' + project.id;
        try {
            var existing = sessionStorage.getItem(key);
            if (existing) return existing;
            var generated = 'client-' + crypto.randomUUID();
            sessionStorage.setItem(key, generated);
            return generated;
        } catch (e) { return 'client-' + crypto.randomUUID(); }
    })();

    function send(message) {
        if (!socket || socket.readyState !== WebSocket.OPEN) return false;
        message.document_id = project.id;
        message.user_id = project.userId;
        socket.send(JSON.stringify(message));
        return true;
    }

    /* ---------- Recovery ---------- */

    function journalKey(path) { return 'plainshow-ot:' + project.userId + ':' + project.id + ':file:' + path; }
    function preserveDraft(path, content) {
        if (typeof content !== 'string') return;
        try { localStorage.setItem('plainshow-recovery:' + project.id + ':file:' + path, JSON.stringify({ content: content, savedAt: Date.now() })); } catch (e) { /* kept in memory */ }
    }
    function persistJournal(session) {
        try {
            if (!session.queue.length) { sessionStorage.removeItem(journalKey(session.path)); return; }
            sessionStorage.setItem(journalKey(session.path), JSON.stringify({ clientId: clientId, content: session.shadow, base: session.base, queue: session.queue, rev: session.rev, epoch: session.epoch }));
        } catch (e) { preserveDraft(session.path, session.shadow); }
    }
    function readJournal(path) {
        try {
            var saved = JSON.parse(sessionStorage.getItem(journalKey(path)) || 'null');
            return saved && saved.clientId === clientId && Array.isArray(saved.queue) && saved.queue.length ? saved : null;
        } catch (e) { return null; }
    }

    /* ---------- Sessions ---------- */

    function session(path) { return sessions.get(path) || null; }

    // Renames we made recently: the server may still answer under the old name for a moment.
    var renames = [];
    function current(path) {
        if (!path || sessions.has(path)) return path;
        for (var i = renames.length - 1; i >= 0; i--) {
            if (Date.now() - renames[i].at < 60000 && Lx.within(path, renames[i].from)) path = Lx.moved(path, renames[i].from, renames[i].to);
        }
        return path;
    }

    /** Open a text file: its document is ready at once, and fills when the server answers. */
    function open(path) {
        var existing = session(path);
        if (existing) return existing;
        var journal = readJournal(path);
        var s = {
            path: path,
            doc: CodeMirror.Doc(journal ? journal.content : '', Lx.modeFor(path)),
            shadow: journal ? journal.content : '',
            base: journal && typeof journal.base === 'string' ? journal.base : null,
            rev: journal ? journal.rev : 0,
            epoch: journal ? journal.epoch : null,
            queue: journal ? journal.queue : [],
            loaded: !!journal,
            ready: false, inFlight: false, resuming: false, held: 0,
            error: null, cursors: {}, marks: {}
        };
        s.doc.on('beforeChange', function (doc, change) { localChange(s, change); });
        sessions.set(path, s);
        requestState(s);
        return s;
    }

    function close(path) {
        var s = session(path);
        if (!s) return;
        if (s.queue.length) preserveDraft(path, s.shadow);
        Object.keys(s.marks).forEach(function (id) { clearCursor(s, id); });
        sessions.delete(path);
        send({ type: 'close_file', path: path });
    }

    function requestState(s, force) {
        s.ready = false;
        send({ type: 'open_file', path: s.path, known_rev: s.rev, epoch: s.epoch, force_snapshot: !!force, active: Lx.tabs && Lx.tabs.active() === s.path });
    }

    /** Replace a document's text without it counting as an edit. */
    function setText(s, text) {
        if (s.doc.getValue() === text) return;
        var cursor = s.doc.getCursor();
        suppress++;
        try { s.doc.setValue(text); } finally { suppress--; }
        try { s.doc.setCursor(cursor); } catch (e) { /* past the end */ }
    }

    /* ---------- Local edits ---------- */

    function localChange(s, change) {
        if (suppress) return;
        if (!s.loaded) { change.cancel(); return; }
        var op;
        try {
            var from = s.doc.indexFromPos(change.from);
            var to = s.doc.indexFromPos(change.to);
            op = new TextOperation().retain(from).delete(to - from).insert(change.text.join('\n')).retain(s.shadow.length - to);
            s.shadow = op.apply(s.shadow);
        } catch (e) {
            op = null;
        }
        if (op && !op.isNoop()) queue(s, op.toJSON());
        // CodeMirror applies the change after this handler; check we agree with it.
        scheduleVerify(s);
    }

    var verifying = new Set();
    function scheduleVerify(s) {
        if (verifying.has(s)) return;
        verifying.add(s);
        Promise.resolve().then(function () {
            window.setTimeout(function () {
                verifying.delete(s);
                if (!sessions.has(s.path)) return;
                var actual = s.doc.getValue();
                if (actual === s.shadow) return;
                var fix = diff(s.shadow, actual);
                s.shadow = actual;
                if (fix.length) queue(s, fix);
            }, 0);
        });
    }

    /** One edit from one text to another; never splits a surrogate pair. */
    function diff(before, after) {
        var start = 0, max = Math.min(before.length, after.length);
        while (start < max && before.charCodeAt(start) === after.charCodeAt(start)) start++;
        var endBefore = before.length, endAfter = after.length;
        while (endBefore > start && endAfter > start && before.charCodeAt(endBefore - 1) === after.charCodeAt(endAfter - 1)) { endBefore--; endAfter--; }
        var splits = function (text, i) { return i > 0 && i < text.length && /[\uD800-\uDBFF]/.test(text[i - 1]) && /[\uDC00-\uDFFF]/.test(text[i]); };
        if (splits(before, start) || splits(after, start)) start--;
        if (splits(before, endBefore) || splits(after, endAfter)) { endBefore++; endAfter++; }
        var op = new TextOperation().retain(start).delete(endBefore - start).insert(after.slice(start, endAfter)).retain(before.length - endBefore);
        return op.isNoop() ? [] : op.toJSON();
    }

    /**
     * The smallest set of insertions and deletions from one text to another
     * (Myers' diff, on code points so emoji stay whole), as one edit. Used to
     * merge after a restart, where a plain diff would turn scattered changes
     * into one big replacement. Very different texts fall back to that.
     */
    function minimalDiff(before, after) {
        var a = Array.from(before), b = Array.from(after);
        var start = 0;
        while (start < a.length && start < b.length && a[start] === b[start]) start++;
        var endA = a.length, endB = b.length;
        while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) { endA--; endB--; }
        var n = endA - start, m = endB - start, max = n + m, limit = 1500;
        if (max > limit * 2) max = limit * 2;
        var offset = max + 1, v = new Int32Array(2 * max + 3), trace = [], found = -1;
        for (var d = 0; d <= Math.min(max, limit) && found < 0; d++) {
            trace.push(v.slice());
            for (var k = -d; k <= d; k += 2) {
                var x = (k === -d || (k !== d && v[offset + k - 1] < v[offset + k + 1])) ? v[offset + k + 1] : v[offset + k - 1] + 1;
                var y = x - k;
                while (x < n && y < m && a[start + x] === b[start + y]) { x++; y++; }
                v[offset + k] = x;
                if (x >= n && y >= m) { found = d; break; }
            }
        }
        if (found < 0) return diff(before, after);
        // Walk back from the end to collect the script, then build the edit forwards.
        var script = [];
        var px = n, py = m;
        for (var step = found; step >= 0; step--) {
            var prev = trace[step];
            var kk = px - py;
            var prevK = (kk === -step || (kk !== step && prev[offset + kk - 1] < prev[offset + kk + 1])) ? kk + 1 : kk - 1;
            var prevX = step === 0 ? 0 : prev[offset + prevK];
            var prevY = step === 0 ? 0 : prevX - prevK;
            while (px > prevX && py > prevY) { script.push(['=', a[start + px - 1]]); px--; py--; }
            if (step > 0) {
                if (px === prevX) script.push(['+', b[start + prevY]]);
                else script.push(['-', a[start + prevX]]);
            }
            px = prevX; py = prevY;
        }
        var op = new TextOperation().retain(a.slice(0, start).join('').length);
        script.reverse().forEach(function (item) {
            if (item[0] === '=') op.retain(item[1].length);
            else if (item[0] === '+') op.insert(item[1]);
            else op['delete'](item[1].length);
        });
        op.retain(a.slice(endA).join('').length);
        return op.isNoop() ? [] : op.toJSON();
    }

    function queue(s, ops) {
        transformCursors(s, ops);
        preserveDraft(s.path, s.shadow);
        // One edit in flight and one composed buffer behind it, even on slow connections.
        // Never add to an edit that has gone out: the server may already have it.
        var tail = s.queue[s.queue.length - 1];
        if (tail && !tail.sentAt) {
            tail.ops = TextOperation.compose(TextOperation.fromJSON(tail.ops), TextOperation.fromJSON(ops)).toJSON();
        } else {
            s.queue.push({ ops: ops, opId: clientId + '-' + crypto.randomUUID() });
        }
        persistJournal(s);
        sendNext(s);
        Lx.emit('dirty', s.path);
    }

    function sendNext(s) {
        if (!s.ready || s.inFlight || s.resuming || s.held || !s.queue.length) return;
        var entry = s.queue[0];
        if (send({ type: 'ot_update', path: s.path, base_rev: s.rev, client_id: clientId, op_id: entry.opId, epoch: s.epoch, ops: entry.ops })) {
            entry.sentAt = Date.now();
            s.inFlight = true;
        }
    }

    /* ---------- From the server ---------- */

    function applied(message) {
        var s = session(message.path);
        if (!s || !s.ready || message.rev <= s.rev) return;
        if (message.rev !== s.rev + 1) { requestState(s); return; }
        s.rev = message.rev;

        if (message.client_id === clientId && (!message.op_id || (s.queue[0] && s.queue[0].opId === message.op_id))) {
            if (s.base !== null && s.queue[0]) s.base = TextOperation.fromJSON(s.queue[0].ops).apply(s.base);
            s.queue.shift();
            s.inFlight = false;
            persistJournal(s);
            sendNext(s);
            Lx.emit('dirty', s.path);
            return;
        }
        try {
            var remote = TextOperation.fromJSON(message.ops || []);
            if (s.base !== null) s.base = remote.apply(s.base);
            // Their edit against our queue, and our queue against their edit.
            s.queue.forEach(function (entry) {
                var primes = TextOperation.transform(remote, TextOperation.fromJSON(entry.ops));
                remote = primes[0];
                entry.ops = primes[1].toJSON();
            });
            applyRemote(s, remote);
            persistJournal(s);
        } catch (e) {
            preserveDraft(s.path, s.shadow);
            requestState(s);
        }
    }

    function applyRemote(s, op) {
        s.shadow = op.apply(s.shadow);
        suppress++;
        try {
            s.doc.getEditor() ? s.doc.getEditor().operation(apply) : apply();
        } finally { suppress--; }
        transformCursors(s, op.toJSON());

        function apply() {
            var index = 0;
            op.ops.forEach(function (item) {
                if (typeof item === 'string') {
                    s.doc.replaceRange(item, s.doc.posFromIndex(index), null, '+remote');
                    index += item.length;
                } else if (item > 0) {
                    index += item;
                } else {
                    s.doc.replaceRange('', s.doc.posFromIndex(index), s.doc.posFromIndex(index - item), '+remote');
                }
            });
        }
        if (s.doc.getValue() !== s.shadow) setText(s, s.shadow);
        Lx.emit('remote', s.path);
    }

    function fileState(message) {
        var s = session(message.path);
        if (!s) return;
        var content = message.content || '';
        if (s.queue.length && s.shadow !== content && s.base !== null && message.epoch !== s.epoch && merge(s, content)) {
            // The server started over (a restart). Our edits are merged onto its text and sent again.
            s.epoch = message.epoch;
            s.rev = message.rev || 0;
            s.inFlight = false;
            s.ready = true;
            s.error = null;
            persistJournal(s);
            sendNext(s);
            Lx.emit('state', s.path);
            Lx.emit('dirty', s.path);
            return;
        }
        if (s.queue.length && s.shadow !== content) {
            // The server lost track of our edits (it restarted, or the file changed on disk).
            preserveDraft(s.path, s.shadow);
            recovered = true;
            s.ready = false;
            s.inFlight = false;
            s.error = 'unsynced';
            Lx.toast(psT('{name}: your edits could not be merged. They are kept here and in File, Recovery copy.', { name: Lx.name(s.path) }), 'warning');
            Lx.emit('state', s.path);
            return;
        }
        s.epoch = message.epoch;
        s.rev = message.rev || 0;
        s.queue = [];
        s.inFlight = false;
        s.shadow = content;
        s.base = content;
        s.error = null;
        setText(s, content);
        if (!s.loaded) s.doc.clearHistory();
        s.loaded = true;
        s.ready = true;
        persistJournal(s);
        Lx.emit('state', s.path);
        Lx.emit('dirty', s.path);
    }

    /**
     * Unconfirmed edits onto text the server has now: what changed there since
     * our last confirmed text is worked out as one edit, and our queue is
     * transformed over it, as if it had come in live.
     */
    function merge(s, content) {
        try {
            var theirs = s.base === content ? new TextOperation().retain(content.length) : TextOperation.fromJSON(minimalDiff(s.base, content));
            var queue = s.queue.map(function (entry) { return { ops: entry.ops, opId: entry.opId }; });
            queue.forEach(function (entry) {
                var primes = TextOperation.transform(theirs, TextOperation.fromJSON(entry.ops));
                theirs = primes[0];
                entry.ops = primes[1].toJSON();
            });
            s.queue = queue;
            s.base = content;
            if (!theirs.isNoop()) applyRemote(s, theirs);
            return true;
        } catch (e) {
            return false;
        }
    }

    function fileResume(message) {
        var s = session(message.path);
        if (!s) return;
        if (message.epoch !== s.epoch) { requestState(s, true); return; }
        s.ready = true;
        s.resuming = true;
        s.inFlight = false;
        try {
            (message.events || []).forEach(function (event) { applied(Object.assign({}, event, { path: s.path })); });
            if (s.rev < message.rev) throw new Error('Incomplete history.');
        } catch (e) {
            s.ready = false;
            preserveDraft(s.path, s.shadow);
            requestState(s, true);
        } finally {
            s.resuming = false;
        }
        s.error = null;
        sendNext(s);
        persistJournal(s);
        Lx.emit('state', s.path);
    }

    function fileError(message) {
        var s = session(message.path);
        if (!s) return;
        s.ready = false;
        s.error = message.message || psT('This file cannot be opened.');
        if (s.queue.length) preserveDraft(s.path, s.shadow);
        Lx.emit('state', s.path);
    }

    function otError(message) {
        var s = session(message.path);
        if (!s) return;
        preserveDraft(s.path, s.shadow);
        s.ready = false;
        s.inFlight = false;
        // A rename by someone else may be on its way; ask again once it has arrived.
        window.setTimeout(function () { if (sessions.get(s.path) === s) requestState(s); }, 300);
    }

    /* ---------- Cursors and presence ---------- */

    function transformIndex(index, ops) {
        var base = 0;
        ops.forEach(function (item) {
            if (typeof item === 'string') { if (index >= base) index += item.length; }
            else if (item > 0) base += item;
            else {
                var length = -item;
                if (index > base + length) index -= length;
                else if (index >= base) index = base;
            }
        });
        return index;
    }

    function transformCursors(s, ops) {
        Object.keys(s.cursors).forEach(function (id) {
            var cursor = s.cursors[id];
            cursor.anchor = transformIndex(cursor.anchor, ops);
            cursor.head = transformIndex(cursor.head, ops);
            renderCursor(s, id);
        });
    }

    function clearCursor(s, id) {
        var marks = s.marks[id];
        if (marks) { marks.bookmark && marks.bookmark.clear(); marks.selection && marks.selection.clear(); }
        delete s.marks[id];
    }

    function renderCursor(s, id) {
        clearCursor(s, id);
        var cursor = s.cursors[id];
        if (!cursor) return;
        var length = s.doc.getValue().length;
        var anchor = Math.max(0, Math.min(cursor.anchor, length));
        var head = Math.max(0, Math.min(cursor.head, length));
        var colors = Lx.color(cursor.user_id, cursor.user_name);
        var widget = document.createElement('span');
        widget.className = 'remote-cursor';
        widget.style.setProperty('--cursor-color', colors.solid);
        var label = document.createElement('span');
        label.className = 'remote-cursor-label';
        label.textContent = cursor.user_name || psT('Someone');
        widget.appendChild(label);
        var marks = { bookmark: s.doc.setBookmark(s.doc.posFromIndex(head), { widget: widget, insertLeft: true }) };
        if (anchor !== head) {
            marks.selection = s.doc.markText(s.doc.posFromIndex(Math.min(anchor, head)), s.doc.posFromIndex(Math.max(anchor, head)), { css: 'background:' + colors.soft });
        }
        s.marks[id] = marks;
    }

    function cursorFrom(message) {
        var s = session(message.path);
        if (!s || message.client === me) return;
        s.cursors[message.client] = { user_id: message.user_id, user_name: message.user_name, anchor: message.anchor || 0, head: message.head || 0 };
        renderCursor(s, message.client);
    }

    function cursorClear(message) {
        sessions.forEach(function (s) {
            if (message.path && s.path !== message.path) return;
            delete s.cursors[message.client];
            clearCursor(s, message.client);
        });
    }

    var cursorTimer = null;
    function sendCursor() {
        window.clearTimeout(cursorTimer);
        cursorTimer = window.setTimeout(function () {
            var path = Lx.tabs.active();
            var s = session(path);
            if (!s || !s.ready || s.doc.getEditor() !== Lx.editor) return;
            var selection = s.doc.listSelections()[0];
            send({ type: 'cursor_position', path: path, anchor: s.doc.indexFromPos(selection.anchor), head: s.doc.indexFromPos(selection.head) });
        }, 30);
    }

    function setActive(path) {
        send({ type: 'active_file', path: path || null });
        sendCursor();
    }

    /* ---------- Renames and removals ---------- */

    /** Wait for edits under a path to be confirmed, and hold new ones back until release(). */
    function hold(path) {
        var held = [];
        sessions.forEach(function (s) { if (Lx.within(s.path, path)) { s.held++; held.push(s); } });
        var deadline = Date.now() + 8000;
        return new Promise(function (resolve, reject) {
            (function wait() {
                if (!held.some(function (s) { return s.inFlight; })) return resolve(held);
                if (Date.now() > deadline || !socket || socket.readyState !== WebSocket.OPEN) {
                    release(held);
                    return reject(new Error('Edits are not synced yet. Try again in a moment.'));
                }
                window.setTimeout(wait, 60);
            })();
        });
    }
    function release(held) {
        held.forEach(function (s) { s.held = Math.max(0, s.held - 1); sendNext(s); });
    }

    /** A file or folder was renamed or moved: its sessions follow. */
    function remap(from, to) {
        renames.push({ from: from, to: to, at: Date.now() });
        if (renames.length > 20) renames.shift();
        Array.from(sessions.keys()).forEach(function (path) {
            if (!Lx.within(path, from)) return;
            var s = sessions.get(path);
            var next = Lx.moved(path, from, to);
            sessions.delete(path);
            try { sessionStorage.removeItem(journalKey(path)); } catch (e) { /* none */ }
            s.path = next;
            sessions.set(next, s);
            persistJournal(s);
            // It may have failed to reopen under the old name while the rename was on its way.
            if (!s.ready) requestState(s);
        });
    }

    function removed(path) {
        Array.from(sessions.keys()).forEach(function (open) {
            if (!Lx.within(open, path)) return;
            var s = sessions.get(open);
            if (s.queue.length) {
                preserveDraft(open, s.shadow);
                Lx.toast(psT('{name} was deleted. Your last edits are in File, Recovery copy.', { name: Lx.name(open) }), 'warning');
            }
            try { sessionStorage.removeItem(journalKey(open)); } catch (e) { /* none */ }
            sessions.delete(open);
        });
    }

    function workspaceEvent(action, payload) {
        payload = payload || {};
        payload.type = 'workspace_event';
        payload.action = action;
        send(payload);
    }

    /* ---------- Connection ---------- */

    function handle(message) {
        if (message.path && message.type !== 'workspace_event' && message.type !== 'presence') message.path = current(message.path);
        switch (message.type) {
            case 'pong': break;
            case 'user_list':
                presence = {};
                me = message.you;
                (message.users || []).forEach(function (user) { presence[user.client] = { user_id: user.user_id, user_name: user.user_name, path: user.path }; });
                Lx.emit('presence', presence);
                break;
            case 'user_join':
                presence[message.client] = { user_id: message.user_id, user_name: message.user_name, path: null };
                Lx.emit('presence', presence);
                break;
            case 'user_leave':
                delete presence[message.client];
                cursorClear({ client: message.client });
                Lx.emit('presence', presence);
                break;
            case 'presence':
                presence[message.client] = { user_id: message.user_id, user_name: message.user_name, path: message.path };
                Lx.emit('presence', presence);
                break;
            case 'file_state': fileState(message); break;
            case 'file_resume': fileResume(message); break;
            case 'file_error': fileError(message); break;
            case 'file_reset': {
                var s = session(message.path);
                if (s) requestState(s, !s.queue.length);
                break;
            }
            case 'ot_applied': applied(message); break;
            case 'ot_error': otError(message); break;
            case 'cursor_position': cursorFrom(message); break;
            case 'cursor_clear': cursorClear(message); break;
            case 'workspace_event': Lx.emit('workspace', message); break;
            case 'server_error': Lx.toast(message.message, 'warning'); socket.close(); break;
        }
    }

    function connect(ticket) {
        socket = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + project.socketPath +
            '?document_id=' + project.id + '&ticket=' + encodeURIComponent(ticket || project.ticket));
        socket.onopen = function () {
            reconnectAttempt = 0;
            lastMessageAt = Date.now();
            sessions.forEach(function (s) { requestState(s); });
            setActive(Lx.tabs ? Lx.tabs.active() : null);
            Lx.emit('connection', true);
        };
        socket.onmessage = function (event) {
            lastMessageAt = Date.now();
            var message;
            try { message = JSON.parse(event.data); } catch (e) { return; }
            handle(message);
        };
        socket.onclose = function () {
            sessions.forEach(function (s) { if (s.queue.length) preserveDraft(s.path, s.shadow); s.ready = false; s.inFlight = false; });
            Lx.emit('connection', false);
            var delay = Math.min(30000, 1000 * Math.pow(2, reconnectAttempt++)) + Math.random() * 500;
            window.setTimeout(function () {
                // The page's ticket lasts two minutes; ask for a fresh one.
                fetch('api/ws-ticket.php?document_id=' + project.id, { cache: 'no-store' })
                    .then(function (response) { return response.json(); })
                    .then(function (body) { connect(body.ticket); })
                    .catch(function () { socket.onclose(); });
            }, delay);
        };
    }

    window.setInterval(function () {
        if (!socket || socket.readyState !== WebSocket.OPEN) return;
        if (Date.now() - lastMessageAt > 45000) { socket.close(); return; }
        send({ type: 'ping' });
    }, 15000);
    window.setInterval(function () {
        if (!socket || socket.readyState !== WebSocket.OPEN) return;
        var stalled = false;
        sessions.forEach(function (s) { if (s.inFlight && s.queue[0] && Date.now() - s.queue[0].sentAt > 60000) stalled = true; });
        if (stalled) socket.close();
    }, 3000);

    function pending(path) {
        var any = false;
        sessions.forEach(function (s) { if ((!path || s.path === path) && s.queue.length) any = true; });
        return any;
    }

    /** Resolve once every edit is confirmed by the server, for compiling. */
    function waitForSync() {
        var deadline = Date.now() + 8000;
        return new Promise(function (resolve, reject) {
            (function wait() {
                if (!pending()) return resolve();
                if (!socket || socket.readyState !== WebSocket.OPEN || Date.now() > deadline) {
                    return reject(new Error('Edits are not synced yet. A recovery copy is kept in File, Recovery copy.'));
                }
                window.setTimeout(wait, 80);
            })();
        });
    }

    function status() {
        if (recovered) return 'review';
        if (!socket || socket.readyState !== WebSocket.OPEN) return 'offline';
        var active = session(Lx.tabs.active());
        if (active && !active.ready && !active.error) return 'sync';
        return pending() ? 'saving' : 'saved';
    }

    /** Throw away our unsynced edits to a file and load the server's text. */
    function reload(path) {
        var s = session(path);
        if (!s) return;
        if (s.queue.length) preserveDraft(path, s.shadow);
        s.queue = [];
        s.inFlight = false;
        s.error = null;
        recovered = false;
        requestState(s, true);
    }

    function recoveryCopy() {
        var drafts = {};
        var prefix = 'plainshow-recovery:' + project.id + ':';
        try {
            for (var i = 0; i < localStorage.length; i++) {
                var key = localStorage.key(i);
                if (key.indexOf(prefix) === 0) drafts[key.slice(prefix.length)] = JSON.parse(localStorage.getItem(key));
            }
        } catch (e) { /* none */ }
        var current = {};
        sessions.forEach(function (s) { current[s.path] = s.doc.getValue(); });
        Lx.download(JSON.stringify({ project: project.id, drafts: drafts, openFiles: current }, null, 2), 'plainshow-' + project.id + '-recovery.json', 'application/json');
    }

    window.addEventListener('beforeunload', function (event) {
        if (pending()) { event.preventDefault(); event.returnValue = ''; }
    });

    Lx.on('editor', function (editor) {
        editor.on('cursorActivity', sendCursor);
        editor.on('focus', sendCursor);
    });

    Lx.collab = {
        connect: function () { connect(); },
        open: open, close: close, session: session, setActive: setActive,
        hold: hold, release: release, remap: remap, removed: removed, workspaceEvent: workspaceEvent,
        pending: pending, waitForSync: waitForSync, status: status, reload: reload, recoveryCopy: recoveryCopy,
        presence: function () { return presence; },
        clientId: clientId,
        // For the tests.
        _diff: diff, _minimalDiff: minimalDiff, _transformIndex: transformIndex, _handle: handle
    };
})(window.Lx);
