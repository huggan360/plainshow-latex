<?php
require_once __DIR__ . '/lib/plainshow.php';
$identity = latexRequireUser();
require_once __DIR__ . '/lib/account.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/api/_workspace.php';

$documentId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT);
if (!$documentId || !latexCanAccess($documentId, (int) $user_id)) {
    latexNotFound();
}
$statement = $conn->prepare('SELECT title FROM documents WHERE document_id = ?');
$statement->bind_param('i', $documentId);
$statement->execute();
$title = $statement->get_result()->fetch_column();
if (!is_string($title)) {
    latexNotFound();
}
$tree = workspaceBuildTree($documentId);
$fileUrl = static function (string $path) use ($documentId): string {
    return '/temp/document_' . $documentId . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
};
$renderTree = static function (array $items) use (&$renderTree, $fileUrl): void {
    echo '<ul class="ps-chain">';
    foreach ($items as $item) {
        if ($item['type'] === 'folder') {
            echo '<li><details><summary>', e($item['name']), '</summary>';
            $renderTree($item['children']);
            echo '</details></li>';
        } else {
            echo '<li><a class="ps-row" href="', e($fileUrl($item['path'])), '" target="_blank" rel="noopener"><span class="ps-row__icon"><i class="bx bx-file"></i></span><span class="ps-row__body"><span class="ps-row__title">', e($item['name']), '</span></span></a></li>';
        }
    }
    echo '</ul>';
};

latex_page_start($title, 'projects');
?>
<div class="ps-shell lx-shell">
    <main class="ps-main">
        <header class="ps-topbar"><a class="ps-btn ps-btn--ghost" href="/"><i class="bx bx-arrow-back"></i> <?= ps_h('Projects') ?></a><span class="ps-topbar__spacer"></span><?= latex_switcher() ?></header>
        <div class="ps-content ps-content--wide">
            <div class="ps-page-head"><div><h1 class="ps-page-head__title"><?= e($title) ?></h1><p class="ps-page-head__text"><?= ps_h('You can view this project. Ask the group owner for edit access to collaborate.') ?></p></div></div>
            <section class="ps-frame"><div class="ps-frame__panel"><?php $renderTree($tree); ?></div></section>
        </div>
    </main>
</div>
<?php latex_page_end(); ?>
