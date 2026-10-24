/*
 * The file tree: every file in the project, main.tex among them. Click a file
 * to open it in a tab; right-click (or the ... button) for its menu. Changes
 * go through the file API, then the collaboration server tells everyone.
 */
(function (Lx) {
    'use strict';

    var host = document.getElementById('workspaceTree');
    var filter = document.getElementById('fileFilter');
    var folderKey = 'lx-folders:' + Lx.project.id;
    var openFolders = Lx.store.get(folderKey, {});
    var data = [];
    var paths = new Set();
    var selectedFolder = '';
    var dragging = false;

    function index(nodes) {
        nodes.forEach(function (node) {
            paths.add(node.path);
            if (node.children) index(node.children);
        });
    }

    function load() {
        return fetch('api/get-workspace-tree.php?document_id=' + Lx.project.id, { cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                if (body.status !== 'success') throw new Error(body.message || psT('Could not load the files.'));
                data = body.tree || [];
                paths = new Set();
                index(data);
                render();
            })
            .catch(function (error) {
                host.innerHTML = '';
                var message = document.createElement('div');
                message.className = 'workspace-empty';
                message.textContent = error.message;
                host.appendChild(message);
            });
    }

    function usersIn(path) {
        var presence = Lx.collab.presence();
        var seen = {};
        return Object.keys(presence).map(function (client) { return presence[client]; }).filter(function (person) {
            if (String(person.user_id) === String(Lx.project.userId) || person.path !== path || seen[person.user_id]) return false;
            return (seen[person.user_id] = true);
        }).map(function (person) { return { id: person.user_id, name: person.user_name }; });
    }

    function render() {
        host.innerHTML = '';
        var root = document.createElement('button');
        root.type = 'button';
        root.className = 'workspace-node workspace-node--root' + (selectedFolder === '' ? ' selected-folder' : '');
        root.innerHTML = '<i class="bx bx-folder-open"></i><span class="workspace-node-name"></span>';
        root.querySelector('span').textContent = Lx.project.title;
        root.addEventListener('click', function () { selectedFolder = ''; render(); });
        root.addEventListener('contextmenu', function (event) { event.preventDefault(); menu(event.clientX, event.clientY, { path: '', type: 'folder', name: '' }); });
        dropTarget(root, '');
        host.appendChild(root);

        if (!data.length) {
            var none = document.createElement('div');
            none.className = 'workspace-empty';
            none.textContent = psT('No files yet. Create or upload one.');
            host.appendChild(none);
            return;
        }
        renderNodes(data, 0);
        applyFilter();
    }

    function renderNodes(nodes, depth) {
        var query = filter.value.trim();
        nodes.forEach(function (node) {
            var row = document.createElement('div');
            row.className = 'workspace-row';
            if (depth > 0) {
                var branch = document.createElement('div');
                branch.className = 'workspace-branch';
                for (var level = 0; level < depth - 1; level++) {
                    var line = document.createElement('span');
                    line.className = 'workspace-branch-line';
                    line.style.left = (12 + level * 18) + 'px';
                    branch.appendChild(line);
                }
                var elbow = document.createElement('span');
                elbow.className = 'workspace-branch-elbow';
                elbow.style.left = (12 + (depth - 1) * 18) + 'px';
                branch.appendChild(elbow);
                row.appendChild(branch);
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'workspace-node';
            button.style.paddingLeft = (16 + depth * 18) + 'px';
            button.dataset.path = node.path;
            button.dataset.nodeType = node.type;
            button.draggable = true;
            button.title = node.path;
            button.addEventListener('dragstart', function (event) {
                dragging = true;
                event.dataTransfer.setData('text/ws-path', node.path);
                event.dataTransfer.effectAllowed = 'move';
                button.classList.add('dragging');
            });
            button.addEventListener('dragend', function () { dragging = false; button.classList.remove('dragging'); });

            var folderOpen = openFolders[node.path] !== false;
            var icon = document.createElement('i');
            icon.className = 'bx ' + (node.type === 'folder' ? (folderOpen ? 'bx-folder-open' : 'bx-folder') : Lx.icon(node.path));
            var name = document.createElement('span');
            name.className = 'workspace-node-name';
            name.textContent = node.name;
            button.append(icon, name);

            if (node.type === 'file' && node.path === Lx.project.rootFile) {
                var main = document.createElement('span');
                main.className = 'workspace-node-main';
                main.textContent = 'main';
                main.title = psT('Main file: compiling starts here');
                button.appendChild(main);
            }
            usersIn(node.path).forEach(function (user) {
                var tag = document.createElement('span');
                var colors = Lx.color(user.id, user.name);
                tag.className = 'user-tag';
                tag.textContent = (user.name || '?').charAt(0).toUpperCase();
                tag.title = psT('{name} is here', { name: user.name || psT('Someone') });
                tag.style.background = colors.solid;
                button.appendChild(tag);
            });

            var more = document.createElement('span');
            more.className = 'workspace-node-actions';
            more.innerHTML = '<button type="button" aria-label="' + psT('More') + '"><i class="bx bx-dots-horizontal-rounded"></i></button>';
            more.querySelector('button').addEventListener('click', function (event) {
                event.stopPropagation();
                var box = event.currentTarget.getBoundingClientRect();
                menu(box.left, box.bottom + 4, node);
            });
            button.appendChild(more);
            button.addEventListener('contextmenu', function (event) { event.preventDefault(); menu(event.clientX, event.clientY, node); });

            if (node.type === 'folder') {
                if (selectedFolder === node.path) button.classList.add('selected-folder');
                button.addEventListener('click', function () {
                    openFolders[node.path] = !folderOpen;
                    Lx.store.set(folderKey, openFolders);
                    selectedFolder = node.path;
                    render();
                });
                dropTarget(button, node.path);
            } else {
                if (node.path === Lx.tabs.active()) button.classList.add('active');
                button.addEventListener('click', function () { Lx.tabs.open(node.path); });
            }

            row.appendChild(button);
            host.appendChild(row);
            if (node.type === 'folder' && (folderOpen || query)) renderNodes(node.children || [], depth + 1);
        });
    }

    function applyFilter() {
        var query = filter.value.trim().toLowerCase();
        host.querySelectorAll('.workspace-row').forEach(function (row) {
            var node = row.querySelector('[data-path]');
            row.hidden = !!query && node && node.dataset.path.toLowerCase().indexOf(query) === -1;
        });
    }

    function dropTarget(element, folder) {
        element.addEventListener('dragover', function (event) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; element.classList.add('drop-target'); });
        element.addEventListener('dragleave', function () { element.classList.remove('drop-target'); });
        element.addEventListener('drop', function (event) {
            event.preventDefault();
            event.stopPropagation();
            element.classList.remove('drop-target');
            host.classList.remove('drag-active', 'dragover');
            if (event.dataTransfer.files.length) { upload(event.dataTransfer.files, folder); return; }
            var source = event.dataTransfer.getData('text/ws-path');
            if (source && source !== folder && !Lx.within(folder, source)) move(source, folder);
        });
    }

    /* ---------- The file and folder menu ---------- */

    function menu(x, y, node) {
        var existing = document.querySelector('.workspace-context-menu');
        if (existing) existing.remove();
        var box = document.createElement('div');
        box.className = 'workspace-context-menu';
        box.setAttribute('role', 'menu');
        var items = [];
        if (node.type === 'file') {
            items.push(['bx-file', psT('Open'), function () { Lx.tabs.open(node.path); }]);
            if (/\.tex$/i.test(node.path) && node.path !== Lx.project.rootFile) items.push(['bx-star', psT('Use as main file'), function () { setRoot(node.path); }]);
            items.push(['bx-download', psT('Download'), function () { var a = document.createElement('a'); a.href = Lx.fileUrl(node.path); a.download = node.name; a.click(); }]);
        } else {
            items.push(['bx-file-blank', psT('New file'), function () { createFile(node.path); }]);
            items.push(['bx-folder-plus', psT('New folder'), function () { createFolder(node.path); }]);
            items.push(['bx-upload', psT('Upload files'), function () { pickFiles(node.path); }]);
        }
        if (node.path !== '') {
            items.push(null);
            items.push(['bx-pencil', psT('Rename'), function () { rename(node); }]);
            items.push(['bx-trash', 'Delete', function () { remove(node); }, true]);
        }
        items.forEach(function (item) {
            if (!item) { var sep = document.createElement('div'); sep.className = 'lx-menu__sep'; box.appendChild(sep); return; }
            var entry = document.createElement('button');
            entry.type = 'button';
            entry.className = 'workspace-context-menu-item' + (item[3] ? ' is-danger' : '');
            entry.setAttribute('role', 'menuitem');
            entry.innerHTML = '<i class="bx ' + item[0] + '"></i><span></span>';
            entry.querySelector('span').textContent = item[1];
            entry.addEventListener('click', function () { box.remove(); item[2](); });
            box.appendChild(entry);
        });
        document.body.appendChild(box);
        var width = box.offsetWidth, height = box.offsetHeight;
        box.style.left = Math.max(8, Math.min(x, window.innerWidth - width - 8)) + 'px';
        box.style.top = Math.max(8, Math.min(y, window.innerHeight - height - 8)) + 'px';
        var first = box.querySelector('button');
        if (first) first.focus();
        var dismiss = function (event) {
            if (event.type === 'keydown' && event.key !== 'Escape') return;
            if (event.type === 'mousedown' && box.contains(event.target)) return;
            box.remove();
            document.removeEventListener('mousedown', dismiss, true);
            document.removeEventListener('keydown', dismiss, true);
        };
        document.addEventListener('mousedown', dismiss, true);
        document.addEventListener('keydown', dismiss, true);
    }

    /* ---------- Changes ---------- */

    function fail(error) { Lx.toast(error.message, 'danger'); }
    function tell(action, payload) { Lx.collab.workspaceEvent(action, payload); }

    function createFile(parent, suggested) {
        var name = window.prompt(psT('File name'), suggested || 'chapter.tex');
        if (!name || !name.trim()) return Promise.resolve();
        return Lx.post('api/create-workspace-file.php', { parent: parent, file_name: name.trim() }).then(function (body) {
            tell('created', { path: body.path, entry_type: 'file' });
            return load().then(function () { Lx.tabs.open(body.path, { focus: true }); });
        }).catch(fail);
    }

    function createFolder(parent) {
        var name = window.prompt(psT('Folder name'));
        if (!name || !name.trim()) return;
        Lx.post('api/create-folder.php', { parent: parent, folder_name: name.trim() }).then(function (body) {
            openFolders[body.path] = true;
            Lx.store.set(folderKey, openFolders);
            selectedFolder = body.path;
            tell('created', { path: body.path, entry_type: 'folder' });
            load();
        }).catch(fail);
    }

    /** Rename and move keep open tabs, live edits and the main file attached. */
    function relocate(from, request, action, type) {
        var held = [];
        return Lx.collab.hold(from).then(function (sessions) {
            held = sessions;
            return request();
        }).then(function (to) {
            Lx.collab.remap(from, to);
            Lx.tabs.remap(from, to);
            Lx.project.rootFile = Lx.moved(Lx.project.rootFile, from, to);
            openFolders[to] = openFolders[from];
            if (Lx.within(selectedFolder, from)) selectedFolder = Lx.moved(selectedFolder, from, to);
            tell(action, { old_path: from, path: to, entry_type: type });
            Lx.collab.release(held);
            Lx.emit('root', Lx.project.rootFile);
            return load();
        }).catch(function (error) { Lx.collab.release(held); fail(error); });
    }

    function rename(node) {
        var name = window.prompt(psT('New name'), node.name);
        if (!name || !name.trim() || name.trim() === node.name) return;
        relocate(node.path, function () {
            return Lx.post('api/rename-workspace-entry.php', { path: node.path, new_name: name.trim() }).then(function (body) { return body.path; });
        }, 'renamed', node.type);
    }

    function move(source, folder) {
        relocate(source, function () {
            return Lx.post('api/move-workspace-entry.php', { source: source, destination: folder }).then(function (body) { return body.new_path || source; });
        }, 'moved', null);
    }

    function remove(node) {
        var main = Lx.within(Lx.project.rootFile, node.path);
        var question = 'Delete ' + (node.type === 'folder' ? 'the folder ' + node.path + ' and everything in it' : node.path) + '?' + (main ? ' It holds the main file, so compiling will stop until you pick another.' : '');
        if (!window.confirm(question)) return;
        var held = [];
        Lx.collab.hold(node.path).then(function (sessions) {
            held = sessions;
            return Lx.post('api/remove-workspace-entry.php', { path: node.path });
        }).then(function () {
            Lx.tabs.removed(node.path);
            Lx.collab.removed(node.path);
            if (Lx.within(selectedFolder, node.path)) selectedFolder = '';
            tell('removed', { path: node.path, entry_type: node.type });
            load();
        }).catch(fail).finally(function () { Lx.collab.release(held); });
    }

    function setRoot(path) {
        Lx.post('api/set-root-file.php', { path: path }).then(function () {
            Lx.project.rootFile = path;
            tell('root', { path: path });
            Lx.emit('root', path);
            render();
            Lx.toast(psT('{name} is now the main file.', { name: Lx.name(path) }), 'success');
        }).catch(fail);
    }

    var picker = document.getElementById('workspaceFileInput');
    var pickTarget = '';
    function pickFiles(folder) { pickTarget = folder || ''; picker.click(); }
    picker.addEventListener('change', function () { if (picker.files.length) upload(picker.files, pickTarget); picker.value = ''; });

    function upload(files, folder) {
        var list = Array.prototype.slice.call(files);
        return Promise.all(list.map(function (file) {
            var form = new FormData();
            form.append('document_id', Lx.project.id);
            form.append('target_dir', folder || '');
            form.append('file', file);
            return fetch('api/upload-file.php', { method: 'POST', body: form }).then(function (response) { return response.json(); });
        })).then(function (results) {
            var failed = results.filter(function (result) { return result.status !== 'success'; })[0];
            results.forEach(function (result) { if (result.file_name) tell('created', { path: result.file_name, entry_type: 'file' }); });
            if (failed) throw new Error(failed.error || failed.message || psT('Upload failed.'));
            Lx.toast(list.length === 1 ? psT('Uploaded {name}.', { name: list[0].name }) : psT('{n} files uploaded.', { n: list.length }), 'success');
            return load();
        }).catch(function (error) { fail(error); load(); });
    }

    /* ---------- Changes by others ---------- */

    Lx.on('workspace', function (event) {
        var who = event.user_name || psT('Someone');
        if ((event.action === 'renamed' || event.action === 'moved') && event.old_path && event.path) {
            Lx.collab.remap(event.old_path, event.path);
            Lx.tabs.remap(event.old_path, event.path);
            Lx.project.rootFile = Lx.moved(Lx.project.rootFile, event.old_path, event.path);
            Lx.emit('root', Lx.project.rootFile);
        } else if (event.action === 'removed' && event.path) {
            if (Lx.tabs.list().some(function (path) { return Lx.within(path, event.path); })) Lx.toast(psT('{name} deleted {path}.', { name: who, path: event.path }), 'warning');
            Lx.tabs.removed(event.path);
            Lx.collab.removed(event.path);
        } else if (event.action === 'root' && event.path) {
            Lx.project.rootFile = event.path;
            Lx.emit('root', event.path);
        } else if (event.path) {
            Lx.tabs.refresh(event.path);
        }
        load();
    });
    Lx.on('presence', render);
    Lx.on('active', render);

    /* ---------- Dropping files from the computer ---------- */

    var dragDepth = 0;
    window.addEventListener('dragenter', function (event) {
        if (dragging || !event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types, psT('Files')) === -1) return;
        dragDepth++;
        host.classList.add('drag-active');
    });
    window.addEventListener('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; host.classList.remove('drag-active', 'dragover'); } });
    window.addEventListener('drop', function () { dragDepth = 0; host.classList.remove('drag-active', 'dragover'); });
    host.addEventListener('dragover', function (event) { event.preventDefault(); if (!dragging) host.classList.add('dragover'); });
    host.addEventListener('drop', function (event) {
        event.preventDefault();
        host.classList.remove('dragover', 'drag-active');
        if (dragging) { var source = event.dataTransfer.getData('text/ws-path'); if (source) move(source, ''); return; }
        if (event.dataTransfer.files.length) upload(event.dataTransfer.files, selectedFolder);
    });
    filter.addEventListener('input', render);

    Lx.tree = {
        load: load, render: render,
        exists: function (path) { return paths.has(path); },
        files: function () {
            var list = [];
            (function walk(nodes) { nodes.forEach(function (node) { if (node.type === 'file') list.push(node.path); else walk(node.children || []); }); })(data);
            return list;
        },
        folder: function () { return selectedFolder; },
        createFile: createFile, createFolder: createFolder, pickFiles: pickFiles, upload: upload, setRoot: setRoot
    };
})(window.Lx);
