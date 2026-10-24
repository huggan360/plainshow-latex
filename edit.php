<?php
/*
 * The editor for one project. The page is only the frame; js/editor/*.js does
 * the work (see js/editor/app.js for the map).
 */
require_once __DIR__ . '/lib/plainshow.php';
$identity = latexRequireUser();
require_once __DIR__ . '/lib/account.php';
require_once __DIR__ . '/lib/view.php';

$document_id = $_GET['document_id'] ?? null;
if (!$document_id || !ctype_digit((string) $document_id)) {
    http_response_code(400);
    die('Project ID is required.');
}
$document_id = (int) $document_id;
if (!latexCanAccess($document_id, (int) $user_id, true)) {
    http_response_code(403);
    die('You do not have access to this project.');
}

$stmt = $conn->prepare('SELECT title, user_id, root_file FROM documents WHERE document_id = ?');
$stmt->bind_param('i', $document_id);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc() ?: ['title' => 'Untitled', 'user_id' => 0, 'root_file' => 'main.tex'];
$stmt->close();
$isOwner = (int) $project['user_id'] === (int) $user_id;
$canShare = ($isOwner && latexCan('latex.project.create')) || latexCan('latex.project.moderate');
$pdf = "temp/document_$document_id/document_$document_id.pdf";
// Profile pictures for the people shown as online in the top right.
$pictures = [];
foreach ($conn->query("SELECT user_id, image_source FROM `user` WHERE image_source <> ''")->fetch_all(MYSQLI_ASSOC) as $row) {
    $pictures[(int) $row['user_id']] = latex_picture_url($row['image_source']);
}
$hasPdf = is_file(__DIR__ . '/' . $pdf);

$cm = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13';
ob_start(); ?>
    <link rel="stylesheet" href="<?= $cm ?>/codemirror.min.css">
    <link rel="stylesheet" href="<?= $cm ?>/addon/dialog/dialog.min.css">
    <link rel="stylesheet" href="<?= $cm ?>/addon/hint/show-hint.min.css">
    <script src="<?= $cm ?>/codemirror.min.js"></script>
    <script src="<?= $cm ?>/mode/stex/stex.min.js"></script>
    <script src="<?= $cm ?>/mode/python/python.min.js"></script>
    <script src="<?= $cm ?>/mode/javascript/javascript.min.js"></script>
    <script src="<?= $cm ?>/mode/xml/xml.min.js"></script>
    <script src="<?= $cm ?>/mode/css/css.min.js"></script>
    <script src="<?= $cm ?>/mode/markdown/markdown.min.js"></script>
    <script src="<?= $cm ?>/mode/yaml/yaml.min.js"></script>
    <script src="<?= $cm ?>/addon/search/searchcursor.min.js"></script>
    <script src="<?= $cm ?>/addon/dialog/dialog.min.js"></script>
    <script src="<?= $cm ?>/addon/search/search.min.js"></script>
    <script src="<?= $cm ?>/addon/hint/show-hint.min.js"></script>
    <script src="<?= $cm ?>/addon/hint/anyword-hint.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/typo-js@1.2.2/typo.min.js"></script>
<?php latex_page_start((string) $project['title'], 'editor', (string) ob_get_clean()); ?>
<div class="lx-ide" id="lxIde" data-layout="split">
  <header class="lx-menubar">
    <a class="lx-menubar__home" href="/" title="<?= ps_h('All projects') ?>"><img src="/ui/brand/plainshow-icon.webp?v=<?= e(latex_ui_version()) ?>" alt="<?= ps_h('Projects') ?>"></a>
    <nav class="lx-menus" aria-label="<?= ps_h('Menu') ?>">
      <div class="lx-menu"><button type="button" data-menu><?= ps_h('File') ?></button>
        <div class="lx-menu__panel" role="menu" hidden>
          <button class="lx-menu__item" data-act="add-files"><i class="bx bx-file-blank"></i><?= ps_h('New file or upload') ?></button>
          <button class="lx-menu__item" data-act="new-folder"><i class="bx bx-folder-plus"></i><?= ps_h('New folder') ?></button>
          <div class="lx-menu__sep"></div>
          <button class="lx-menu__item" data-act="download-pdf"><i class="bx bx-download"></i><?= ps_h('Download PDF') ?></button>
          <button class="lx-menu__item" data-act="download-source"><i class="bx bx-code-alt"></i><?= ps_h('Download this file') ?></button>
          <button class="lx-menu__item" data-act="recovery"><i class="bx bx-life-buoy"></i><?= ps_h('Recovery copy') ?></button>
          <button class="lx-menu__item" data-act="reload"><i class="bx bx-revision"></i><?= ps_h('Reload file from server') ?></button>
          <button class="lx-menu__item" data-act="close-tab"><i class="bx bx-x"></i><?= ps_h('Close tab') ?><kbd><?= ps_h('Alt W') ?></kbd></button>
          <div class="lx-menu__sep"></div>
          <a class="lx-menu__item" href="/"><i class="bx bx-left-arrow-alt"></i><?= ps_h('All projects') ?></a>
        </div>
      </div>
      <div class="lx-menu"><button type="button" data-menu><?= ps_h('Edit') ?></button>
        <div class="lx-menu__panel" role="menu" hidden>
          <button class="lx-menu__item" data-act="undo"><i class="bx bx-undo"></i><?= ps_h('Undo') ?><kbd><?= ps_h('Ctrl Z') ?></kbd></button>
          <button class="lx-menu__item" data-act="redo"><i class="bx bx-redo"></i><?= ps_h('Redo') ?><kbd><?= ps_h('Ctrl Y') ?></kbd></button>
          <div class="lx-menu__sep"></div>
          <button class="lx-menu__item" data-act="find"><i class="bx bx-search"></i><?= ps_h('Find') ?><kbd><?= ps_h('Ctrl F') ?></kbd></button>
          <button class="lx-menu__item" data-act="replace"><i class="bx bx-transfer"></i><?= ps_h('Find and replace') ?><kbd><?= ps_h('Ctrl H') ?></kbd></button>
          <div class="lx-menu__sep"></div>
          <button class="lx-menu__item" data-act="select-all"><i class="bx bx-select-multiple"></i><?= ps_h('Select all') ?><kbd><?= ps_h('Ctrl A') ?></kbd></button>
          <button class="lx-menu__item" data-act="comment"><i class="bx bx-hash"></i><?= ps_h('Comment lines') ?><kbd><?= ps_h('Ctrl /') ?></kbd></button>
        </div>
      </div>
      <div class="lx-menu"><button type="button" data-menu><?= ps_h('Insert') ?></button>
        <div class="lx-menu__panel" role="menu" hidden>
          <button class="lx-menu__item" data-insert="section"><i class="bx bx-heading"></i><?= ps_h('Section') ?></button>
          <button class="lx-menu__item" data-insert="subsection"><i class="bx bx-heading"></i><?= ps_h('Subsection') ?></button>
          <div class="lx-menu__sep"></div>
          <button class="lx-menu__item" data-insert="inline-math"><i class="bx bx-math"></i><?= ps_h('Inline math') ?></button>
          <button class="lx-menu__item" data-insert="equation"><i class="bx bx-math"></i><?= ps_h('Equation') ?></button>
          <button class="lx-menu__item" data-insert="itemize"><i class="bx bx-list-ul"></i><?= ps_h('Bulleted list') ?></button>
          <button class="lx-menu__item" data-insert="enumerate"><i class="bx bx-list-ol"></i><?= ps_h('Numbered list') ?></button>
          <button class="lx-menu__item" data-insert="table"><i class="bx bx-table"></i><?= ps_h('Table') ?></button>
          <button class="lx-menu__item" data-insert="figure"><i class="bx bx-image"></i><?= ps_h('Figure') ?></button>
          <button class="lx-menu__item" data-insert="link"><i class="bx bx-link"></i><?= ps_h('Link') ?></button>
          <button class="lx-menu__item" data-insert="cite"><i class="bx bx-book-bookmark"></i><?= ps_h('Citation') ?></button>
        </div>
      </div>
      <div class="lx-menu"><button type="button" data-menu><?= ps_h('View') ?></button>
        <div class="lx-menu__panel" role="menu" hidden>
          <button class="lx-menu__item" data-layout-set="split"><i class="bx bx-columns"></i><?= ps_h('Source and PDF') ?></button>
          <button class="lx-menu__item" data-layout-set="source"><i class="bx bx-code-alt"></i><?= ps_h('Source only') ?></button>
          <button class="lx-menu__item" data-layout-set="pdf"><i class="bx bx-file"></i><?= ps_h('PDF only') ?></button>
          <div class="lx-menu__sep"></div>
          <button class="lx-menu__item" data-act="toggle-panel"><i class="bx bx-sidebar"></i><?= ps_h('File panel') ?></button>
          <button class="lx-menu__item" data-act="wrap"><i class="bx bx-paragraph"></i><?= ps_h('Wrap long lines') ?><kbd><?= ps_h('Alt Z') ?></kbd></button>
          <div class="lx-menu__sep"></div>
          <button class="lx-menu__item" data-ps-theme-toggle><i class="bx bx-adjust"></i><span data-ps-theme-label><?= ps_h('Light theme') ?></span></button>
        </div>
      </div>
      <div class="lx-menu"><button type="button" data-menu><?= ps_h('Format') ?></button>
        <div class="lx-menu__panel" role="menu" hidden>
          <button class="lx-menu__item" data-wrap="textbf"><i class="bx bx-bold"></i><?= ps_h('Bold') ?><kbd><?= ps_h('Ctrl B') ?></kbd></button>
          <button class="lx-menu__item" data-wrap="textit"><i class="bx bx-italic"></i><?= ps_h('Italic') ?><kbd><?= ps_h('Ctrl I') ?></kbd></button>
          <button class="lx-menu__item" data-wrap="underline"><i class="bx bx-underline"></i><?= ps_h('Underline') ?></button>
          <button class="lx-menu__item" data-wrap="texttt"><i class="bx bx-code"></i><?= ps_h('Monospace') ?></button>
          <button class="lx-menu__item" data-wrap="emph"><i class="bx bx-highlight"></i><?= ps_h('Emphasis') ?></button>
        </div>
      </div>
      <div class="lx-menu"><button type="button" data-menu><?= ps_h('Help') ?></button>
        <div class="lx-menu__panel" role="menu" hidden>
          <button class="lx-menu__item" data-act="shortcuts"><i class="bx bx-command"></i><?= ps_h('Keyboard shortcuts') ?></button>
          <a class="lx-menu__item" href="https://www.overleaf.com/learn" target="_blank" rel="noopener"><i class="bx bx-book-open"></i><?= ps_h('LaTeX guides') ?></a>
        </div>
      </div>
    </nav>
    <div class="lx-menubar__title"><button type="button" data-act="<?= $canShare ? 'share' : 'noop' ?>" title="<?= $canShare ? ps_t('Rename and share') : '' ?>"><span data-doc-title><?= e($project['title']) ?></span><?php if ($canShare): ?><i class="bx bx-chevron-down"></i><?php endif; ?></button></div>
    <div class="lx-menubar__right">
      <div id="onlineUsersContainer"><div id="onlineUsers"></div></div>
      <div class="lx-menu"><button type="button" class="ps-btn ps-btn--ghost ps-btn--sm" data-menu><i class="bx bx-layout"></i><span><?= ps_h('Layout') ?></span></button>
        <div class="lx-menu__panel lx-menu__panel--right" role="menu" hidden>
          <button class="lx-menu__item" data-layout-set="split"><i class="bx bx-columns"></i><?= ps_h('Source and PDF') ?></button>
          <button class="lx-menu__item" data-layout-set="source"><i class="bx bx-code-alt"></i><?= ps_h('Source only') ?></button>
          <button class="lx-menu__item" data-layout-set="pdf"><i class="bx bx-file"></i><?= ps_h('PDF only') ?></button>
        </div>
      </div>
      <?php if ($canShare): ?><button type="button" class="ps-btn ps-btn--primary ps-btn--sm" data-act="share"><i class="bx bx-user-plus"></i><span><?= ps_h('Share') ?></span></button><?php endif; ?>
    </div>
  </header>

  <div class="latex-container">
    <nav class="lx-rail" aria-label="<?= ps_h('Panels') ?>">
      <button type="button" class="is-active" data-act="toggle-panel" title="<?= ps_h('File tree') ?>"><i class="bx bx-folder"></i></button>
      <button type="button" data-act="find" title="<?= ps_h('Search in file') ?>"><i class="bx bx-search"></i></button>
      <button type="button" data-act="outline" title="<?= ps_h('File outline') ?>"><i class="bx bx-list-ul"></i></button>
      <button type="button" data-act="add-files" title="<?= ps_h('Add files') ?>"><i class="bx bx-plus-circle"></i></button>
      <span class="lx-rail__spacer"></span>
      <button type="button" data-act="shortcuts" title="<?= ps_h('Keyboard shortcuts') ?>"><i class="bx bx-help-circle"></i></button>
      <button type="button" data-ps-theme-toggle title="<?= ps_h('Theme') ?>"><i class="bx bx-adjust"></i></button>
    </nav>

    <aside id="sidebarEl" class="sidebar" aria-label="<?= ps_h('Files') ?>">
      <div class="sidebar-resize-handle" id="sidebarResizeHandle"></div>
      <div class="sidebar-full-content">
        <section class="sidebar-section workspace-section">
          <div class="sidebar-section-header">
            <span class="sidebar-section-title"><?= ps_h('File tree') ?></span>
            <div class="workspace-toolbar">
              <button type="button" title="<?= ps_h('New file') ?>" aria-label="<?= ps_h('New file') ?>" data-act="new-file"><i class="bx bx-file-blank"></i></button>
              <button type="button" title="<?= ps_h('New folder') ?>" aria-label="<?= ps_h('New folder') ?>" data-act="new-folder"><i class="bx bx-folder-plus"></i></button>
              <button type="button" title="<?= ps_h('Upload') ?>" aria-label="<?= ps_h('Upload') ?>" data-act="upload"><i class="bx bx-upload"></i></button>
            </div>
          </div>
          <input id="workspaceFileInput" type="file" multiple hidden>
          <input id="fileFilter" class="file-filter" placeholder="<?= ps_h('Find a file') ?>" aria-label="<?= ps_h('Find a file') ?>" autocomplete="off">
          <div id="workspaceTree" class="workspace-tree dropzone"></div>
        </section>
        <section class="sidebar-section lx-outline-section" id="lxOutlineSection">
          <div class="sidebar-section-header"><span class="sidebar-section-title"><?= ps_h('File outline') ?></span></div>
          <ul class="lx-outline" id="lxOutline"></ul>
        </section>
      </div>
    </aside>

    <div class="editor">
      <div class="lx-tabs" id="lxTabs" role="tablist" aria-label="<?= ps_h('Open files') ?>"></div>
      <div class="editor-header">
        <button type="button" class="lx-tool" data-act="undo" title="<?= ps_h('Undo') ?>"><i class="bx bx-undo"></i></button>
        <button type="button" class="lx-tool" data-act="redo" title="<?= ps_h('Redo') ?>"><i class="bx bx-redo"></i></button>
        <span class="lx-tool-sep"></span>
        <button type="button" class="lx-tool" data-insert="section" title="<?= ps_h('Section') ?>"><i class="bx bx-heading"></i></button>
        <button type="button" class="lx-tool" data-wrap="textbf" title="<?= ps_h('Bold') ?>"><i class="bx bx-bold"></i></button>
        <button type="button" class="lx-tool" data-wrap="textit" title="<?= ps_h('Italic') ?>"><i class="bx bx-italic"></i></button>
        <span class="lx-tool-sep"></span>
        <button type="button" class="lx-tool" data-insert="inline-math" title="<?= ps_h('Math') ?>"><i class="bx bx-math"></i></button>
        <button type="button" class="lx-tool" data-insert="link" title="<?= ps_h('Link') ?>"><i class="bx bx-link"></i></button>
        <button type="button" class="lx-tool" data-insert="cite" title="<?= ps_h('Citation') ?>"><i class="bx bx-book-bookmark"></i></button>
        <button type="button" class="lx-tool" data-insert="figure" title="<?= ps_h('Figure') ?>"><i class="bx bx-image"></i></button>
        <button type="button" class="lx-tool" data-insert="table" title="<?= ps_h('Table') ?>"><i class="bx bx-table"></i></button>
        <button type="button" class="lx-tool" data-insert="itemize" title="<?= ps_h('Bulleted list') ?>"><i class="bx bx-list-ul"></i></button>
        <button type="button" class="lx-tool" data-insert="enumerate" title="<?= ps_h('Numbered list') ?>"><i class="bx bx-list-ol"></i></button>
        <div class="editor-header-actions">
          <span class="spellcheck-language-picker" title="<?= ps_h('Spell check') ?>"><i class="bx bx-spellcheck"></i>
            <select id="spellcheckLanguagePrimary" class="spellcheck-language-select" aria-label="<?= ps_h('Spell check language') ?>"><option value="en_US"><?= ps_h('English') ?></option><option value="sv_SE"><?= ps_h('Svenska') ?></option></select>
          </span>
          <button type="button" class="lx-tool" data-act="find" title="<?= ps_h('Search') ?>"><i class="bx bx-search"></i></button>
        </div>
      </div>
      <div class="lx-file-notice" id="lxFileNotice" role="status" hidden></div>
      <div class="lx-source" id="lxSource"></div>
      <div class="lx-file-preview" id="lxFilePreview" hidden></div>
      <div class="lx-empty" id="lxEmpty" hidden>
        <i class="bx bx-folder-open"></i>
        <strong><?= ps_h('No file open') ?></strong>
        <span><?= ps_h('Pick a file in the file tree, or make a new one.') ?></span>
        <button type="button" class="ps-btn ps-btn--sm" data-act="add-files"><i class="bx bx-plus"></i><?= ps_h('New file or upload') ?></button>
      </div>
      <footer class="editor-status"><span id="saveStatus" role="status"><?= ps_h('Connecting') ?></span><span id="cursorStatus"></span><button type="button" data-act="recovery"><?= ps_h('Recovery copy') ?></button></footer>
    </div>

    <div class="divider" tabindex="0" role="separator" aria-label="<?= ps_h('Resize source and preview') ?>" aria-orientation="vertical"></div>

    <div class="preview">
      <div class="preview-toolbar">
        <button type="button" class="ps-btn ps-btn--primary ps-btn--sm" id="compileButton" title="<?= ps_h('Recompile (Ctrl Enter)') ?>"><i class="bx bx-refresh"></i><span><?= ps_h('Recompile') ?></span></button>
        <button type="button" class="ps-btn ps-btn--ghost ps-btn--sm lx-compile-issues" id="compileIssues" hidden><i class="bx bx-error-circle"></i><span><?= ps_h('Errors') ?></span></button>
        <span class="lx-preview-spacer"></span>
        <button type="button" class="ps-btn ps-btn--ghost ps-btn--sm ps-btn--icon" data-act="download-pdf" title="<?= ps_h('Download PDF') ?>"><i class="bx bx-download"></i></button>
        <button type="button" class="ps-btn ps-btn--ghost ps-btn--sm ps-btn--icon" data-act="open-pdf" title="<?= ps_h('Open PDF in a new tab') ?>"><i class="bx bx-link-external"></i></button>
      </div>
      <div id="previewEmpty" class="preview-empty"><span class="lx-paper"><img src="/ui/brand/plainshow-icon.webp?v=<?= e(latex_ui_version()) ?>" alt=""></span><span><?= ps_h('Your document preview') ?></span><small><?= ps_h('Recompile with Ctrl Enter') ?></small></div>
      <div id="loader" class="loader" style="display: none;"></div>
      <iframe id="preview" title="PDF preview" <?= $hasPdf ? 'src="' . e($pdf) . '?t=' . time() . '"' : '' ?> style="<?= $hasPdf ? 'display:block' : 'display:none' ?>"></iframe>
      <div id="errorContainer" class="error-container" style="display: none;"></div>
    </div>
  </div>
</div>

<!-- Add files -->
<div class="ps-modal-backdrop" id="lxAddFiles" hidden>
  <div class="ps-frame ps-modal lx-addfiles" role="dialog" aria-modal="true" aria-labelledby="lxAddFilesTitle">
    <div class="ps-frame__panel">
      <div class="ps-modal__head"><h2 class="ps-modal__title" id="lxAddFilesTitle"><?= ps_h('Add files') ?></h2><button type="button" class="ps-btn ps-btn--ghost ps-btn--icon" data-modal-close aria-label="<?= ps_h('Close') ?>"><i class="bx bx-x"></i></button></div>
      <div class="lx-addfiles__grid">
        <nav class="lx-addfiles__nav">
          <button type="button" class="is-active" data-add-tab="new"><i class="bx bx-file-blank"></i><?= ps_h('New file') ?></button>
          <button type="button" data-add-tab="upload"><i class="bx bx-upload"></i><?= ps_h('Upload') ?></button>
          <button type="button" data-add-tab="project"><i class="bx bx-folder-open"></i><?= ps_h('From another project') ?></button>
          <button type="button" data-add-tab="url"><i class="bx bx-globe"></i><?= ps_h('From external URL') ?></button>
        </nav>
        <div class="lx-addfiles__pane">
          <form data-add-pane="new">
            <label class="ps-field"><span class="ps-label"><?= ps_h('File name') ?></span><input class="ps-input" name="name" value="name.tex" autocomplete="off" required></label>
            <p class="ps-help"><?= ps_h('The file goes in') ?> <strong data-add-folder><?= ps_h('the project folder') ?></strong>.</p>
            <div class="ps-modal__foot lx-add-foot"><button type="button" class="ps-btn ps-btn--ghost" data-modal-close><?= ps_h('Cancel') ?></button><button type="submit" class="ps-btn ps-btn--primary"><?= ps_h('Create') ?></button></div>
          </form>
          <div data-add-pane="upload" hidden>
            <label class="lx-drop" data-add-drop><input type="file" multiple hidden data-add-input><i class="bx bx-cloud-upload"></i><strong><?= ps_h('Drag files here or click to choose') ?></strong><span><?= ps_h('Images, PDFs, .tex, .bib and other text files.') ?></span></label>
          </div>
          <form data-add-pane="project" hidden>
            <label class="ps-field"><span class="ps-label"><?= ps_h('Project') ?></span><select class="ps-select" name="source" data-add-projects><option value=""><?= ps_h('Choose a project') ?></option></select></label>
            <ul class="lx-filelist" data-add-files></ul>
            <div class="ps-modal__foot lx-add-foot"><button type="button" class="ps-btn ps-btn--ghost" data-modal-close><?= ps_h('Cancel') ?></button><button type="submit" class="ps-btn ps-btn--primary"><?= ps_h('Copy files') ?></button></div>
          </form>
          <form data-add-pane="url" hidden>
            <label class="ps-field"><span class="ps-label"><?= ps_h('URL to fetch the file from') ?></span><input class="ps-input" name="url" type="url" placeholder="https://" required></label>
            <label class="ps-field"><span class="ps-label"><?= ps_h('File name in this project') ?></span><input class="ps-input" name="name" placeholder="image.png" autocomplete="off"></label>
            <p class="ps-help"><?= ps_h('Up to 10 MB. Only public https addresses.') ?></p>
            <div class="ps-modal__foot lx-add-foot"><button type="button" class="ps-btn ps-btn--ghost" data-modal-close><?= ps_h('Cancel') ?></button><button type="submit" class="ps-btn ps-btn--primary"><?= ps_h('Fetch file') ?></button></div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Share and rename -->
<?php if ($canShare): ?><div class="ps-modal-backdrop" id="lxShare" hidden>
  <form class="ps-frame ps-modal ps-modal--sm" role="dialog" aria-modal="true" aria-labelledby="lxShareTitle" data-share-form>
    <div class="ps-frame__panel">
      <div class="ps-modal__head"><h2 class="ps-modal__title" id="lxShareTitle"><?= ps_h('Share project') ?></h2><button type="button" class="ps-btn ps-btn--ghost ps-btn--icon" data-modal-close aria-label="<?= ps_h('Close') ?>"><i class="bx bx-x"></i></button></div>
      <div class="ps-modal__body">
        <label class="ps-field"><span class="ps-label"><?= ps_h('Project name') ?></span><input class="ps-input" name="title" maxlength="255" required></label>
        <span class="ps-label"><?= ps_h('People with access') ?></span>
        <div class="ps-input-wrap lx-people-filter"><i class="bx bx-search" aria-hidden="true"></i><input class="ps-input" type="search" placeholder="<?= ps_h('Search people and groups') ?>" aria-label="<?= ps_h('Search people and groups') ?>" data-share-filter></div>
        <div class="lx-people" data-share-people><p class="ps-muted ps-small"><?= ps_h('Loading people...') ?></p></div>
      </div>
      <div class="ps-modal__foot"><button type="button" class="ps-btn ps-btn--ghost" data-modal-close><?= ps_h('Cancel') ?></button><button type="submit" class="ps-btn ps-btn--primary"><?= ps_h('Save') ?></button></div>
    </div>
  </form>
</div><?php endif; ?>

<!-- Keyboard shortcuts -->
<div class="ps-modal-backdrop" id="lxKeys" hidden>
  <div class="ps-frame ps-modal ps-modal--sm" role="dialog" aria-modal="true" aria-labelledby="lxKeysTitle">
    <div class="ps-frame__panel">
      <div class="ps-modal__head"><h2 class="ps-modal__title" id="lxKeysTitle"><?= ps_h('Keyboard shortcuts') ?></h2><button type="button" class="ps-btn ps-btn--ghost ps-btn--icon" data-modal-close aria-label="<?= ps_h('Close') ?>"><i class="bx bx-x"></i></button></div>
      <ul class="lx-keys ps-modal__body">
        <li><?= ps_h('Recompile') ?><kbd class="ps-kbd"><?= ps_h('Ctrl Enter') ?></kbd></li>
        <li><?= ps_h('Recompile') ?><kbd class="ps-kbd"><?= ps_h('Ctrl S') ?></kbd></li>
        <li><?= ps_h('Close tab') ?><kbd class="ps-kbd"><?= ps_h('Alt W') ?></kbd></li>
        <li><?= ps_h('Next and previous tab') ?><kbd class="ps-kbd"><?= ps_h('Alt PgDn, Alt PgUp') ?></kbd></li>
        <li><?= ps_h('Find') ?><kbd class="ps-kbd"><?= ps_h('Ctrl F') ?></kbd></li>
        <li><?= ps_h('Find and replace') ?><kbd class="ps-kbd"><?= ps_h('Ctrl H') ?></kbd></li>
        <li><?= ps_h('Bold') ?><kbd class="ps-kbd"><?= ps_h('Ctrl B') ?></kbd></li>
        <li><?= ps_h('Italic') ?><kbd class="ps-kbd"><?= ps_h('Ctrl I') ?></kbd></li>
        <li><?= ps_h('Comment lines') ?><kbd class="ps-kbd"><?= ps_h('Ctrl /') ?></kbd></li>
        <li><?= ps_h('Autocomplete') ?><kbd class="ps-kbd"><?= ps_h('Ctrl Space') ?></kbd></li>
        <li><?= ps_h('Wrap long lines') ?><kbd class="ps-kbd"><?= ps_h('Alt Z') ?></kbd></li>
      </ul>
    </div>
  </div>
</div>

<script type="application/json" id="lxProject"><?= json_encode([
    'id' => $document_id,
    'title' => $project['title'],
    'owner' => $canShare,
    'userId' => (int) $user_id,
    'userName' => $user_name,
    'rootFile' => $project['root_file'],
    'pictures' => (object) $pictures,
    'ticket' => latexWsTicket($document_id, (int) $user_id, (string) $user_name),
    'socketPath' => '/ws',
], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php foreach (['js/ot.js', 'js/editor/app.js', 'js/editor/collab.js', 'js/editor/tabs.js', 'js/editor/tree.js', 'js/editor/outline.js', 'js/editor/pdf.js', 'js/editor/compile.js', 'js/editor/spellcheck.js', 'js/editor/shell.js'] as $script): ?>
<script src="<?= e(latex_asset($script)) ?>"></script>
<?php endforeach; ?>
<script>Lx.start();</script>
</body>
</html>
