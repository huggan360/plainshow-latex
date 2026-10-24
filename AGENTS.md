# PlainShow LaTeX: notes for developers and AI agents

LaTeX is latex.plainshow.se: collaborative LaTeX projects. A project is a
folder of files with a main file (`documents.root_file`, usually `main.tex`).
Plain PHP pages and APIs plus a websocket server for live editing.

## Layout

- `index.php` (projects), `edit.php` (editor shell), `view.php` (read-only).
- `api/`: JSON endpoints. `_workspace.php` is the file layer (safe names, the
  project folder `temp/document_<id>/`). `v1.php` and `widgets.php` are for core.
  Old section endpoints answer 410 (`_gone.php`).
- `js/editor/*.js`: editor modules (app, tabs, tree, outline, collab, pdf,
  compile, spellcheck). `js/ot.js`: operational transform client.
- `websocket/server.php` (+ `TextOperation.php`): live editing keyed by
  project and file path; runs under pm2 as `latex-websocket`.
- `lib/`: `plainshow.php` (sign-in through the kit), `db.php` (tables live in
  the blog's `woc` database), `account.php`, `view.php` (layout, 404 page).
- `bin/migrate.php`: applies `database/migrations/*.sql`.

## Work

- Tests: `php tests/server.php`,
  `node tests/workspace.cjs`, `node tests/reliability.cjs`,
  `node tests/live-collaboration.cjs`.
- This folder is the live checkout. Work in it only on a clean tree you
  commit; Updates refuses to update a dirty checkout. Database changes go in a
  new `database/migrations/*.sql` file; `php bin/migrate.php` applies each once
  (Updates runs it). The first file is the schema for a new server.
- Addresses of other apps come from kit `Platform` (`ps_url('core', '/account')`),
  never written into code.
- Release: tag and install via Updates (composer install, `php bin/migrate.php`,
  then the websocket restarts: pm2 on the first PlainShow, the
  `plainshow-latex-websocket` systemd unit on installed servers).

## The PlainShow platform

PlainShow is a set of web services on one server, each in its own git repo on
GitHub (`huggan360/plainshow-*`, private). They share one account system and
one look.

| Repo | Site | What it is |
|---|---|---|
| core | plainshow.se | Dashboard, accounts, groups, roles, admin, **Updates**. Laravel + Inertia/React. |
| login | login.plainshow.se | The only sign-in. OAuth2/OpenID Connect server, 2FA, device approval. Laravel. |
| kit | (library) | Shared PHP (auth, signed requests, widgets, access rules) and UI (CSS, JS, fonts, brand, error pages). Used by every app through Composer. |
| cloud | cloud.plainshow.se | Files, photos, calendar, tasks. A frozen Nextcloud 34.0.3 fork; our app is `apps/plainshow`. |
| latex | latex.plainshow.se | Collaborative LaTeX projects. Plain PHP + a websocket server. |
| blog | blog.plainshow.se | Posts, albums, films. Plain PHP. Swedish UI. |
| split | split.plainshow.se | Shared expenses. Plain PHP. Swedish UI. |

### Versions and releases (all repos)

- Versions are semver tags `vX.Y.Z` (patch: fixes; minor: features; major:
  breaking data or API changes). Only tags are installed, never branches.
- A release is: update `CHANGELOG.md` (a `## vX.Y.Z` section at the top, in
  plain words; the Updates page shows it), commit, `git tag -a vX.Y.Z -m vX.Y.Z`,
  `git push origin HEAD vX.Y.Z`.
- Install it through **Updates** in core (Admin, Updates, Install). The cron
  runner (`php artisan plainshow:updates --run`, every minute, in
  `/srv/plainshow/core/current`) fetches tags from GitHub, builds, switches,
  health-checks and rolls back by itself on failure. From a shell you can
  queue a job: insert into `core_update_jobs` (`component`, `action` = update,
  `target` = the tag, `status` = queued) and run the command above.
- While a component installs, visitors of its site see the "updating" page
  (flag file `/srv/plainshow/updating/<host>`, served by
  `core/ops/apache/plainshow-errors.conf`). Old releases are deleted after a
  successful update; nothing legacy is kept.
- Never copy files into `/srv/plainshow` or a live checkout by hand, never
  deploy a working tree (`--worktree` exists for emergencies only), never edit
  `vendor/`. Uncommitted changes in a checkout block its updates.

### Where things are on the server

- Release apps (core, login, blog, split): `/srv/plainshow/<app>/releases/<timestamp>-<tag>`,
  `current` symlink, shared state in `/srv/plainshow/<app>/shared` (`.env` or
  `config/`, `storage/`). Each runs in its own PHP-FPM pool as `plainshow-<app>`
  (the blog runs as www-data).
- Checkout apps: cloud in `/var/www/html/cloud`, latex in
  `/var/www/html/projects/hugohansson/plainshow-latex`. Updates moves them
  forward to a tag and runs their steps (see `core/config/updates.php`).
- Development sources: `/home/hugohansson/projects/plainshow/<repo>`.
- Error and updating pages: made in kit (`ui/errors`, `php bin/errors.php`),
  installed by core's deploy into `/srv/plainshow/errors`, shown by Apache for
  every site and by the apps themselves.
- Backups go to `/mnt/texlive/backups`. **Never touch `/mnt/cloud`** (Cloud's data).

### House rules

- Accounts live in login/core only. Services read identity from Login (OIDC)
  and permissions from core; never add a local password system.
- UI text: short and plain; never use the middle dot character as a
  separator.

- Design: kit CSS (`ps-*` classes, `--ps-*` tokens), Outfit for UI text, the
  serif for titles, brand orange `#FC5000`, nested "frame" boxes.
- Secrets: never commit `.env`, `config/*.php` with credentials, keys or
  tokens, and never print them in logs or output.
- Test what you change with the repo's own tests (below) before tagging.

### Languages (English, Dutch, Swedish)

- Every page speaks English, Dutch or Swedish. The choice is the `ps_lang`
  cookie for the whole domain, set by the language switch in every account
  menu; without it the browser's language decides, then English (kit `Lang`).
- Texts are written in one source language (English in core, login, cloud and
  latex; Swedish in blog and split) and looked up by that text in
  `lang/<code>.json` ({"Source text": "Translation"}). `{name}` placeholders
  are filled in after translating (Laravel's `__()` uses `:name`).
- Wrap every visible text: React `t('...')` and `tn(count, 'one', '{n} many')`
  from the app's `i18n.ts`; Laravel `__('...')`; plain PHP `ps_h('...')` in HTML
  and `ps_t('...')` elsewhere; plain JS `psT('...')`. Never build a sentence
  from pieces; use one text with placeholders. Dates use the chosen locale.
- After adding texts, add their translations and check:
  `python3 vendor/plainshow/kit/bin/i18n-check.py lang/nl.json <code folders>`
  (also `sv.json`, or `en.json` for blog and split).
- The account menu is the same everywhere: Account, the app's own items,
  Language, Theme, then Sign out.
