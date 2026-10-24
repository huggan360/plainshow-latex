/*
 * The editor's frame: menus, toolbar, layouts, the side panel and the pane
 * divider, the status bar, keyboard shortcuts, and the add files, share and
 * shortcut dialogs.
 */
(function (Lx) {
    'use strict';

    var ide = document.getElementById('lxIde');
    var sidebar = document.getElementById('sidebarEl');
    function cm() { return Lx.editor; }
    function editable() { return Lx.editor && !Lx.editor.getOption('readOnly'); }

    /* ---------- Dialogs ---------- */

    var lastFocus = null;
    function openModal(id) {
        closeMenus();
        var modal = document.getElementById(id);
        lastFocus = document.activeElement;
        modal.hidden = false;
        var first = modal.querySelector('input:not([type=hidden]):not([hidden]), select, button');
        if (first) window.setTimeout(function () { first.focus(); if (first.select) first.select(); }, 30);
    }
    function closeModal(modal) {
        modal.hidden = true;
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var backdrop = target && target.closest('.ps-modal-backdrop');
        if (backdrop && (target === backdrop || target.closest('[data-modal-close]'))) closeModal(backdrop);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('.ps-modal-backdrop:not([hidden])').forEach(closeModal);
        closeMenus();
    });

    /* ---------- Menus ---------- */

    var openMenu = null;
    function closeMenus() {
        if (!openMenu) return;
        openMenu.classList.remove('is-open');
        openMenu.querySelector('.lx-menu__panel').hidden = true;
        openMenu = null;
    }
    function showMenu(menu) {
        closeMenus();
        openMenu = menu;
        menu.classList.add('is-open');
        menu.querySelector('.lx-menu__panel').hidden = false;
    }
    document.querySelectorAll('.lx-menu').forEach(function (menu) {
        var button = menu.querySelector('[data-menu]');
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            openMenu === menu ? closeMenus() : showMenu(menu);
        });
        button.addEventListener('mouseenter', function () { if (openMenu && openMenu !== menu) showMenu(menu); });
    });
    document.addEventListener('click', function (event) {
        if (openMenu && !(event.target instanceof Element && event.target.closest('.lx-menu__panel'))) closeMenus();
    });

    /* ---------- Editing helpers ---------- */

    var snippets = {
        'section': ['\\section{', 'Section title', '}\n'],
        'subsection': ['\\subsection{', 'Subsection title', '}\n'],
        'inline-math': ['$', 'x', '$'],
        'equation': ['\\begin{equation}\n  ', 'E = mc^2', '\n\\end{equation}\n'],
        'itemize': ['\\begin{itemize}\n  \\item ', 'First item', '\n\\end{itemize}\n'],
        'enumerate': ['\\begin{enumerate}\n  \\item ', 'First item', '\n\\end{enumerate}\n'],
        'table': ['\\begin{table}[h]\n  \\centering\n  \\begin{tabular}{|c|c|}\n    \\hline\n    ', 'A & B', ' \\\\\n    \\hline\n  \\end{tabular}\n  \\caption{Caption}\n\\end{table}\n'],
        'figure': ['\\begin{figure}[h]\n  \\centering\n  \\includegraphics[width=0.8\\linewidth]{', 'image.png', '}\n  \\caption{Caption}\n\\end{figure}\n'],
        'link': ['\\href{', 'https://example.com', '}{text}'],
        'cite': ['\\cite{', 'key', '}']
    };
    function insertSnippet(name) {
        if (!editable()) { Lx.toast(psT('Open a text file to write in.'), 'warning'); return; }
        var parts = snippets[name];
        var middle = cm().getSelection() || parts[1];
        var from = cm().getCursor('from');
        cm().replaceSelection(parts[0] + middle + parts[2]);
        var start = cm().indexFromPos(from) + parts[0].length;
        cm().setSelection(cm().posFromIndex(start), cm().posFromIndex(start + middle.length));
        cm().focus();
    }
    function wrapSelection(command) {
        if (!editable()) return;
        var selected = cm().getSelection();
        var from = cm().getCursor('from');
        cm().replaceSelection('\\' + command + '{' + selected + '}');
        var start = cm().indexFromPos(from) + command.length + 2;
        cm().setSelection(cm().posFromIndex(start), cm().posFromIndex(start + selected.length));
        cm().focus();
    }
    function toggleComment() {
        if (!editable()) return;
        var from = cm().getCursor('from').line, to = cm().getCursor('to').line, lines = [];
        for (var i = from; i <= to; i++) lines.push(cm().getLine(i));
        var commented = lines.every(function (line) { return /^\s*%/.test(line) || !line.trim(); });
        cm().operation(function () {
            for (var n = from; n <= to; n++) {
                var line = cm().getLine(n);
                if (commented) {
                    var at = line.indexOf('%');
                    if (at !== -1) cm().replaceRange('', { line: n, ch: at }, { line: n, ch: at + (line.charAt(at + 1) === ' ' ? 2 : 1) });
                } else if (line.trim()) {
                    cm().replaceRange('% ', { line: n, ch: 0 });
                }
            }
        });
        cm().focus();
    }

    /* ---------- Layout and side panel ---------- */

    function setLayout(layout) {
        ide.dataset.layout = layout;
        Lx.store.set('latex-layout', layout);
        document.querySelectorAll('[data-layout-set]').forEach(function (item) { item.classList.toggle('is-checked', item.dataset.layoutSet === layout); });
        window.setTimeout(function () { cm() && cm().refresh(); }, 30);
    }
    setLayout(Lx.store.get('latex-layout', 'split'));

    function phone() { return window.matchMedia('(max-width: 900px)').matches; }
    var mini = Lx.store.get('sidebar_mini', false) === true || Lx.store.get('sidebar_mini', 0) === 1;
    function applyPanel() {
        sidebar.classList.toggle('mini', mini);
        sidebar.style.width = mini ? '' : Math.max(220, Math.min(700, Number(Lx.store.get('sidebar_width', 270)) || 270)) + 'px';
        syncRail();
    }
    function syncRail() {
        var open = phone() ? sidebar.classList.contains('is-mobile-open') : !sidebar.classList.contains('mini');
        document.querySelectorAll('.lx-rail [data-act="toggle-panel"]').forEach(function (button) { button.classList.toggle('is-active', open); });
    }
    function togglePanel() {
        if (phone()) { sidebar.classList.toggle('is-mobile-open'); syncRail(); return; }
        mini = !mini;
        Lx.store.set('sidebar_mini', mini);
        applyPanel();
        window.setTimeout(function () { cm().refresh(); }, 260);
    }
    function ensurePanel() {
        if (phone()) { sidebar.classList.add('is-mobile-open'); syncRail(); return; }
        if (mini) togglePanel();
    }
    applyPanel();
    // On a phone the panel opens over the source and closes once a file is picked.
    sidebar.addEventListener('click', function (event) {
        if (phone() && event.target instanceof Element && event.target.closest('.workspace-node[data-node-type="file"], .lx-outline button')) {
            window.setTimeout(function () { sidebar.classList.remove('is-mobile-open'); syncRail(); }, 120);
        }
    });

    var handle = document.getElementById('sidebarResizeHandle');
    handle.addEventListener('mousedown', function (event) {
        event.preventDefault();
        var startX = event.clientX, startWidth = sidebar.getBoundingClientRect().width;
        handle.classList.add('dragging');
        sidebar.style.transition = 'none';
        function moveTo(e) {
            var width = startWidth + e.clientX - startX;
            if (width < 120) { stop(); mini = true; Lx.store.set('sidebar_mini', true); applyPanel(); return; }
            width = Math.min(700, Math.max(220, width));
            sidebar.style.width = width + 'px';
            Lx.store.set('sidebar_width', width);
        }
        function stop() {
            handle.classList.remove('dragging');
            sidebar.style.transition = '';
            document.removeEventListener('mousemove', moveTo);
            document.removeEventListener('mouseup', stop);
            cm().refresh();
        }
        document.addEventListener('mousemove', moveTo);
        document.addEventListener('mouseup', stop);
    });

    // The divider between source and PDF.
    var divider = document.querySelector('.divider');
    function resizePanes(position) {
        var editor = document.querySelector('.editor');
        var left = editor.getBoundingClientRect().left;
        var available = document.querySelector('.latex-container').getBoundingClientRect().right - left - 5;
        if (available < 480) return;
        editor.style.flex = '0 0 ' + Math.max(240, Math.min(available - 240, position - left)) + 'px';
        document.querySelector('.preview').style.flex = '1 1 0';
        cm().refresh();
    }
    divider.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        resizePanes(divider.getBoundingClientRect().left + (event.key === 'ArrowLeft' ? -25 : 25));
    });
    divider.addEventListener('pointerdown', function (event) {
        event.preventDefault();
        divider.setPointerCapture(event.pointerId);
        document.getElementById('preview').style.pointerEvents = 'none';
    });
    divider.addEventListener('pointermove', function (event) { if (divider.hasPointerCapture(event.pointerId)) resizePanes(event.clientX); });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (type) {
        divider.addEventListener(type, function () { document.getElementById('preview').style.pointerEvents = ''; });
    });
    window.addEventListener('resize', function () {
        document.querySelector('.editor').style.flex = '';
        document.querySelector('.preview').style.flex = '';
        syncRail();
        cm() && cm().refresh();
    });

    /* ---------- Actions ---------- */

    function downloadActive() {
        var path = Lx.tabs.active();
        if (!path) { Lx.toast(psT('Open a file first.'), 'warning'); return; }
        var link = document.createElement('a');
        link.href = Lx.fileUrl(path);
        link.download = Lx.name(path);
        link.click();
    }
    function reloadActive() {
        var path = Lx.tabs.active();
        if (!path || !Lx.collab.session(path)) return;
        if (Lx.collab.pending(path)) {
            if (!window.confirm(psT('Download your unsynced edits as a recovery copy and load the server version?'))) return;
            Lx.collab.recoveryCopy();
        }
        Lx.collab.reload(path);
    }

    var actions = {
        'add-files': function () { openAddFiles('new'); },
        'new-file': function () { Lx.tree.createFile(Lx.tree.folder()); },
        'new-folder': function () { Lx.tree.createFolder(Lx.tree.folder()); },
        'upload': function () { Lx.tree.pickFiles(Lx.tree.folder()); },
        'download-pdf': function () { Lx.pdf.download(); },
        'open-pdf': function () { Lx.pdf.openTab(); },
        'download-source': downloadActive,
        'recovery': function () { Lx.collab.recoveryCopy(); },
        'reload': reloadActive,
        'close-tab': function () { Lx.tabs.closeActive(); },
        'undo': function () { cm().undo(); cm().focus(); },
        'redo': function () { cm().redo(); cm().focus(); },
        'find': function () { cm().focus(); cm().execCommand('findPersistent'); },
        'replace': function () { cm().focus(); cm().execCommand('replace'); },
        'select-all': function () { cm().execCommand('selectAll'); cm().focus(); },
        'comment': toggleComment,
        'toggle-panel': togglePanel,
        'files': function () { ensurePanel(); document.getElementById('fileFilter').focus(); },
        'outline': function () { ensurePanel(); document.getElementById('lxOutlineSection').scrollIntoView({ block: 'nearest' }); Lx.outline.build(); },
        'wrap': function () { cm().setOption('lineWrapping', !cm().getOption('lineWrapping')); },
        'shortcuts': function () { openModal('lxKeys'); },
        'share': function () { openShare(); },
        'compile': function () { Lx.compile.run(); },
        'noop': function () {}
    };
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        var act = target.closest('[data-act]'), insert = target.closest('[data-insert]'), wrap = target.closest('[data-wrap]'), layout = target.closest('[data-layout-set]');
        if (!act && !insert && !wrap && !layout) return;
        closeMenus();
        if (act && actions[act.dataset.act]) actions[act.dataset.act]();
        else if (insert) insertSnippet(insert.dataset.insert);
        else if (wrap) wrapSelection(wrap.dataset.wrap);
        else if (layout) setLayout(layout.dataset.layoutSet);
    });

    /* ---------- Status bar ---------- */

    var statusText = { review: psT('Review unsynced edits'), offline: psT('Reconnecting'), sync: psT('Loading'), saving: psT('Saving'), saved: psT('All changes saved') };
    function updateStatus() {
        var state = Lx.collab.status();
        var status = document.getElementById('saveStatus');
        status.textContent = statusText[state];
        status.dataset.state = state === 'saved' ? 'saved' : 'pending';
        var pos = cm().getCursor();
        document.getElementById('cursorStatus').textContent = Lx.tabs.active() && Lx.kind(Lx.tabs.active()) === 'text' ? psT('Ln {line}, Col {col}', { line: pos.line + 1, col: pos.ch + 1 }) : '';
    }

    function renderOnline(presence) {
        var host = document.getElementById('onlineUsers');
        host.innerHTML = '';
        // One chip per person, however many tabs they have open.
        var people = [], seen = {};
        Object.keys(presence).forEach(function (client) {
            var person = presence[client];
            if (seen[person.user_id]) { if (person.path && !seen[person.user_id].path) seen[person.user_id].path = person.path; return; }
            seen[person.user_id] = { id: person.user_id, name: person.user_name, path: person.path };
            people.push(seen[person.user_id]);
        });
        people.slice(0, 6).forEach(function (person) {
            var colors = Lx.color(person.id, person.name);
            var chip = document.createElement('div');
            chip.className = 'online-user-chip';
            chip.title = (person.path ? psT('{name}, in {path}', { name: person.name || psT('Someone'), path: person.path }) : (person.name || psT('Someone')));
            var dot = document.createElement('div');
            dot.className = 'user-square';
            var picture = (Lx.project.pictures || {})[person.id];
            if (picture) {
                var img = document.createElement('img');
                img.src = picture;
                img.alt = '';
                // A broken picture falls back to the initial.
                img.addEventListener('error', function () { dot.textContent = (person.name || '?').charAt(0).toUpperCase(); });
                dot.appendChild(img);
                dot.classList.add('user-square--photo');
            } else {
                dot.textContent = (person.name || '?').charAt(0).toUpperCase();
            }
            dot.style.background = colors.solid;
            dot.style.boxShadow = '0 0 0 3px ' + colors.ring;
            var name = document.createElement('span');
            name.className = 'online-user-name';
            name.textContent = person.name || psT('Someone');
            chip.append(dot, name);
            if (person.path && String(person.id) !== String(Lx.project.userId)) {
                chip.classList.add('is-link');
                chip.addEventListener('click', function () { if (Lx.tree.exists(person.path)) Lx.tabs.open(person.path); });
            }
            host.appendChild(chip);
        });
        if (people.length > 6) {
            var more = document.createElement('span');
            more.textContent = '+' + (people.length - 6);
            more.title = people.slice(6).map(function (p) { return p.name; }).join(', ');
            host.appendChild(more);
        }
    }

    /* ---------- Add files ---------- */

    var addModal = document.getElementById('lxAddFiles');
    function addTab(name) {
        addModal.querySelectorAll('[data-add-tab]').forEach(function (tab) { tab.classList.toggle('is-active', tab.dataset.addTab === name); });
        addModal.querySelectorAll('[data-add-pane]').forEach(function (pane) { pane.hidden = pane.dataset.addPane !== name; });
        if (name === 'project') loadProjects();
        var field = addModal.querySelector('[data-add-pane="' + name + '"] input:not([type=file]), [data-add-pane="' + name + '"] select');
        if (field) window.setTimeout(function () { field.focus(); if (field.name === 'name' && field.value === 'name.tex') field.setSelectionRange(0, 4); }, 30);
    }
    function openAddFiles(tab) {
        addModal.querySelector('[data-add-folder]').textContent = Lx.tree.folder() || psT('the project folder');
        openModal('lxAddFiles');
        addTab(tab || 'new');
    }
    addModal.querySelectorAll('[data-add-tab]').forEach(function (tab) { tab.addEventListener('click', function () { addTab(tab.dataset.addTab); }); });
    function added(paths) {
        paths.forEach(function (path) { Lx.collab.workspaceEvent('created', { path: path, entry_type: 'file' }); });
        return Lx.tree.load();
    }

    addModal.querySelector('[data-add-pane="new"]').addEventListener('submit', function (event) {
        event.preventDefault();
        var name = event.target.name.value.trim();
        Lx.post('api/create-workspace-file.php', { parent: Lx.tree.folder(), file_name: name }).then(function (body) {
            closeModal(addModal);
            return added([body.path]).then(function () { Lx.tabs.open(body.path, { focus: true }); });
        }).catch(function (error) { Lx.toast(error.message, 'danger'); });
    });

    var drop = addModal.querySelector('[data-add-drop]');
    var dropInput = addModal.querySelector('[data-add-input]');
    function upload(files) {
        if (!files.length) return;
        Lx.tree.upload(files, Lx.tree.folder());
        closeModal(addModal);
    }
    dropInput.addEventListener('change', function () { upload(Array.prototype.slice.call(dropInput.files)); dropInput.value = ''; });
    ['dragenter', 'dragover'].forEach(function (type) { drop.addEventListener(type, function (event) { event.preventDefault(); drop.classList.add('is-over'); }); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('is-over'); });
    drop.addEventListener('drop', function (event) { event.preventDefault(); drop.classList.remove('is-over'); upload(Array.prototype.slice.call(event.dataTransfer.files)); });

    var projectPane = addModal.querySelector('[data-add-pane="project"]');
    var projectSelect = projectPane.querySelector('[data-add-projects]');
    var projectFiles = projectPane.querySelector('[data-add-files]');
    var projectsLoaded = false;
    function loadProjects() {
        if (projectsLoaded) return;
        projectsLoaded = true;
        fetch('api/list-projects.php').then(function (r) { return r.json(); }).then(function (body) {
            (body.projects || []).filter(function (p) { return p.id !== Number(Lx.project.id); }).forEach(function (p) {
                var option = document.createElement('option');
                option.value = p.id;
                option.textContent = p.title;
                projectSelect.appendChild(option);
            });
        });
    }
    projectSelect.addEventListener('change', function () {
        projectFiles.innerHTML = '';
        if (!projectSelect.value) return;
        fetch('api/list-project-files.php?document_id=' + encodeURIComponent(projectSelect.value)).then(function (r) { return r.json(); }).then(function (body) {
            if (body.status !== 'success') throw new Error(body.message || psT('Could not list the files.'));
            if (!body.files.length) { projectFiles.innerHTML = '<li class="ps-muted ps-small">' + psT('This project has no files.') + '</li>'; return; }
            body.files.forEach(function (path) {
                var li = document.createElement('li');
                li.innerHTML = '<label><input type="checkbox"><span></span></label>';
                li.querySelector('input').value = path;
                li.querySelector('span').textContent = path;
                projectFiles.appendChild(li);
            });
        }).catch(function (error) { Lx.toast(error.message, 'danger'); });
    });
    projectPane.addEventListener('submit', function (event) {
        event.preventDefault();
        var files = Array.prototype.map.call(projectFiles.querySelectorAll('input:checked'), function (box) { return box.value; });
        if (!files.length) { Lx.toast(psT('Choose at least one file.'), 'warning'); return; }
        Lx.post('api/copy-from-project.php', { source_id: Number(projectSelect.value), files: files, target_dir: Lx.tree.folder() }).then(function (body) {
            closeModal(addModal);
            Lx.toast((body.paths.length === 1 ? psT('1 file copied.') : psT('{n} files copied.', { n: body.paths.length })), 'success');
            return added(body.paths);
        }).catch(function (error) { Lx.toast(error.message, 'danger'); });
    });

    addModal.querySelector('[data-add-pane="url"]').addEventListener('submit', function (event) {
        event.preventDefault();
        var form = event.target;
        var submit = form.querySelector('[type=submit]');
        submit.disabled = true;
        Lx.post('api/import-url.php', { url: form.url.value.trim(), name: form.name.value.trim(), target_dir: Lx.tree.folder() }).then(function (body) {
            closeModal(addModal);
            Lx.toast(psT('Fetched {path}.', { path: body.path }), 'success');
            form.reset();
            return added([body.path]);
        }).catch(function (error) { Lx.toast(error.message, 'danger'); }).finally(function () { submit.disabled = false; });
    });

    /* ---------- Share and rename ---------- */

    var shareForm = document.querySelector('[data-share-form]');
    function openShare() {
        if (!Lx.project.owner || !shareForm) return;
        var people = shareForm.querySelector('[data-share-people]');
        openModal('lxShare');
        fetch('api/get-document-details.php?document_id=' + encodeURIComponent(Lx.project.id)).then(function (r) { return r.json(); }).then(function (body) {
            if (body.status !== 'success') throw new Error(body.message || psT('Could not load the project.'));
            shareForm.title.value = body.document.title;
            var shared = (body.shared_users || []).map(String);
            people.innerHTML = '';
            function heading(text) { var node = document.createElement('div'); node.className = 'lx-picker-heading'; node.textContent = text; people.appendChild(node); }
            function avatar(user) {
                var node = document.createElement('span'); node.className = 'ps-avatar ps-avatar--xs'; var source = (user.image_source || '').trim();
                if (source && !/^https?:\/\//.test(source)) source = (window.PS_PICTURES || '/') + source.replace(/^\//, '');
                if (source) { var image = document.createElement('img'); image.className = 'ps-avatar__img'; image.src = source; image.alt = ''; node.appendChild(image); }
                else { var letters = document.createElement('span'); letters.className = 'ps-avatar__initials'; letters.textContent = ((user.firstname || '?')[0] + (user.lastname || '')[0]).toUpperCase(); node.appendChild(letters); }
                return node;
            }
            if ((body.groups || []).length) heading('Groups');
            (body.groups || []).forEach(function (group) {
                var label = document.createElement('label');
                label.className = 'lx-person';
                label.dataset.name = group.name.toLowerCase();
                label.innerHTML = '<input type="checkbox" data-group><span class="lx-group-avatar"><i class="bx bx-group"></i></span><span class="lx-person__text"><strong></strong></span><span class="ps-badge ps-badge--accent">' + psT('Group') + '</span>';
                label.querySelector('input').value = group.id;
                label.querySelector('input').checked = (body.shared_groups || []).map(String).indexOf(String(group.id)) !== -1;
                label.querySelector('strong').textContent = group.name;
                people.appendChild(label);
            });
            if (body.users.length) heading('Suggested people');
            body.users.forEach(function (user, index) {
                if (String(user.user_id) === String(Lx.project.userId)) return;
                if (index === 3) heading('People');
                var label = document.createElement('label'); label.className = 'lx-person'; label.dataset.name = (user.firstname + ' ' + user.lastname + ' ' + (user.username || '')).toLowerCase();
                label.innerHTML = '<input type="checkbox"><span class="lx-person__text"><strong></strong><small></small></span>';
                label.querySelector('input').value = user.user_id; label.querySelector('input').checked = shared.indexOf(String(user.user_id)) !== -1;
                label.insertBefore(avatar(user), label.querySelector('.lx-person__text')); label.querySelector('strong').textContent = (user.firstname + ' ' + user.lastname).trim(); label.querySelector('small').textContent = user.username ? '@' + user.username : '';
                people.appendChild(label);
            });
        }).catch(function (error) { Lx.toast(error.message, 'danger'); });
    }
    if (shareForm) shareForm.querySelector('[data-share-filter]').addEventListener('input', function (event) {
        var query = event.target.value.trim().toLowerCase();
        shareForm.querySelectorAll('[data-share-people] .lx-person').forEach(function (row) { row.hidden = query !== '' && row.dataset.name.indexOf(query) === -1; });
    });
    if (shareForm) shareForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var shared = Array.prototype.map.call(shareForm.querySelectorAll('[data-share-people] input:checked:not([data-group])'), function (box) { return box.value; });
        var groups = Array.prototype.map.call(shareForm.querySelectorAll('[data-share-people] input[data-group]:checked'), function (box) { return box.value; });
        var title = shareForm.title.value.trim();
        Lx.post('api/edit-document.php', { title: title, shared_users: shared, shared_groups: groups }).then(function () {
            Lx.project.title = title;
            document.querySelector('[data-doc-title]').textContent = title;
            document.title = title + ' | LaTeX';
            closeModal(document.getElementById('lxShare'));
            Lx.tree.render();
            Lx.toast(psT('Saved.'), 'success');
        }).catch(function (error) { Lx.toast(error.message, 'danger'); });
    });

    /* ---------- Keyboard ---------- */

    Lx.on('editor', function (editor) {
        editor.setOption('extraKeys', {
            'Ctrl-Enter': function () { Lx.compile.run(); }, 'Cmd-Enter': function () { Lx.compile.run(); },
            'Ctrl-S': function () { Lx.compile.run(); }, 'Cmd-S': function () { Lx.compile.run(); },
            'Ctrl-Space': function (instance) { instance.showHint({ hint: CodeMirror.hint.anyword }); },
            'Ctrl-F': 'findPersistent', 'Cmd-F': 'findPersistent',
            'Ctrl-H': 'replace', 'Shift-Cmd-H': 'replace',
            'Alt-Z': function (instance) { instance.setOption('lineWrapping', !instance.getOption('lineWrapping')); },
            'Ctrl-B': function () { wrapSelection('textbf'); }, 'Cmd-B': function () { wrapSelection('textbf'); },
            'Ctrl-I': function () { wrapSelection('textit'); }, 'Cmd-I': function () { wrapSelection('textit'); },
            'Ctrl-/': toggleComment, 'Cmd-/': toggleComment,
            'Alt-W': function () { Lx.tabs.closeActive(); },
            'Alt-PageDown': function () { Lx.tabs.next(1); }, 'Alt-PageUp': function () { Lx.tabs.next(-1); }
        });
        editor.on('cursorActivity', updateStatus);
        window.setInterval(updateStatus, 500);
        updateStatus();
    });
    // Outside the source editor too (it handles its own keys first).
    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented) return;
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); Lx.compile.run(); }
        else if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S')) { event.preventDefault(); Lx.compile.run(); }
        else if (event.altKey && (event.key === 'w' || event.key === 'W')) { event.preventDefault(); Lx.tabs.closeActive(); }
    });

    Lx.on('presence', renderOnline);
    Lx.on('root', function () { Lx.tabs.render(); });
    Lx.shell = { openModal: openModal };
})(window.Lx);
