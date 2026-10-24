/*
 * The open files, one tab each. Text files edit in the source editor (each
 * with its own undo history and cursor); pictures and PDFs open as previews.
 * Open tabs are remembered per project.
 */
(function (Lx) {
    'use strict';

    var bar = document.getElementById('lxTabs');
    var sourceHost = document.getElementById('lxSource');
    var preview = document.getElementById('lxFilePreview');
    var empty = document.getElementById('lxEmpty');
    var notice = document.getElementById('lxFileNotice');
    var storeKey = 'lx-tabs:' + Lx.project.id;
    var open = [];       // paths, in tab order
    var active = null;
    var blank = null;    // the editor's document when no text file is shown

    function save() { Lx.store.set(storeKey, { open: open, active: active }); }

    var notText = new Set(); // files the server would not open as text
    function isText(path) { return Lx.kind(path) === 'text' && !notText.has(path); }

    /** Open a file in a tab (or go to its tab). Options: line, to jump to (0-based). */
    function openTab(path, options) {
        options = options || {};
        if (open.indexOf(path) === -1) {
            var at = active ? open.indexOf(active) + 1 : open.length;
            open.splice(at, 0, path);
        }
        activate(path);
        if (typeof options.line === 'number' && isText(path)) goToLine(path, options.line);
        else if (options.focus && isText(path)) goToLine(path, null);
    }

    var jumps = {};      // path -> line to go to once the file has loaded
    function goToLine(path, line) {
        var session = Lx.collab.session(path);
        if (!session) return;
        if (!session.loaded) { jumps[path] = line; return; }
        // A line to go to, or null just to start typing.
        if (line !== null) {
            var pos = { line: Math.max(0, Math.min(line, session.doc.lineCount() - 1)), ch: 0 };
            session.doc.setCursor(pos);
            if (active === path) Lx.editor.scrollIntoView(pos, 120);
        }
        if (active === path) Lx.editor.focus();
    }

    function activate(path) {
        active = path;
        save();
        sourceHost.hidden = !path || !isText(path);
        preview.hidden = !path || isText(path);
        empty.hidden = !!path;
        // The writing toolbar only for text files.
        sourceHost.parentNode.classList.toggle('is-not-text', !path || !isText(path));
        if (path && isText(path)) {
            var session = Lx.collab.open(path);
            if (Lx.editor.getDoc() !== session.doc) Lx.editor.swapDoc(session.doc);
            Lx.editor.setOption('mode', Lx.modeFor(path));
            syncState(path);
            window.setTimeout(function () { Lx.editor.refresh(); }, 0);
        } else {
            showBlank();
            if (path) showPreview(path, false);
        }
        Lx.collab.setActive(path);
        render();
        Lx.emit('active', path);
    }

    function showBlank() {
        blank = blank || CodeMirror.Doc('', 'stex');
        if (Lx.editor.getDoc() !== blank) Lx.editor.swapDoc(blank);
        Lx.editor.setOption('readOnly', 'nocursor');
        notice.hidden = true;
    }

    function showPreview(path, fresh) {
        preview.innerHTML = '';
        var kind = Lx.kind(path);
        if (kind === 'image') {
            var img = document.createElement('img');
            img.src = Lx.fileUrl(path, true);
            img.alt = Lx.name(path);
            preview.appendChild(img);
        } else if (kind === 'pdf') {
            var frame = document.createElement('iframe');
            frame.title = Lx.name(path);
            frame.src = Lx.fileUrl(path, fresh);
            preview.appendChild(frame);
        } else {
            var box = document.createElement('div');
            box.className = 'lx-file-preview__none';
            box.innerHTML = '<i class="bx bx-file-blank"></i><strong></strong><span>' + psT('This file cannot be shown here.') + '</span><a class="ps-btn ps-btn--sm" download><i class="bx bx-download"></i>' + psT('Download') + '</a>';
            box.querySelector('strong').textContent = Lx.name(path);
            box.querySelector('a').href = Lx.fileUrl(path);
            preview.appendChild(box);
        }
    }

    /** Editable once the server has sent the text; a notice when it cannot be opened. */
    function syncState(path) {
        if (path !== active) return;
        var session = Lx.collab.session(path);
        if (!session) return;
        if (session.error && session.error !== 'unsynced') {
            // Not a text file after all, or gone: show it as a file instead.
            if (!session.loaded) {
                notText.add(path);
                Lx.collab.close(path);
                sourceHost.hidden = true;
                sourceHost.parentNode.classList.add('is-not-text');
                preview.hidden = false;
                showBlank();
                showPreview(path, true);
                preview.querySelector('.lx-file-preview__none span').textContent = session.error;
                return;
            }
            notice.hidden = false;
            notice.textContent = session.error;
        } else if (session.error === 'unsynced') {
            notice.hidden = false;
            notice.innerHTML = '';
            notice.append('Your edits to this file could not be merged with the server. They are still here. ');
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = psT('Load the server version');
            button.addEventListener('click', function () {
                if (!window.confirm(psT('Download a recovery copy of your edits, then load the server version?'))) return;
                Lx.collab.recoveryCopy();
                Lx.collab.reload(path);
            });
            notice.appendChild(button);
        } else {
            notice.hidden = true;
        }
        Lx.editor.setOption('readOnly', session.loaded ? false : 'nocursor');
        if (session.loaded && jumps[path] !== undefined) {
            var line = jumps[path];
            delete jumps[path];
            goToLine(path, line);
        }
    }

    function close(path) {
        var index = open.indexOf(path);
        if (index === -1) return;
        if (Lx.collab.pending(path) && !window.confirm(psT('{name} has edits that are not synced yet. A recovery copy is kept. Close it anyway?', { name: Lx.name(path) }))) return;
        open.splice(index, 1);
        if (active === path) {
            var next = open[index] || open[index - 1] || null;
            if (next) activate(next);
            else { active = null; activate(null); }
        }
        if (isText(path)) Lx.collab.close(path);
        save();
        render();
    }

    function render() {
        bar.innerHTML = '';
        var names = {};
        open.forEach(function (path) { names[Lx.name(path)] = (names[Lx.name(path)] || 0) + 1; });
        open.forEach(function (path) {
            var tab = document.createElement('div');
            tab.className = 'lx-tab' + (path === active ? ' is-active' : '') + (Lx.collab.pending(path) ? ' is-dirty' : '');
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-selected', path === active ? 'true' : 'false');
            tab.tabIndex = 0;
            tab.title = path;
            tab.dataset.path = path;

            var icon = document.createElement('i');
            icon.className = 'bx ' + Lx.icon(path);
            var name = document.createElement('span');
            name.className = 'lx-tab__name';
            name.textContent = Lx.name(path);
            tab.append(icon, name);
            if (names[Lx.name(path)] > 1 && path.indexOf('/') !== -1) {
                var folder = document.createElement('small');
                folder.className = 'lx-tab__folder';
                folder.textContent = path.split('/').slice(-2, -1)[0];
                tab.appendChild(folder);
            }
            if (path === Lx.project.rootFile) {
                var main = document.createElement('span');
                main.className = 'lx-tab__main';
                main.textContent = 'main';
                main.title = psT('Main file: compiling starts here');
                tab.appendChild(main);
            }
            var dot = document.createElement('span');
            dot.className = 'lx-tab__dot';
            dot.title = psT('Not synced yet');
            var x = document.createElement('button');
            x.type = 'button';
            x.className = 'lx-tab__close';
            x.setAttribute('aria-label', psT('Close {name}', { name: Lx.name(path) }));
            x.innerHTML = '<i class="bx bx-x"></i>';
            x.addEventListener('click', function (event) { event.stopPropagation(); close(path); });
            tab.append(dot, x);

            tab.addEventListener('click', function () { if (path !== active) activate(path); });
            tab.addEventListener('auxclick', function (event) { if (event.button === 1) { event.preventDefault(); close(path); } });
            tab.addEventListener('mousedown', function (event) { if (event.button === 1) event.preventDefault(); });
            tab.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(path); }
                if (event.key === 'Delete') close(path);
            });
            bar.appendChild(tab);
        });
        var current = bar.querySelector('.is-active');
        if (current) current.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    /** Bring back the tabs from last time, or open the main file. */
    function restore() {
        var saved = Lx.store.get(storeKey, null);
        var paths = saved && Array.isArray(saved.open) ? saved.open.filter(Lx.tree.exists) : [];
        if (!paths.length) {
            var first = Lx.tree.exists(Lx.project.rootFile) ? Lx.project.rootFile : Lx.tree.files().filter(function (p) { return /\.tex$/i.test(p); })[0];
            if (first) paths = [first];
        }
        open = paths;
        var start = saved && paths.indexOf(saved.active) !== -1 ? saved.active : (paths.indexOf(Lx.project.rootFile) !== -1 ? Lx.project.rootFile : paths[0] || null);
        // Open the text files in the background so their tabs are ready.
        paths.forEach(function (path) { if (isText(path) && path !== start) Lx.collab.open(path); });
        activate(start);
    }

    /** A file or folder was renamed or moved. */
    function remap(from, to) {
        open = open.map(function (path) { return Lx.moved(path, from, to); });
        if (active) active = Lx.moved(active, from, to);
        save();
        if (active && Lx.within(active, to)) Lx.editor.setOption('mode', Lx.modeFor(active));
        render();
    }

    /** A file or folder was deleted. */
    function removed(path) {
        var gone = open.filter(function (p) { return Lx.within(p, path); });
        if (!gone.length) return;
        var wasActive = active && Lx.within(active, path);
        var index = wasActive ? open.indexOf(active) : -1;
        open = open.filter(function (p) { return !Lx.within(p, path); });
        if (wasActive) activate(open[Math.min(index, open.length - 1)] || null);
        save();
        render();
    }

    /** Someone changed a picture or PDF that is open: show the new one. */
    function refresh(path) {
        if (path === active && !isText(path)) showPreview(path, true);
    }

    Lx.on('state', syncState);
    Lx.on('dirty', function () { window.requestAnimationFrame(renderDots); });
    function renderDots() {
        bar.querySelectorAll('.lx-tab').forEach(function (tab) { tab.classList.toggle('is-dirty', Lx.collab.pending(tab.dataset.path)); });
    }
    bar.addEventListener('wheel', function (event) {
        if (Math.abs(event.deltaY) > Math.abs(event.deltaX)) { bar.scrollLeft += event.deltaY; event.preventDefault(); }
    }, { passive: false });

    Lx.tabs = {
        open: openTab, close: close, active: function () { return active; }, list: function () { return open.slice(); },
        restore: restore, remap: remap, removed: removed, refresh: refresh, render: render,
        closeActive: function () { if (active) close(active); },
        next: function (step) { if (open.length > 1) activate(open[(open.indexOf(active) + step + open.length) % open.length]); }
    };
})(window.Lx);
