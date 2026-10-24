/*
 * The LaTeX editor. A project is a folder of files: the file tree opens them
 * as tabs, each text file is edited live together through collab.js, and
 * compiling starts from the project's main file.
 *
 *   app.js        this namespace, small helpers and start-up
 *   collab.js     live editing: one session per open text file
 *   tabs.js       the open files
 *   tree.js       the file tree and its file actions
 *   outline.js    headings and \input files of the open file
 *   compile.js    compiling and the error list
 *   pdf.js        the PDF pane
 *   spellcheck.js spelling in .tex files
 *   shell.js      menus, toolbar, layouts, side panel and dialogs
 */
(function () {
    'use strict';

    var data = JSON.parse(document.getElementById('lxProject').textContent);
    var events = {};

    var Lx = window.Lx = {
        project: data,
        editor: null,

        on: function (name, handler) { (events[name] = events[name] || []).push(handler); },
        emit: function (name) {
            var args = Array.prototype.slice.call(arguments, 1);
            (events[name] || []).forEach(function (handler) { handler.apply(null, args); });
        },

        toast: function (message, kind) {
            if (window.PlainShow && window.PlainShow.toast) window.PlainShow.toast(message, kind === 'danger' || kind === 'warning' ? 'error' : kind);
        },

        /** POST JSON to an API endpoint; rejects with the server's message. */
        post: function (url, body) {
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ document_id: data.id }, body)) })
                .then(function (response) { return response.json().catch(function () { return {}; }); })
                .then(function (result) {
                    if (result.status !== 'success') throw new Error(result.message || result.error || psT('Something went wrong.'));
                    return result;
                });
        },

        /** Address of a project file, for previews and downloads. */
        fileUrl: function (path, fresh) {
            return 'temp/document_' + data.id + '/' + path.split('/').map(encodeURIComponent).join('/') + (fresh ? '?t=' + Date.now() : '');
        },

        name: function (path) { return path.split('/').pop(); },
        kind: function (path) {
            if (/\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(path)) return 'image';
            if (/\.pdf$/i.test(path)) return 'pdf';
            if (/\.(zip|gz|tgz|tar|7z|rar|docx?|xlsx?|pptx?|odt|ttf|otf|woff2?|eps|ps|mp4|mov|mp3|wav)$/i.test(path)) return 'binary';
            return 'text';
        },
        icon: function (path) {
            var kind = Lx.kind(path);
            if (kind === 'image') return 'bx-image';
            if (kind === 'pdf') return 'bxs-file-pdf';
            if (kind === 'binary') return 'bx-file-blank';
            if (/\.bib$/i.test(path)) return 'bx-book-bookmark';
            if (/\.(cls|sty)$/i.test(path)) return 'bx-cog';
            return 'bx-file';
        },
        modeFor: function (path) {
            var ext = (path.split('.').pop() || '').toLowerCase();
            if (['tex', 'cls', 'sty', 'bib', 'bbl', 'ltx', 'dtx'].indexOf(ext) !== -1) return 'stex';
            if (ext === 'py') return 'python';
            if (ext === 'js') return 'javascript';
            if (ext === 'json') return 'application/json';
            if (['xml', 'svg', 'html'].indexOf(ext) !== -1) return 'xml';
            if (ext === 'css') return 'css';
            if (ext === 'md' || ext === 'markdown') return 'markdown';
            if (ext === 'yaml' || ext === 'yml') return 'yaml';
            return 'null';
        },

        /** Under a path, or the path itself: for renames and removals of folders. */
        within: function (path, parent) { return path === parent || path.indexOf(parent + '/') === 0; },
        moved: function (path, from, to) { return Lx.within(path, from) ? to + path.slice(from.length) : path; },

        color: function (id, name) {
            var source = id + ':' + (name || ''), hash = 0;
            for (var i = 0; i < source.length; i++) { hash = ((hash << 5) - hash) + source.charCodeAt(i); hash |= 0; }
            var hue = Math.abs(hash) % 360;
            return { solid: 'hsl(' + hue + ' 72% 52%)', soft: 'hsla(' + hue + ' 78% 58% / 0.18)', ring: 'hsla(' + hue + ' 80% 35% / 0.28)' };
        },

        store: {
            get: function (key, fallback) { try { var value = localStorage.getItem(key); return value === null ? fallback : JSON.parse(value); } catch (e) { return fallback; } },
            set: function (key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* private mode */ } }
        },

        download: function (content, name, type) {
            var url = URL.createObjectURL(new Blob([content], { type: type || 'text/plain' }));
            var link = document.createElement('a');
            link.href = url; link.download = name; link.click();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        }
    };

    Lx.start = function () {
        Lx.editor = CodeMirror(document.getElementById('lxSource'), {
            value: '', mode: 'stex', readOnly: 'nocursor', lineNumbers: true, lineWrapping: true, theme: 'default',
            hintOptions: { hint: CodeMirror.hint.anyword }
        });
        Lx.emit('editor', Lx.editor);
        Lx.collab.connect();
        Lx.tree.load().then(function () { Lx.tabs.restore(); });
    };
})();
