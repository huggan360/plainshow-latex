<?php
// Projects: everything you own or that is shared with you, newest first.
require_once __DIR__ . '/lib/plainshow.php';
$identity = latexRequireUser();
require_once __DIR__ . '/lib/account.php';
require_once __DIR__ . '/lib/view.php';

$canCreate = latexCan('latex.project.create');
$canModerate = latexCan('latex.project.moderate');
$canViewShared = latexCan('latex.project.view') || $canModerate;
$groupSql = $canViewShared ? latexGroupProjectSql($user_id) : '';
$stmt = $conn->prepare('
    SELECT d.document_id, d.title, d.user_id, d.created_at, d.updated_at,
           u.firstname, u.lastname, u.username, u.image_source,
           d.updated_at AS edited_at, eu.firstname AS editor_first, eu.user_id AS editor_id,
           ((SELECT COUNT(*) FROM shared_documents s2 WHERE s2.document_id = d.document_id) +
            (SELECT COUNT(*) FROM latex_group_shares gs WHERE gs.document_id = d.document_id)) AS shares
    FROM documents d
    JOIN `user` u ON u.user_id = d.user_id
    LEFT JOIN `user` eu ON eu.user_id = d.last_editor_id
    WHERE d.user_id = ? OR (? = 1 AND EXISTS (SELECT 1 FROM shared_documents s WHERE s.document_id = d.document_id AND s.user_id = ?))
    ' . $groupSql . '
    ORDER BY d.updated_at DESC, d.document_id DESC');
$viewShared = $canViewShared ? 1 : 0;
$stmt->bind_param('iii', $user_id, $viewShared, $user_id);
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$owned = count(array_filter($projects, fn ($p) => (int) $p['user_id'] === $user_id));

latex_page_start('Projects', 'projects');
?>
<canvas class="ps-backdrop" aria-hidden="true"></canvas>
<div class="ps-shell lx-shell" data-ps-shell>
    <aside class="ps-sidebar" aria-label="<?= ps_h('Projects') ?>">
        <div class="ps-sidebar__brand">
            <a class="ps-brand" href="/"><img class="ps-brand__logo" src="/ui/brand/plainshow-logo.webp?v=<?= e(latex_ui_version()) ?>" alt="<?= ps_h('PlainShow') ?>" width="92" height="30"><span class="ps-brand__suffix"><?= ps_h('LaTeX') ?></span></a>
        </div>
        <?php if ($canCreate): ?><button type="button" class="ps-btn ps-btn--primary ps-btn--block lx-new" data-modal-open="project-new"><i class="bx bx-plus"></i><?= ps_h('New project') ?></button><?php endif; ?>
        <nav class="ps-nav lx-filters">
            <button type="button" class="ps-nav__item is-active" data-filter="all"><i class="bx bx-folder"></i><?= ps_h('All projects') ?><span class="ps-nav__count"><?= count($projects) ?></span></button>
            <button type="button" class="ps-nav__item" data-filter="mine"><i class="bx bx-user"></i><?= ps_h('Your projects') ?><span class="ps-nav__count"><?= $owned ?></span></button>
            <button type="button" class="ps-nav__item" data-filter="shared"><i class="bx bx-group"></i><?= ps_h('Shared with you') ?><span class="ps-nav__count"><?= count($projects) - $owned ?></span></button>
        </nav>
        <div class="ps-sidebar__foot">
            <a class="ps-nav__item" href="<?= e(ps_url('core')) ?>"><i class="bx bx-grid-alt"></i><?= ps_h('PlainShow') ?></a>
            <?= latex_me($identity) ?>
        </div>
    </aside>
    <div class="ps-main">
        <header class="ps-topbar lx-topbar">
            <button type="button" class="ps-btn ps-btn--ghost ps-btn--icon ps-menu-toggle" data-ps-menu-toggle aria-label="<?= ps_h('Open menu') ?>"><i class="bx bx-menu"></i></button>
            <span class="ps-topbar__spacer"></span>
            <?php if ($canCreate): ?><button type="button" class="ps-btn ps-btn--primary ps-btn--sm lx-new-mobile" data-modal-open="project-new"><i class="bx bx-plus"></i><?= ps_h('New') ?></button><?php endif; ?>
            <?= latex_switcher() ?>
        </header>
        <main class="ps-content ps-content--wide">
            <div class="ps-page-head ps-page-head--gradient">
                <div>
                    <h1 class="ps-page-head__title" data-filter-title><?= ps_h('All projects') ?></h1>
                    <p class="ps-page-head__text"><?= ps_h('Write and compile LaTeX together, live.') ?></p>
                </div>
            </div>
            <div class="lx-toolbar">
                <label class="lx-search"><i class="bx bx-search" aria-hidden="true"></i><input class="ps-input" type="search" placeholder="<?= ps_h('Search in all projects') ?>" aria-label="<?= ps_h('Search projects') ?>" data-search></label>
                <div class="lx-bulk" data-bulk hidden>
                    <span data-bulk-count></span>
                    <button type="button" class="ps-btn ps-btn--sm ps-btn--danger" data-bulk-delete><i class="bx bx-trash"></i><?= ps_h('Delete') ?></button>
                </div>
            </div>
            <section class="ps-frame ps-frame--flush lx-projects">
                <div class="ps-frame__panel">
                    <?php if ($projects): ?>
                    <div class="ps-table-wrap">
                        <table class="ps-table lx-table">
                            <thead><tr>
                                <th class="ps-col-check"><input type="checkbox" class="lx-check" aria-label="<?= ps_h('Select all') ?>" data-select-all></th>
                                <th><?= ps_h('Title') ?></th><th class="lx-hide-sm"><?= ps_h('Owner') ?></th><th><?= ps_h('Last modified') ?></th><th><span class="ps-sr-only"><?= ps_h('Actions') ?></span></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($projects as $p):
                                $mine = (int) $p['user_id'] === $user_id;
                                $owner = $mine ? 'You' : trim($p['firstname'] . ' ' . $p['lastname']);
                                $when = $p['edited_at'] && $p['edited_at'] > $p['updated_at'] ? $p['edited_at'] : $p['updated_at'];
                                $by = $p['editor_id'] ? ((int) $p['editor_id'] === $user_id ? 'You' : $p['editor_first']) : null; ?>
                                <tr data-project="<?= (int) $p['document_id'] ?>" data-mine="<?= $mine ? '1' : '0' ?>" data-title="<?= e(mb_strtolower($p['title'])) ?>">
                                    <td class="ps-col-check"><?php if ($mine): ?><input type="checkbox" class="lx-check" aria-label="Select <?= e($p['title']) ?>" data-select><?php endif; ?></td>
                                    <td class="is-primary"><a class="lx-title" href="/<?= latexCanAccess((int) $p['document_id'], (int) $user_id, true) ? 'edit' : 'view' ?>?document_id=<?= (int) $p['document_id'] ?>"><i class="bx bx-file" aria-hidden="true"></i><span><?= e($p['title']) ?></span></a><?php if ((int) $p['shares'] > 0 && $mine): ?><span class="ps-badge lx-shared" title="Shared with <?= (int) $p['shares'] ?>"><i class="bx bx-group"></i><?= (int) $p['shares'] ?></span><?php endif; ?></td>
                                    <td class="lx-hide-sm"><span class="lx-owner"><?= latex_avatar(['firstname' => $p['firstname'], 'lastname' => $p['lastname'], 'image_source' => $p['image_source']], 'xs') ?><?= e($owner) ?></span></td>
                                    <td class="lx-when"><?= e(latex_ago($when)) ?><?= $by ? ' by ' . e($by) : '' ?></td>
                                    <td class="lx-actions">
                                        <?php if ($mine || $canModerate): ?>
                                            <?php if ($canCreate || $canModerate): ?><button type="button" class="ps-btn ps-btn--ghost ps-btn--sm ps-btn--icon" data-share="<?= (int) $p['document_id'] ?>" aria-label="<?= ps_h('Share and rename') ?>" title="<?= ps_h('Share and rename') ?>"><i class="bx bx-share-alt"></i></button><?php endif; ?>
                                            <button type="button" class="ps-btn ps-btn--ghost ps-btn--sm ps-btn--icon" data-delete="<?= (int) $p['document_id'] ?>" aria-label="<?= ps_h('Delete') ?>" title="<?= ps_h('Delete') ?>"><i class="bx bx-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="ps-empty" data-no-match hidden><i class="bx bx-search-alt"></i><div><?= ps_h('No projects match.') ?></div></div>
                    <?php else: ?>
                    <div class="ps-empty"><i class="bx bx-file"></i><div><?= ps_h('No projects yet.') ?></div><?php if ($canCreate): ?><button type="button" class="ps-btn ps-btn--primary" data-modal-open="project-new"><i class="bx bx-plus"></i><?= ps_h('New project') ?></button><?php endif; ?></div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<?php if ($canCreate): ?><div class="ps-modal-backdrop" id="project-new" hidden>
    <form class="ps-frame ps-modal ps-modal--sm" role="dialog" aria-modal="true" aria-labelledby="project-new-title" data-project-form>
        <div class="ps-frame__panel">
            <div class="ps-modal__head"><h2 class="ps-modal__title" id="project-new-title"><?= ps_h('New project') ?></h2><button type="button" class="ps-btn ps-btn--ghost ps-btn--icon" data-modal-close aria-label="<?= ps_h('Close') ?>"><i class="bx bx-x"></i></button></div>
            <div class="ps-modal__body">
                <input type="hidden" name="document_id" value="">
                <label class="ps-field"><span class="ps-label"><?= ps_h('Project name') ?></span><input class="ps-input" name="title" maxlength="255" required autocomplete="off" placeholder="<?= ps_h('Untitled project') ?>"></label>
                <span class="ps-label"><?= ps_h('Share with') ?></span>
                <div class="ps-input-wrap lx-people-filter"><i class="bx bx-search" aria-hidden="true"></i><input class="ps-input" type="search" placeholder="<?= ps_h('Search people and groups') ?>" aria-label="<?= ps_h('Search people and groups') ?>" data-people-filter></div>
                <div class="lx-people" data-people><p class="ps-muted ps-small"><?= ps_h('Loading people...') ?></p></div>
            </div>
            <div class="ps-modal__foot"><button type="button" class="ps-btn ps-btn--ghost" data-modal-close><?= ps_h('Cancel') ?></button><button type="submit" class="ps-btn ps-btn--primary" data-project-submit><?= ps_h('Create') ?></button></div>
        </div>
    </form>
</div><?php endif; ?>
<?php latex_page_end(['js/projects.js']);
