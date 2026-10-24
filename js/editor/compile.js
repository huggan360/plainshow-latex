/*
 * Compiling: wait until every edit is saved, compile the document, then show
 * the PDF, or the errors with a link to the file and line of each.
 *
 * The document is the open .tex file when it is a whole document (it has
 * \documentclass); in a chapter or other part it stays the last document
 * shown, at first the project's main file. Switching to another document
 * shows its PDF, compiling it first if it has none yet.
 */
(function (Lx) {
    'use strict';

    var button = document.getElementById('compileButton');
    var loader = document.getElementById('loader');
    var box = document.getElementById('errorContainer');
    var badge = document.getElementById('compileIssues');
    var running = false;
    var again = false;
    var target = Lx.project.rootFile;

    function isDocument(path) {
        if (!path || !/\.tex$/i.test(path)) return false;
        var session = Lx.collab.session(path);
        return !!(session && session.loaded && /^[^%\n]*\\documentclass/m.test(session.doc.getValue()));
    }

    /** The same name the server gives the PDF (api/compile-project.php). */
    function pdfFor(file) {
        var id = Lx.project.id;
        var slug = file.replace(/\.tex$/i, '').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 120);
        return 'temp/document_' + id + '/document_' + id + (file === Lx.project.rootFile ? '' : '--' + slug) + '.pdf';
    }

    /** The open file became a document of its own: show its PDF. */
    function follow(path) {
        if (path !== Lx.tabs.active() || !isDocument(path) || path === target) return;
        target = path;
        badge.hidden = true;
        var address = pdfFor(path);
        fetch(address, { method: 'HEAD', cache: 'no-store' }).then(function (response) {
            if (target !== path) return;
            if (response.ok) Lx.pdf.show(address, path);
            else { Lx.pdf.clear(); compile(); }
        }).catch(function () {});
    }

    function compile() {
        if (running) { again = true; return; }
        running = true;
        again = false;
        button.disabled = true;
        if (isDocument(Lx.tabs.active())) target = Lx.tabs.active();
        var file = target;
        Lx.collab.waitForSync().then(function () {
            loader.style.display = 'inline-block';
            box.style.display = 'none';
            Lx.pdf.sync();
            return fetch('api/compile-project.php', { method: 'POST', body: new URLSearchParams({ document_id: Lx.project.id, file: file }) });
        }).then(function (response) {
            return response.json().catch(function () { return { status: 'error', message: psT('The compiler did not answer.') }; });
        }).then(function (result) {
            // Switched to another document meanwhile: its own PDF is on the way.
            if (file !== target) return;
            // Not a document after all: the server compiled the main file.
            if (result.file) target = result.file;
            badge.hidden = true;
            if (result.status === 'success') { Lx.pdf.show(result.pdf_path, target); return; }
            var lines = Array.isArray(result.output) ? result.output : [];
            if (result.status === 'partial') {
                // A PDF despite errors: show it, with the errors one click away.
                Lx.pdf.show(result.pdf_path, target);
                render(result.message, result.errors || [], result.warnings || [], lines, true);
                var count = (result.errors || []).length || 1;
                badge.querySelector('span').textContent = (count === 1 ? psT('1 error') : psT('{n} errors', { n: count }));
                badge.hidden = false;
                return;
            }
            render(result.message, result.errors || [], result.warnings || [], lines);
        }).catch(function (error) {
            Lx.toast(error.message, 'warning');
        }).finally(function () {
            running = false;
            button.disabled = false;
            loader.style.display = 'none';
            Lx.pdf.sync();
            if (again) compile();
        });
    }

    /** "./chapters/intro.tex:12: Undefined control sequence." -> where and what. */
    function parse(text) {
        var match = /^(?:\.\/)?(.+?\.(?:tex|sty|cls|bib|bbl)):(\d+):\s*(.*)$/.exec(String(text || '').trim());
        return match ? { path: match[1], line: Number(match[2]), message: match[3] || text } : { message: String(text || '') };
    }

    function render(message, errors, warnings, log, behindPdf) {
        box.innerHTML = '';
        var head = document.createElement('div');
        head.className = 'error-header';
        var copy = document.createElement('div');
        copy.className = 'error-header-copy';
        var title = document.createElement('h4');
        title.textContent = behindPdf ? psT('Compiled with errors') : errors.length || warnings.length || log.length ? psT('Compilation failed') : (message || psT('Compilation failed'));
        var subtitle = document.createElement('p');
        subtitle.className = 'error-subtitle';
        subtitle.textContent = errors.length + warnings.length
            ? (errors.length === 1 ? psT('1 error') : psT('{n} errors', { n: errors.length })) + ', ' + (warnings.length === 1 ? psT('1 warning') : psT('{n} warnings', { n: warnings.length })) + '.'
            : (log.length ? psT('No error lines were found. The log is below.') : (message && title.textContent !== message ? message : ''));
        copy.append(title, subtitle);
        head.appendChild(copy);
        if (Lx.pdf.current()) {
            var last = document.createElement('button');
            last.type = 'button';
            last.className = 'ps-btn ps-btn--sm ps-btn--ghost';
            last.textContent = psT('Show last PDF');
            last.addEventListener('click', Lx.pdf.showLast);
            head.appendChild(last);
        }
        box.appendChild(head);

        section('Errors', errors, 'error');
        section('Warnings', warnings, 'warning');

        if (log.length) {
            var details = document.createElement('details');
            details.className = 'log-details';
            var summary = document.createElement('summary');
            summary.textContent = psT('Compiler log ({n} lines)', { n: log.length });
            var pre = document.createElement('pre');
            pre.textContent = log.join('\n');
            details.append(summary, pre);
            box.appendChild(details);
        }
        box.scrollTop = 0;
        if (behindPdf) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        Lx.pdf.hide();
        Lx.pdf.sync();
    }

    function section(name, items, type) {
        if (!items.length) return;
        var wrap = document.createElement('section');
        wrap.className = 'error-section';
        var head = document.createElement('div');
        head.className = 'error-section-header';
        var title = document.createElement('h5');
        title.textContent = name;
        var count = document.createElement('span');
        count.className = 'error-section-count';
        count.textContent = items.length;
        head.append(title, count);
        wrap.appendChild(head);
        var list = document.createElement('ul');
        list.className = 'error-list';
        items.forEach(function (item) {
            var issue = parse(item);
            var li = document.createElement('li');
            li.className = 'error-pill ' + type;
            var top = document.createElement('div');
            top.className = 'error-pill-head';
            var kind = document.createElement('div');
            kind.className = 'error-pill-kind';
            kind.innerHTML = '<i class="bx ' + (type === 'error' ? 'bx-x-circle' : 'bx-error') + '"></i><span></span>';
            kind.querySelector('span').textContent = type === 'error' ? psT('Error') : psT('Warning');
            top.appendChild(kind);
            if (issue.path) {
                var where = document.createElement(Lx.tree.exists(issue.path) ? 'button' : 'span');
                where.className = 'error-pill-chip error-pill-link';
                where.textContent = psT('{path}, line {line}', { path: issue.path, line: issue.line });
                if (where.tagName === 'BUTTON') {
                    where.type = 'button';
                    where.title = psT('Go to the line');
                    where.addEventListener('click', function () { Lx.tabs.open(issue.path, { line: issue.line - 1 }); });
                }
                top.appendChild(where);
            }
            var text = document.createElement('p');
            text.className = 'error-pill-message';
            text.textContent = issue.message;
            li.append(top, text);
            list.appendChild(li);
        });
        wrap.appendChild(list);
        box.appendChild(wrap);
    }

    button.addEventListener('click', compile);
    badge.addEventListener('click', function () {
        if (box.style.display === 'none') { box.style.display = 'block'; Lx.pdf.hide(); }
        else Lx.pdf.showLast();
        Lx.pdf.sync();
    });
    Lx.on('active', follow);
    Lx.on('state', follow);
    Lx.compile = { run: compile, parse: parse, target: function () { return target; } };
})(window.Lx);
