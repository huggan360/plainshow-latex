/*
 * The file outline: the open file's \part, \chapter, \section and
 * \subsection headings, and the files it pulls in with \input or \include.
 * Click a heading to go there, a file to open it.
 */
(function (Lx) {
    'use strict';

    var list = document.getElementById('lxOutline');
    var levels = { part: 0, chapter: 0, section: 0, subsection: 1, subsubsection: 2 };
    var heading = /^\s*\\(part|chapter|section|subsection|subsubsection)\*?\s*(?:\[[^\]]*\])?\s*\{([^}]*)\}/;
    var include = /\\(input|include|subfile)\s*\{([^}]+)\}/;
    var timer = null;

    /** The project file an \input names: as written, or with .tex added. */
    function resolve(name) {
        name = name.trim().replace(/^\.\//, '');
        if (Lx.tree.exists(name)) return name;
        if (Lx.tree.exists(name + '.tex')) return name + '.tex';
        return null;
    }

    function build() {
        var path = Lx.tabs.active();
        var session = path ? Lx.collab.session(path) : null;
        list.innerHTML = '';
        if (!session || Lx.modeFor(path) !== 'stex') {
            note(path ? psT('No outline for this file.') : psT('Open a file to see its outline.'));
            return;
        }
        var items = [];
        session.doc.eachLine(function (line) {
            var text = line.text;
            var comment = text.search(/(^|[^\\])%/);
            if (comment !== -1) text = text.slice(0, comment + 1);
            var match = heading.exec(text);
            if (match) { items.push({ line: session.doc.getLineNumber(line), level: levels[match[1]], title: match[2] || match[1] }); return; }
            match = include.exec(text);
            if (match) items.push({ line: session.doc.getLineNumber(line), file: match[2] });
        });
        if (!items.length) { note('No sections in this file.'); return; }
        items.forEach(function (item) {
            var li = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            if (item.file) {
                var target = resolve(item.file);
                button.className = 'lx-outline__file' + (target ? '' : ' is-missing');
                button.innerHTML = '<i class="bx bx-file"></i><span></span>';
                button.querySelector('span').textContent = item.file;
                button.title = target ? psT('Open {name}', { name: target }) : psT('{name} is not in the project', { name: item.file });
                button.addEventListener('click', function () { if (target) Lx.tabs.open(target); else Lx.tabs.open(path, { line: item.line }); });
            } else {
                button.className = 'lvl-' + item.level;
                button.textContent = item.title;
                button.title = psT('Line {n}', { n: item.line + 1 });
                button.addEventListener('click', function () { Lx.tabs.open(path, { line: item.line }); });
            }
            li.appendChild(button);
            list.appendChild(li);
        });
    }

    function note(text) {
        var li = document.createElement('li');
        li.className = 'lx-outline__empty';
        li.textContent = text;
        list.appendChild(li);
    }

    function later() { window.clearTimeout(timer); timer = window.setTimeout(build, 300); }

    Lx.on('editor', function (editor) { editor.on('changes', later); });
    Lx.on('active', later);
    Lx.on('state', later);
    Lx.on('remote', function (path) { if (path === Lx.tabs.active()) later(); });

    Lx.outline = { build: build };
})(window.Lx);
