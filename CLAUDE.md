# Working rules for this repository

`CONTRIBUTING.md` is the specification, not background reading. Read it in full
before writing any code — most of what follows is a pointer into it, and where
this file and `CONTRIBUTING.md` disagree, `CONTRIBUTING.md` wins.

The rest of this file is the part that is not written down anywhere else: the
house style as it is actually practised in the tree, and the checks to run
before a diff is offered.

## Before writing anything

Open the nearest existing file that does the same kind of job and follow it.
This codebase is procedural PHP with no framework and no build step, so
consistency is carried by imitation rather than by tooling. A handler is copied
from the neighbouring handler, a list page from the neighbouring list page, a
modal from the neighbouring modal. Never import a personal style, and never
reformat code that the change does not otherwise touch — it buries the real
diff and gets the PR rejected on its own.

## The rules that get PRs rejected

Restated from `CONTRIBUTING.md` because they account for nearly all review
feedback. The full reasoning is there under "Security rules (non-negotiable)".

- Every value interpolated into SQL is `intval()` (unquoted) or `escapeSql()`
  (inside quotes). Values read back out of the database get the same treatment
  before they go into another query.
- `logAudit()`, `appNotify()`, `logHistory()` and `logTicketHistory()` are
  queries wearing a disguise. Their description arguments owe `escapeSql()`.
- `validateCSRFToken()` is the first line of every action block, called bare.
- `enforceUserPermission('module_x', 1|2|3)` — read / write / delete. Admin
  handlers inherit the gate from `admin/post.php` and do not call it; the client
  portal uses `enforceContactCan()`. Everything under `agent/post/` calls it.
- One record is checked with `enforceClientAccess()`; any query returning more
  than one row appends `clientScopeSql('<entity>_client_id')` on the resource's
  own client column, never on a joined `clients.client_id`.
- Output is escaped where the row is read, not where it is echoed. Assign
  through `escapeHtml()` once, then echo the variable raw. Ints get `intval()`.
- Fetch helpers (`getFieldById()`, `getTicketStatusName()`) return raw values.
  The call site escapes, and escapes once — double-escaping is a real bug here.
- No `shell_exec`/`exec`/`eval`. No new Composer or npm dependency, ever;
  libraries are vendored into `libs/` and `libs/` is never edited in place.
- `SELECT` the columns actually used, not `*`. The one exception is
  `api/v1/*/read.php`, where the row is the response contract.
- A schema change is two edits in one PR: `db.sql` plus a new
  `admin/database_updates/<x.y.z>.php`. Never edit a historical migration.
- Changing a single action means checking for its `bulk_*` counterpart and
  changing that too.

## House style, as observed in the tree

Layout is fixed by `.editorconfig` and `.gitattributes`: 4 spaces, LF, UTF-8,
no trailing whitespace, final newline. Beyond that:

- Files open with a block comment naming the file's job:

  ```php
  /*
   * ITFlow - GET/POST request handler for vendors
   */
  ```

- Handler files carry `defined('FROM_POST_HANDLER') || die(...)`, migrations
  carry `defined('FROM_DB_UPDATER') || die(...)`.
- Action blocks breathe: a blank line after the opening `if (isset($_POST[...]))
  {`, short `// Comment` labels over each stage (`// GET POST Data`,
  `// Permission check`, `// Vendor add query`), and a blank line before the
  closing brace. The tail of a block is act, `logAudit()`, `flashAlert()`,
  `redirect()`.
- Shared create/edit field collection goes in `<entity>_model.php`. The `_model`
  suffix is reserved — the dispatcher skips those files, so a handler named that
  way is silently never loaded.
- Markup echoes with `<?= $var ?>`, not `<?php echo $var; ?>` (6399 uses to 8).
- `} elseif {`, not `} else if {` (174 to 4).
- Functions are camelCase, variables and columns snake_case. Comments are plain
  `//` or `/* */` prose in English — no PHPDoc blocks, no `@param`, and type
  hints only where existing code already uses them.
- The `$x_display` idiom carries a fallback: assign the escaped value, then set
  `$x_display` to either it or `"-"` / `''`.
- UI is Bootstrap 4 / AdminLTE. Icons read `fas fa-fw fa-<name> me-2`. Modals
  live under `<portal>/modals/<module>/`, start with `modal_header.php` and
  `ob_start()`, post to `action="post.php"`, and carry the CSRF hidden input.
  Monospace for technical data (IPs, serials, keys), proportional for prose.

## Before offering a diff

Run these, every time, and report the result rather than asserting the change
is fine:

1. `php -l` on every changed PHP file — this is what CI's PHPLint runs.
2. `git diff --check` for whitespace damage.
3. Re-read the diff against the rejection list above, one line at a time,
   looking specifically for an unescaped interpolation, a missing CSRF or
   permission call, an unscoped list query, and a `bulk_*` twin left behind.
4. If `db.sql` changed, confirm the matching migration file exists.

## Commits

Subject line is `Area: imperative summary`; the body is a short prose paragraph
explaining why the change is needed and what it affects, wrapped, no bullets.
One feature or one fix per commit. Relocation and reformatting are separate
commits from logic changes.
