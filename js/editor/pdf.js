/* The PDF pane: the last compiled PDF, with download and open in a new tab. */
(function (Lx) {
    'use strict';

    var frame = document.getElementById('preview');
    var emptyState = document.getElementById('previewEmpty');
    var errors = document.getElementById('errorContainer');
    var loader = document.getElementById('loader');

    function sync() {
        emptyState.hidden = !!frame.getAttribute('src') || loader.style.display !== 'none' || errors.style.display !== 'none';
    }

    var file = null;

    /** Show a PDF; file is the .tex document it was made from. */
    function show(path, from) {
        file = from || null;
        errors.style.display = 'none';
        frame.src = path + '?t=' + Date.now();
        frame.style.display = 'block';
        sync();
    }

    /** Nothing to show yet (another document, not compiled). */
    function clear() {
        frame.removeAttribute('src');
        frame.style.display = 'none';
        errors.style.display = 'none';
        sync();
    }

    function current() { return frame.getAttribute('src') ? frame.getAttribute('src').split('?')[0] : null; }

    function download() {
        var src = current();
        if (!src) { Lx.toast(psT('Compile first to make a PDF.'), 'warning'); return; }
        var link = document.createElement('a');
        link.href = src;
        var name = file && file !== Lx.project.rootFile ? Lx.name(file).replace(/\.tex$/i, '') : Lx.project.title;
        link.download = (name || 'document').replace(/[^\w\- ]+/g, '').trim() + '.pdf';
        link.click();
    }

    function openTab() {
        if (!current()) { Lx.toast(psT('Compile first to make a PDF.'), 'warning'); return; }
        window.open(frame.src, '_blank', 'noopener');
    }

    /** While errors are shown they take the pane; the last good PDF is a click away. */
    function hide() { if (frame.getAttribute('src')) frame.style.display = 'none'; }
    function showLast() { errors.style.display = 'none'; if (frame.getAttribute('src')) frame.style.display = 'block'; sync(); }

    Lx.pdf = { show: show, clear: clear, hide: hide, showLast: showLast, current: current, sync: sync, download: download, openTab: openTab };
    sync();
})(window.Lx);
