/* Projects page: filter, search, select, create, share, rename and delete. */
(function () {
    'use strict';

    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-project]'));
    var filter = 'all';
    var titles = { all: psT('All projects'), mine: psT('Your projects'), shared: psT('Shared with you') };

    function toast(message, kind) { if (window.PlainShow && PlainShow.toast) PlainShow.toast(message, kind); }
    function api(url, options) {
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok || body.status !== 'success') throw new Error(body.message || psT('Something went wrong. Try again.'));
                return body;
            });
        });
    }

    /* ---------- Filter and search ---------- */
    function apply() {
        var query = (document.querySelector('[data-search]') || { value: '' }).value.trim().toLowerCase();
        var shown = 0;
        rows.forEach(function (row) {
            var mine = row.dataset.mine === '1';
            var visible = (filter === 'all' || (filter === 'mine') === mine) && (!query || row.dataset.title.indexOf(query) !== -1);
            row.hidden = !visible;
            if (visible) shown++;
        });
        var none = document.querySelector('[data-no-match]');
        if (none) none.hidden = shown > 0 || !rows.length;
        document.querySelector('[data-filter-title]').textContent = titles[filter];
    }
    document.querySelectorAll('[data-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            filter = button.dataset.filter;
            document.querySelectorAll('[data-filter]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
            apply();
        });
    });
    var search = document.querySelector('[data-search]');
    if (search) search.addEventListener('input', apply);

    /* ---------- Selection ---------- */
    var bulk = document.querySelector('[data-bulk]');
    function selected() { return rows.filter(function (row) { var box = row.querySelector('[data-select]'); return box && box.checked && !row.hidden; }); }
    function refreshBulk() {
        var chosen = selected();
        bulk.hidden = chosen.length === 0;
        document.querySelector('[data-bulk-count]').textContent = (chosen.length === 1 ? psT('1 project selected') : psT('{n} projects selected', { n: chosen.length }));
        rows.forEach(function (row) { var box = row.querySelector('[data-select]'); row.classList.toggle('is-selected', !!(box && box.checked)); });
    }
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-select-all]')) {
            rows.forEach(function (row) { var box = row.querySelector('[data-select]'); if (box && !row.hidden) box.checked = event.target.checked; });
        }
        if (event.target.matches('[data-select], [data-select-all]')) refreshBulk();
    });

    function remove(ids) {
        var body = function (id) { var data = new FormData(); data.append('document_id', id); return data; };
        return Promise.all(ids.map(function (id) { return api('api/delete-document.php', { method: 'POST', body: body(id) }); }));
    }
    var bulkDelete = document.querySelector('[data-bulk-delete]');
    if (bulkDelete) bulkDelete.addEventListener('click', function () {
        var chosen = selected();
        if (!chosen.length || !window.confirm((chosen.length === 1 ? psT('Delete 1 project with all files? This cannot be undone.') : psT('Delete {n} projects with all files? This cannot be undone.', { n: chosen.length })))) return;
        remove(chosen.map(function (row) { return row.dataset.project; })).then(function () { location.reload(); }).catch(function (error) { toast(error.message, 'error'); });
    });

    /* ---------- Modal ---------- */
    var modal = document.getElementById('project-new');
    var form = document.querySelector('[data-project-form]');
    if (!modal || !form) {
        document.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            var del = target && target.closest('[data-delete]');
            if (!del) return;
            var title = del.closest('tr').querySelector('.lx-title span').textContent;
            if (!window.confirm(psT('Delete "{title}" with all its files? This cannot be undone.', { title: title }))) return;
            remove([del.dataset.delete]).then(function () { location.reload(); }).catch(function (error) { toast(error.message, 'error'); });
        });
        return;
    }
    var people = form.querySelector('[data-people]');
    var peopleLoaded = null;

    function open() { modal.hidden = false; window.setTimeout(function () { form.title.focus(); }, 30); }
    function close() { modal.hidden = true; }
    modal.addEventListener('click', function (event) { if (event.target === modal || event.target.closest('[data-modal-close]')) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) close(); });

    function loadPeople() {
        return peopleLoaded = peopleLoaded || api('api/get-users.php').then(function (body) {
            people.innerHTML = '';
            function heading(text) { var node = document.createElement('div'); node.className = 'lx-picker-heading'; node.textContent = text; people.appendChild(node); }
            function avatar(user) {
                var node = document.createElement('span'); node.className = 'ps-avatar ps-avatar--xs';
                var source = (user.image_source || '').trim();
                if (source && !/^https?:\/\//.test(source)) source = (window.PS_PICTURES || '/') + source.replace(/^\//, '');
                if (source) { var image = document.createElement('img'); image.className = 'ps-avatar__img'; image.src = source; image.alt = ''; node.appendChild(image); }
                else { var letters = document.createElement('span'); letters.className = 'ps-avatar__initials'; letters.textContent = ((user.firstname || '?')[0] + (user.lastname || '')[0]).toUpperCase(); node.appendChild(letters); }
                return node;
            }
            if ((body.groups || []).length) heading('Groups');
            (body.groups || []).forEach(function (group) {
                var label = document.createElement('label');
                label.className = 'lx-person'; label.dataset.name = group.name.toLowerCase();
                label.innerHTML = '<input type="checkbox" data-group><span class="lx-group-avatar"><i class="bx bx-group"></i></span><span class="lx-person__text"><strong></strong></span><span class="ps-badge ps-badge--accent">' + psT('Group') + '</span>';
                label.querySelector('input').value = group.id; label.querySelector('strong').textContent = group.name; people.appendChild(label);
            });
            if (body.users.length) heading('Suggested people');
            body.users.forEach(function (user, index) {
                if (index === 3) heading('People');
                var label = document.createElement('label');
                label.className = 'lx-person';
                label.dataset.name = (user.firstname + ' ' + user.lastname + ' ' + (user.username || '')).toLowerCase();
                label.innerHTML = '<input type="checkbox" value=""><span class="lx-person__text"><strong></strong><small></small></span>';
                label.querySelector('input').value = user.user_id;
                label.insertBefore(avatar(user), label.querySelector('.lx-person__text'));
                label.querySelector('strong').textContent = (user.firstname + ' ' + user.lastname).trim();
                label.querySelector('small').textContent = user.username ? '@' + user.username : '';
                people.appendChild(label);
            });
            if (!body.users.length && !(body.groups || []).length) people.innerHTML = '<p class="ps-muted ps-small">' + psT('Nobody else to share with.') + '</p>';
        }).catch(function (error) { people.textContent = error.message; });
    }
    form.querySelector('[data-people-filter]').addEventListener('input', function (event) {
        var query = event.target.value.trim().toLowerCase();
        people.querySelectorAll('.lx-person').forEach(function (row) { row.hidden = query !== '' && row.dataset.name.indexOf(query) === -1; });
    });

    function openFor(id) {
        form.reset();
        form.document_id.value = id || '';
        document.getElementById('project-new-title').textContent = id ? psT('Share and rename') : psT('New project');
        form.querySelector('[data-project-submit]').textContent = id ? psT('Save') : psT('Create');
        open();
        loadPeople().then(function () {
            if (!id) return;
            return api('api/get-document-details.php?document_id=' + encodeURIComponent(id)).then(function (body) {
                form.title.value = body.document.title;
                var shared = (body.shared_users || []).map(String);
                var groups = (body.shared_groups || []).map(String);
                people.querySelectorAll('input').forEach(function (box) { box.checked = (box.dataset.group === undefined ? shared : groups).indexOf(box.value) !== -1; });
            });
        }).catch(function (error) { toast(error.message, 'error'); });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        if (target.closest('[data-modal-open="project-new"]')) { event.preventDefault(); openFor(null); return; }
        var share = target.closest('[data-share]');
        if (share) { openFor(share.dataset.share); return; }
        var del = target.closest('[data-delete]');
        if (del) {
            var title = del.closest('tr').querySelector('.lx-title span').textContent;
            if (!window.confirm(psT('Delete "{title}" with all its files? This cannot be undone.', { title: title }))) return;
            remove([del.dataset.delete]).then(function () { location.reload(); }).catch(function (error) { toast(error.message, 'error'); });
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var id = form.document_id.value;
        var shared = Array.prototype.map.call(people.querySelectorAll('input:checked:not([data-group])'), function (box) { return box.value; });
        var groups = Array.prototype.map.call(people.querySelectorAll('input[data-group]:checked'), function (box) { return box.value; });
        var title = form.title.value.trim() || psT('Untitled project');
        var submit = form.querySelector('[data-project-submit]');
        submit.disabled = true;
        api(id ? 'api/edit-document.php' : 'api/create-document.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(id ? { document_id: id, title: title, shared_users: shared, shared_groups: groups } : { title: title, shared_users: shared, shared_groups: groups })
        }).then(function (body) {
            if (id) location.reload(); else location.href = '/edit?document_id=' + body.documentId;
        }).catch(function (error) { submit.disabled = false; toast(error.message, 'error'); });
    });
})();
