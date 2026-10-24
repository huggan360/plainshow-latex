# Changelog

## v2.5.1

- Migrations also run where LaTeX's database user may not create tables (the first PlainShow's shared database).

## v2.5.0

- Every address comes from the platform file (kit 1.12), so LaTeX runs on any domain; old profile pictures come from the blog, or the dashboard without one.
- LaTeX's tables are in `database/migrations`; `php bin/migrate.php` sets them up on a new server (Updates runs it).

## v2.4.3

- Deleting a document no longer touches the old section tables, which are gone. The one-off move from sections and its test are removed.

## v2.4.2

- Page headings are a real frame with its inner panel; the main page's heading has the orange ring around the panel, as on widgets (kit 1.11.1).

## v2.4.1

- Page headings sit in a frame of their own; the projects page has the orange ring (kit 1.11).

## v2.4.0

- Compiling follows the open file: a .tex file that is a whole document (it has \documentclass) compiles on its own, and switching between documents switches the PDF too (compiling a document the first time it is opened). In a chapter or other part, the last document stays. The main file's PDF keeps its address.
- The spelling suggestions menu stays open on right-click instead of closing at once.
- The people online in the top right show their profile pictures.
- Keyboard shortcuts: proper padding and a line between rows.

## v2.3.2

- Background: large soft orange blobs that fade in, drift and fade out, lighting only the glyphs (kit 1.10).

## v2.3.1

- New background: embers from orange to yellow that move away from the pointer, so you can chase them (kit 1.9).

## v2.3.0

- English, Dutch and Swedish: the browser's language by default, or chosen in the account menu (the choice follows you to every PlainShow site).
- The account menu is the same in every app: Account, the app's own items, Language, Theme, Sign out.

## v2.2.0

- A project that does not exist shows PlainShow's error page.
- AGENTS.md for developers.

## 2.0.1

- When a project has errors but LaTeX still makes a PDF, the PDF is shown, with the number of errors next to Recompile; click it for the list. As before 2.0, where such projects still produced a PDF.
- Spelling skips the settings in commands like \definecolor and \lstdefinestyle, and package, file and label names.

## 2.0.0

- A project is a folder of files. `main.tex` sits in the project like any other file and compiling starts from it, or from another file you choose with "Use as main file". Sections are gone: every project's sections were joined into its `main.tex`, exactly as they compiled.
- The side panel has the file tree and the file outline. Every file you open gets a tab with a close button (middle-click closes too) and a dot while it is not yet saved; open tabs are remembered per project. Pictures and PDFs open as previews.
- Live editing works on any text file, with everyone's cursors and who is in which file. A rename or delete while someone else is typing keeps their work; after a server restart unsaved edits are merged back.
- Compile errors link to the file and line. The contents and references are filled in on the first compile.
- The editor reconnects by itself after a long break.

## 1.1.0

- The projects page has you at the foot of the sidebar with the account menu, a larger service switcher, and the glyph backdrop. Kit 1.1.

## 1.0.0

- plainshow-kit 1.0.
- The projects page and the editor in the PlainShow look after Overleaf's structure: menu bar, rail, file tree and outline, toolbar and PDF pane; adding files from another project or a web address.
- Its own database user; CSRF on every API call; uploaded scripts can never run; project files only through the access check.
- Outfit for text and Source Serif 4 for titles.

## 0.4.0

- A read API at plainshow.se/api/latex/v1 for personal PlainShow tokens: your projects, a project's files, a file, and the compiled PDF. `/api/v1/openapi.json` describes it.

