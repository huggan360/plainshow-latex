<?php
/* Page frame and small view helpers for LaTeX, on the PlainShow UI kit. */

use PlainShow\Kit\Identity;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function latex_ui_version(): string
{
    static $version = null;
    return $version ??= substr((string) \Composer\InstalledVersions::getReference('plainshow/kit'), 0, 8) ?: 'dev';
}

function latex_asset(string $path): string
{
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    return '/' . ltrim($path, '/') . '?v=' . (is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : latex_ui_version());
}

function latex_ago(?string $date): string
{
    $time = $date ? strtotime($date) : false;
    if ($time === false) {
        return '';
    }
    $seconds = max(0, time() - $time);
    if ($seconds < 60) {
        return ps_t('just now');
    }
    $units = [
        [3600, 60, '1 minute ago', '{n} minutes ago'],
        [86400, 3600, '1 hour ago', '{n} hours ago'],
        [2592000, 86400, '1 day ago', '{n} days ago'],
        [31536000, 2592000, '1 month ago', '{n} months ago'],
        [PHP_INT_MAX, 31536000, 'a year ago', '{n} years ago'],
    ];
    foreach ($units as [$limit, $unit, $one, $many]) {
        if ($seconds < $limit) {
            $n = intdiv($seconds, $unit);
            return ps_t($n === 1 ? $one : $many, ['n' => $n]);
        }
    }
    return '';
}

/** Avatar from a user row: firstname, lastname, image_source. */
/** Where old profile pictures (relative paths) are, with a trailing slash. */
function latex_picture_base(): string
{
    return (ps_url('blog') ?: ps_url('core')) . '/';
}

/** A profile picture's address; old pictures are paths on the blog (or on the dashboard, without one). */
function latex_picture_url(?string $source): string
{
    $source = trim((string) $source);
    if ($source !== '' && !preg_match('~^https?://~', $source)) {
        $source = latex_picture_base() . ltrim($source, '/');
    }
    return $source;
}

function latex_avatar(array $row, string $size = ''): string
{
    $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
    $initials = mb_strtoupper(mb_substr((string) ($row['firstname'] ?? ''), 0, 1) . mb_substr((string) ($row['lastname'] ?? ''), 0, 1)) ?: '?';
    $source = latex_picture_url($row['image_source'] ?? '');
    $inner = $source !== ''
        ? '<img class="ps-avatar__img" src="' . e($source) . '" alt="" loading="lazy">'
        : '<span class="ps-avatar__initials" aria-hidden="true">' . e($initials) . '</span>';
    return '<span class="ps-avatar' . ($size ? ' ps-avatar--' . $size : '') . '" title="' . e($name) . '">' . $inner . '</span>';
}

function latex_switcher(): string
{
    ob_start(); ?>
    <div class="ps-dropdown" data-ps-dropdown>
        <button type="button" class="ps-btn ps-btn--ghost ps-btn--icon ps-switcher__trigger" data-ps-dropdown-trigger aria-expanded="false" aria-label="<?= ps_h('Switch service') ?>"><i class="bx bx-grid"></i></button>
        <div class="ps-switcher__panel" data-ps-dropdown-menu hidden>
            <div class="ps-switcher__grid">
                <a class="ps-switcher__app" href="<?= e(ps_url('core')) ?>"><i class="bx bx-grid-alt"></i><?= ps_h('Dashboard') ?></a>
                <?php if (latexHasService('blog') && ps_url('blog') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('blog')) ?>"><i class="bx bx-news"></i><?= ps_h('Blog') ?></a><?php endif; ?>
                <a class="ps-switcher__app is-current" href="/"><i class="bx bx-code-curly"></i><?= ps_h('LaTeX') ?></a>
                <?php if (latexHasService('split') && ps_url('split') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('split')) ?>"><i class="bx bx-wallet"></i><?= ps_h('Split') ?></a><?php endif; ?>
                <?php if (latexHasService('web') && ps_url('web') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('web')) ?>"><i class="bx bx-server"></i><?= ps_h('Web') ?></a><?php endif; ?>
                <?php if (latexHasService('cloud') && ps_url('cloud') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('cloud')) ?>"><i class="bx bx-cloud"></i><?= ps_h('Cloud') ?></a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}

/** You, at the foot of the sidebar, with the account menu opening upwards. */
function latex_me(Identity $identity): string
{
    $me = ['firstname' => $identity->firstName(), 'lastname' => $identity->lastName(), 'image_source' => $identity->picture()];
    ob_start(); ?>
    <div class="ps-dropdown ps-me" data-ps-dropdown>
        <button type="button" class="ps-me__button" data-ps-dropdown-trigger aria-expanded="false" aria-label="<?= ps_h('Account') ?>">
            <?= latex_avatar($me, 'sm') ?>
            <span class="ps-me__text"><span class="ps-me__name"><?= e($identity->name()) ?></span><span class="ps-me__meta">@<?= e($identity->username()) ?></span></span>
            <i class="bx bx-chevron-up ps-me__chevron" aria-hidden="true"></i>
        </button>
        <div class="ps-dropdown__menu ps-dropdown__menu--up" data-ps-dropdown-menu hidden>
            <a class="ps-dropdown__item" href="<?= e(ps_url('core', '/account')) ?>"><i class="bx bx-user"></i><?= ps_h('Account') ?></a>
            <?= \PlainShow\Kit\Lang::menuSwitch() ?>
            <button type="button" class="ps-dropdown__item" data-ps-theme-toggle><i class="bx bx-adjust"></i><span data-ps-theme-label><?= ps_h('Light theme') ?></span></button>
            <div class="ps-dropdown__sep"></div>
            <a class="ps-dropdown__item" href="/auth/logout"><i class="bx bx-log-out"></i><?= ps_h('Sign out') ?></a>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}

function latex_account_menu(Identity $identity): string
{
    $me = ['firstname' => $identity->firstName(), 'lastname' => $identity->lastName(), 'image_source' => $identity->picture()];
    ob_start(); ?>
    <div class="ps-dropdown" data-ps-dropdown>
        <button type="button" class="ps-btn ps-btn--ghost ps-btn--icon ps-switcher__trigger" data-ps-dropdown-trigger aria-expanded="false" aria-label="<?= ps_h('Switch service') ?>"><i class="bx bx-grid"></i></button>
        <div class="ps-switcher__panel" data-ps-dropdown-menu hidden>
            <div class="ps-switcher__grid">
                <a class="ps-switcher__app" href="<?= e(ps_url('core')) ?>"><i class="bx bx-grid-alt"></i><?= ps_h('Dashboard') ?></a>
                <?php if (latexHasService('blog') && ps_url('blog') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('blog')) ?>"><i class="bx bx-news"></i><?= ps_h('Blog') ?></a><?php endif; ?>
                <a class="ps-switcher__app is-current" href="/"><i class="bx bx-code-curly"></i><?= ps_h('LaTeX') ?></a>
                <?php if (latexHasService('split') && ps_url('split') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('split')) ?>"><i class="bx bx-wallet"></i><?= ps_h('Split') ?></a><?php endif; ?>
                <?php if (latexHasService('web') && ps_url('web') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('web')) ?>"><i class="bx bx-server"></i><?= ps_h('Web') ?></a><?php endif; ?>
                <?php if (latexHasService('cloud') && ps_url('cloud') !== ''): ?><a class="ps-switcher__app" href="<?= e(ps_url('cloud')) ?>"><i class="bx bx-cloud"></i><?= ps_h('Cloud') ?></a><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="ps-dropdown" data-ps-dropdown>
        <button type="button" class="ps-btn ps-btn--ghost lx-account" data-ps-dropdown-trigger aria-expanded="false" aria-label="<?= ps_h('Account') ?>"><?= latex_avatar($me, 'sm') ?></button>
        <div class="ps-dropdown__menu" data-ps-dropdown-menu hidden>
            <div class="ps-dropdown__head"><?= latex_avatar($me) ?><div class="lx-account__who"><strong><?= e($identity->name()) ?></strong><span class="ps-muted ps-small">@<?= e($identity->username()) ?></span></div></div>
            <div class="ps-dropdown__sep"></div>
            <a class="ps-dropdown__item" href="<?= e(ps_url('core', '/account')) ?>"><i class="bx bx-user"></i><?= ps_h('Account') ?></a>
            <?= \PlainShow\Kit\Lang::menuSwitch() ?>
            <button type="button" class="ps-dropdown__item" data-ps-theme-toggle><i class="bx bx-adjust"></i><span data-ps-theme-label><?= ps_h('Light theme') ?></span></button>
            <div class="ps-dropdown__sep"></div>
            <a class="ps-dropdown__item" href="/auth/logout"><i class="bx bx-log-out"></i><?= ps_h('Sign out') ?></a>
        </div>
    </div>
    <?php return (string) ob_get_clean();
}

/** Opens the page. $page: projects or editor. */
function latex_page_start(string $title, string $page, string $head = ''): void
{
    ?>
<!doctype html>
<html lang="<?= e(\PlainShow\Kit\Lang::current()) ?>" data-theme="dark"<?= \PlainShow\Kit\Theme::attributes() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#151515">
    <meta name="csrf-token" content="<?= e(latexCsrfToken()) ?>">
    <title><?= e($title) ?> | LaTeX</title>
    <link rel="icon" href="/ui/brand/favicon.ico?v=<?= e(latex_ui_version()) ?>" sizes="any">
    <link rel="apple-touch-icon" href="/ui/brand/apple-touch-icon.png?v=<?= e(latex_ui_version()) ?>">
    <link rel="preload" href="/ui/fonts/source-serif-4-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/ui/fonts/outfit-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/ui/css/boxicons.css?v=<?= e(latex_ui_version()) ?>">
    <link rel="stylesheet" href="/ui/css/plainshow.css?v=<?= e(latex_ui_version()) ?>">
    <?= $head ?>
    <link rel="stylesheet" href="<?= e(latex_asset('css/latex.css')) ?>">
    <script>try { var t = localStorage.getItem('plainshow-theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) {}</script>
    <script>window.PS_PICTURES = <?= json_encode(latex_picture_base(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>; window.PS_I18N = <?= json_encode((object) \PlainShow\Kit\Lang::dictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>; window.psT = function (s, p) { var o = window.PS_I18N[s] || s; if (p) Object.keys(p).forEach(function (k) { o = o.split('{' + k + '}').join(String(p[k])); }); return o; };</script>
    <script src="<?= e(latex_asset('js/csrf.js')) ?>"></script>
    <script src="/ui/js/plainshow.js?v=<?= e(latex_ui_version()) ?>" defer></script>
    <?php if ($page === 'projects'): ?><script src="/ui/js/backdrop.js?v=<?= e(latex_ui_version()) ?>" defer></script><?php endif; ?>
</head>
<body class="ps lx lx--<?= e($page) ?>">
<?php
}

function latex_page_end(array $scripts = []): void
{
    foreach ($scripts as $script) {
        echo '<script src="' . e(latex_asset($script)) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/** PlainShow's "page not found" page (installed by core in /srv/plainshow/errors), then stop. */
function latexNotFound(): never
{
    http_response_code(404);
    $page = '/srv/plainshow/errors/404.html';
    if (is_readable($page)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($page);
    } else {
        echo 'Project not found.';
    }
    exit;
}
