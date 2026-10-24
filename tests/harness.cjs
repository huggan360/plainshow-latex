// Loads the editor's real scripts (ot.js, app.js, collab.js) into a Node VM with a
// stand-in for CodeMirror documents, so the tests run the code the browser runs.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

function memoryStorage(data = new Map()) {
    return {
        get length() { return data.size; },
        key: (i) => [...data.keys()][i] ?? null,
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        removeItem: (key) => data.delete(key),
        data,
    };
}

/** A CodeMirror document reduced to what collab.js uses; positions are plain indexes. */
class FakeDoc {
    constructor(text = '') { this.text = text; this.handlers = {}; this.cursor = 0; }
    on(name, handler) { (this.handlers[name] = this.handlers[name] || []).push(handler); }
    getValue() { return this.text; }
    setValue(text) { this.change(0, this.text.length, text, 'setValue'); }
    indexFromPos(pos) { return pos; }
    posFromIndex(index) { return Math.max(0, Math.min(index, this.text.length)); }
    replaceRange(text, from, to = from, origin = '+input') { return this.change(from, to ?? from, text, origin); }
    change(from, to, text, origin) {
        let cancelled = false;
        const change = { from, to, text: text.split('\n'), origin, cancel() { cancelled = true; } };
        (this.handlers.beforeChange || []).forEach((handler) => handler(this, change));
        if (cancelled) return false;
        this.text = this.text.slice(0, from) + text + this.text.slice(to);
        return true;
    }
    getCursor() { return this.cursor; }
    setCursor(cursor) { this.cursor = cursor; }
    clearHistory() {}
    getEditor() { return null; }
    setBookmark() { return { clear() {} }; }
    markText() { return { clear() {} }; }
    listSelections() { return [{ anchor: this.cursor, head: this.cursor }]; }
}

/**
 * One editor page. Options: project (id, userId, ticket, socketPath), location,
 * WebSocket, fetch, sessionStorage (to simulate a reload, pass the old one).
 */
function loadEditor(options = {}) {
    const project = Object.assign({ id: 1, title: 'Test', owner: true, userId: 7, userName: 'Test', rootFile: 'main.tex', ticket: 't', socketPath: '/ws' }, options.project);
    const timers = [];
    const listeners = {};
    const window = {
        addEventListener: (name, handler) => { (listeners[name] = listeners[name] || []).push(handler); },
        setTimeout: (fn, ms) => setTimeout(fn, ms),
        clearTimeout,
        setInterval: (fn, ms) => { const timer = setInterval(fn, ms); timer.unref(); timers.push(timer); return timer; },
        requestAnimationFrame: (fn) => setTimeout(fn, 0),
        PlainShow: { toast: (message) => context.toasts.push(message) },
    };
    const context = vm.createContext({
        window,
        document: { getElementById: (id) => (id === 'lxProject' ? { textContent: JSON.stringify(project) } : null) },
        location: options.location || { protocol: 'https:', host: 'latex.test' },
        WebSocket: options.WebSocket || class { constructor() { this.readyState = 0; } send() {} close() {} },
        fetch: options.fetch || (async () => ({ json: async () => ({ ticket: project.ticket }) })),
        sessionStorage: options.sessionStorage || memoryStorage(),
        localStorage: options.localStorage || memoryStorage(),
        crypto, console, Promise, URL, Blob: class {}, setTimeout, clearTimeout,
        CodeMirror: { Doc: (text) => new FakeDoc(text) },
        toasts: [],
        // The page defines psT next to its dictionary (lib/view.php); here texts stay English.
        psT: (text, params) => Object.entries(params || {}).reduce((out, [name, value]) => out.split(`{${name}}`).join(String(value)), text),
    });
    context.window.window = window;
    vm.runInContext(read('js/ot.js'), context, { filename: 'js/ot.js' });
    context.TextOperation = window.TextOperation;
    vm.runInContext(read('js/editor/app.js'), context, { filename: 'js/editor/app.js' });
    const Lx = window.Lx;
    context.Lx = Lx;
    let active = null;
    Lx.tabs = { active: () => active, setActive: (path) => { active = path; } };
    vm.runInContext(read('js/editor/collab.js'), context, { filename: 'js/editor/collab.js' });
    return {
        Lx, context, project,
        stop() { timers.forEach(clearInterval); },
        unload() { (listeners.beforeunload || []).forEach((handler) => handler({ preventDefault() {} })); },
    };
}

module.exports = { loadEditor, memoryStorage, FakeDoc, read, root };
