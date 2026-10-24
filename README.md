# PlainShow LaTeX

`latex.plainshow.se`: write and compile LaTeX together, live, as a PlainShow extension. Projects page and editor follow Overleaf's structure in the PlainShow look (plainshow-kit).

- **Sign in** through login.plainshow.se (`lib/plainshow.php`). LaTeX has its own database user and keeps a profile row per person, refreshed at sign-in.
- **Security:** every API call checks CSRF and access to the project; project files are served only through `api/file.php`, and nothing uploaded into a project can run.
- **Dashboard widget** `recent-projects` at `/api/v1/widgets/...`, signed by core.
- **Read API** at `plainshow.se/api/latex/v1` for personal PlainShow tokens (`api/v1.php`): your projects, a project's files, a file and the compiled PDF. `/api/v1/openapi.json` describes it.
- **Collaboration server** under PM2: `plainshow project plainshow-latex pm2 restart latex-websocket`.

Served straight from this checkout; Admin, Updates on plainshow.se moves it to a new tag and runs `composer install` and the restart.

## A project is a folder of files

Each project lives in `temp/document_<id>/`. Compiling runs pdflatex on the
project's main file (`documents.root_file`, `main.tex` unless you pick another
with "Use as main file") from the project folder, and the PDF is always
`document_<id>.pdf`, which the editor, the read API and the widget use.

## Editor

`edit.php` is the frame; the work is in `js/editor/` (`app.js` has the map):
the file tree and outline in the side panel, every open file in a tab
(remembered per project), live editing in `collab.js`, compiling, the PDF
pane and spelling. No build step.

## Live editing

`websocket/server.php` keeps one shared state per open text file, keyed by
project and path: its text, a revision and a log of operational transforms.
Edits that arrive in the same loop tick share one write, which goes to a
temporary file renamed over the real one; nothing is acknowledged before it.
Renames and deletes made through the file API are announced over the socket,
and the server moves or drops the state after checking the disk.

The editor keeps unconfirmed edits in sessionStorage (a reload resumes them)
and a recovery copy in localStorage. After a server restart, unconfirmed edits
are merged onto the server's text with a minimal diff and sent again.

## Verification

```sh
php tests/server.php          # the server, without a socket, on a scratch folder
node tests/reliability.cjs    # the client against a scripted server, and OT fuzzing
node tests/workspace.cjs      # paths and renames
CLIENTS=5 ROUNDS=10 LATENCY=40 node tests/live-collaboration.cjs
```

The live test starts its own server on a spare port and drives the editor's
real code over WebSockets on a disposable project, with a rename and a server
restart while everyone types, then deletes the project.

Projects moved from sections to files in 2.0.0; the old section tables were
dropped on 2026-10-24 (a dump is in `/mnt/texlive/backups`).
