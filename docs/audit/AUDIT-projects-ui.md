# Independent QA/Product Audit — Projects (Kanban)

**Auditor stance:** independent senior product/QA critic, no application code changed.
**Scope:** `/projects`, `/projects/my-work`, `/projects/boards/{id}` and the task panel.
**Method:** read `routes/web.php`, `app/Http/Controllers/Projects/*`, `app/Services/Projects/*`,
`app/Models/Task.php`, `app/Services/Permissions/PermissionCatalog.php`,
`resources/views/projects/**`; drove the real app in a browser as four real accounts
(Owner/Admin/Operator/Viewer) against a throwaway board; confirmed every claim that can be
confirmed live, not just by reading code, and says so per item.
**Test data:** board `[AUDIT] Demo` (id 4), created through the UI, 10 cards across the 3
default columns, plus labels, a checklist, a comment, an overdue card, a WIP limit, and an
archived-then-restored card. Four throwaway accounts: `qa-audit@example.test` (Owner, board
creator), `qa-admin@example.test`, `qa-operator@example.test`, `qa-viewer@example.test`, all
members of the same team, password `password`. Nothing on any other team or board was touched.

---

## 1. Executive summary

The Kanban feature **works** — board/column/card CRUD, WIP limits, checklists, comments, search
and filtering, and all four permission tiers are implemented correctly and match the
server-side policy exactly in live testing, with no UI-vs-server mismatch found anywhere. That
is a real, well-built foundation. It is **not** demo-ready as currently styled: every new board's
three default columns are hardcoded in Russian and will show "К выполнению / В работе / Готово"
to an English- or Tajik-speaking juror on the very first board they open — the single most
visible defect in the section. The visual language leans on literal Unicode glyphs (◷ ☑ ✕ ×)
instead of the SVG icon set the rest of the product uses, which reads as unfinished next to CRM
or the dashboard. Closing a card or pressing Esc throws away search/filter state and, for Esc,
does a full hard page reload (confirmed via the network log — every font, every script
re-fetched) rather than Turbo's normal fast navigation. A board's "Board Settings" panel closes
itself after every single edit inside it, so renaming three columns in a row takes four separate
clicks to re-open the panel. None of this is a correctness bug in the sense of lost data; all of
it is the kind of first-five-minutes friction a jury would notice before anyone opens a card.

## 2. Scorecard (1–10)

| Area | Score | Why |
|---|---|---|
| Visual design | 5 | Clean card/column structure, consistent spacing and dark mode; undercut by Unicode-glyph icons, a redundant "+Add card" input directly above an "Add card" button in every column, and literal Russian text in a non-Russian UI. |
| UX flow | 4 | Closing a card drops search/filter state; Esc does a hard reload instead of a fast close; Board Settings closes itself after every edit. All three are things a user does repeatedly in one sitting. |
| Functionality | 8 | Every CRUD path I exercised (board, column, label, card, checklist, comment, move, archive/restore, search, filter, WIP limit) worked correctly, including a correct 422-and-revert when a drag violates a WIP limit. |
| Permissions | 9 | All four roles tested live, UI and server both checked (including a raw bypass attempt as Viewer → 403). Zero mismatches found. |
| i18n | 3 | Translation key parity is solid (100/100/100 across en/ru/tg) — the problem is two literal, un-translated Russian strings (default board name, three default column names) that ship on every new board/team regardless of locale. |
| Responsive | 2 | At ~400px width the dashboard's mobile nav drawer fails to translate off-screen and permanently covers the page with no way to dismiss it — reproduced on a fresh load, not a resize artifact. This is a shell-level bug (`layouts/dashboard.blade.php`), not Projects-specific, but it is what a juror hits first on a phone. |
| Accessibility | 4 | Every checklist-item toggle button on a card shares the identical accessible name "Checklist" — confirmed live via the accessibility tree, not just code — making a multi-item checklist unusable by name with a screen reader. No focus trap in the task panel modal. |
| Performance | 6 | No N+1 per card (eager-loaded via `whereIn` per column); query count scales with *column* count (~5 queries/column) rather than card count. A 3-column, 10-card board used 23 of the existing regression test's 25-query budget — closer to the ceiling than the test suite's own single-busy-column fixture suggests. Page HTML alone is ~431 KB for that board (method below). |
| Reliability | 7 | Double-submit guard (from the prior branch) verified live: a genuine double-click on "Add card" created exactly one row. WIP-limit rejection is handled gracefully with a translated error, not a 500. One real bug: the WIP-limit counter (the "/ N" suffix) silently disappears from *every* column after any single drag on the board, not just the one with the limit. |

---

## 3. Findings

Severity: **blocker** (breaks the demo / core workflow) · **major** (clearly wrong, workaround exists) · **minor** (real but low-impact) · **polish**.

| ID | Area | Screen | What's wrong | Evidence | Severity | Suggested fix |
|---|---|---|---|---|---|---|
| PRJ-001 | i18n | New board | Every new board's 3 default columns are the hardcoded literal strings `'К выполнению'`, `'В работе'`, `'Готово'` — never translated, regardless of the owner's locale. | [`app/Services/Projects/ProjectBoards.php:49-52`](../../app/Services/Projects/ProjectBoards.php#L49); live in [10-board-locale-tajik…png](projects-ui/10-board-locale-tajik-hardcoded-russian-columns.png) (Tajik UI, Russian columns) vs. [11-board-locale-russian.png](projects-ui/11-board-locale-russian.png) (only correct by coincidence, since Russian is the viewer's own locale) | Blocker (i18n) | Resolve column names through `__('projects.default_column_*')` at creation time, keyed to the acting user's locale. |
| PRJ-002 | i18n | Default `_inbox` board | The team's auto-created default board is hardcoded `'Задачи'` (Russian for "Tasks"), same issue as PRJ-001. | [`app/Services/Projects/ProjectBoards.php:20`](../../app/Services/Projects/ProjectBoards.php#L20) | Major | Same fix, applied to `defaultForTeam()`. |
| PRJ-003 | Responsive | Any dashboard page incl. Projects, ≤~1023px | The mobile nav drawer (`#app-sidebar`) never gets Alpine's `-translate-x-full` class, so it renders fully open and permanently covers the page; there's no backdrop/scrim to dismiss it since `mobileNavOpen` is correctly `false` the whole time. Reproduced on a **genuinely fresh page load** in a new tab at 400×800 — not a resize artifact. | `getComputedStyle(sidebar).translate` = `"none"`, `className` missing `-translate-x-full` — checked live via JS on a fresh load; [08-board-400px-nav-overlay-bug.png](projects-ui/08-board-400px-nav-overlay-bug.png) | Blocker (responsive), but **scope note: this is `layouts/dashboard.blade.php`, not Projects-specific** — it blocks evaluating Projects on a phone, which is why it's reported here, but the fix belongs to the shell. | File separately against the dashboard shell; verify Alpine's `:class` reactivity on `data-turbo-permanent` elements. |
| PRJ-004 | UX flow | Board Settings panel | Every write inside Board Settings (rename column, change WIP limit, add/remove label, reorder column, update board) redirects via `back()`, which drops the `#board-settings` URL fragment (fragments are never sent in the `Referer` header). Since `settingsOpen` is seeded once from `window.location.hash` at page load, the panel closes after every single edit. | [`resources/views/projects/board.blade.php:129`](../../resources/views/projects/board.blade.php#L129); [`resources/views/projects/partials/board-settings.blade.php`](../../resources/views/projects/partials/board-settings.blade.php) (every form plain `POST`, no `@submit.prevent`); live-reproduced: added a label, panel closed, `get_page_text` confirmed its contents gone and URL fragment-less | Major | Keep `#board-settings` through the redirect (e.g. `back()->withFragment(...)` isn't native Laravel — simplest fix: drive the panel's open state from a query param the controller preserves, not the URL fragment). |
| PRJ-005 | UX flow | Task panel, any entry point | Opening a card drops the board's current `q`/`filter` query string immediately (not just on close) — the card link only carries `task`, never `request()->query()`. Confirmed live: searched `q=login`, clicked the one matching card, URL became `?task=9` and the board behind the panel re-rendered with all cards visible again. | [`resources/views/projects/board.blade.php:55`](../../resources/views/projects/board.blade.php#L55) `href="{{ route('projects.boards.show', [$board, 'task' => $task->id]) }}"` — only `task` is passed | Major | Carry the current `q`/`filter` through the task link and the close/Esc link. |
| PRJ-006 | UX flow | Task panel, Esc key | `Esc` sets `window.location.href` directly, bypassing Turbo entirely — a **full hard page reload**, not Turbo's normal fast close. Confirmed via the network log: pressing Esc re-fetched every font file, `app.css`, `app.js`, the Turbo/Alpine vendor chunks and the brand image — the complete asset pipeline, exactly like a fresh tab. The ✕ button and backdrop click use a normal `<a href>` and get Turbo's fast navigation; only Esc is slow. | [`resources/views/projects/partials/task-panel.blade.php:1`](../../resources/views/projects/partials/task-panel.blade.php#L1) `@keydown.escape.window="window.location.href = @js(route(...))"`; network trace: `GET /fonts/onest/*.woff2`, `GET /@vite/client`, `GET /app.css`, `GET /app.js` all re-fired after the Escape keypress | Major | Make Esc dispatch the same `<a>` click (or a Turbo `visit()` call) the ✕ button uses, not a raw `location.href` assignment. |
| PRJ-007 | Reliability | Kanban board, after any drag | After a successful drag, the client recomputes every column's counter via `count.firstChild.textContent = String(...)`, replacing the **entire** text node — including the " / N" WIP-limit suffix, which is part of that same text node. Any drag anywhere on the board silently wipes the WIP-limit display off every limited column until the next full page load. Server data is untouched (confirmed: reload restored "3 / 3"). | [`resources/views/projects/board.blade.php:155-158`](../../resources/views/projects/board.blade.php#L155); live before/after: [13-wip-limit-suffix-lost-after-drag.png](projects-ui/13-wip-limit-suffix-lost-after-drag.png) shows "В работе 3" with no "/3" immediately after a successful drag elsewhere on the board | Major | Update only the numerator text node, or re-render the whole badge from a template that knows the limit. |
| PRJ-008 | Accessibility | Task panel, checklist | Every checklist-item toggle button shares the literal `aria-label="{{ __('projects.checklist') }}"` — the same string as the section heading. Confirmed live via the accessibility tree: two items, two buttons, both named "Checklist". A screen-reader user navigating by control name cannot tell which item a given button toggles. | [`resources/views/projects/partials/task-panel.blade.php:41`](../../resources/views/projects/partials/task-panel.blade.php#L41); live `find("Checklist")` returned both toggle buttons with identical names | Major | `aria-label` should include the item title, e.g. `__('projects.toggle_item', ['title' => $item->title])`. |
| PRJ-009 | Visual design | Kanban column | Mixed Unicode glyphs used as icons throughout — `◷` (due date), `☑` (checklist count), `◈`/`✕`/`×`/`←`/`→`/`+` — instead of the inline-SVG icon set the rest of the product (nav, CRM, dashboard) uses. Renders inconsistently across platforms/fonts and reads as unfinished next to the rest of the app. | [`resources/views/projects/board.blade.php:70,72`](../../resources/views/projects/board.blade.php#L70) etc.; visible in every board screenshot | Minor | Swap for the existing inline-SVG icon components. |
| PRJ-010 | Visual design | Kanban column | The inline "add card" affordance is a text input with placeholder "+ Add card" directly above a full-width button that *also* says "Add card" — repeated per column, so a 3-column board shows this pair three times. | [`resources/views/projects/board.blade.php:93-94`](../../resources/views/projects/board.blade.php#L93); visible in every board screenshot | Polish | Collapse to a single "+ Add card" affordance that expands into the input on click (the pattern Trello/Linear use). |
| PRJ-011 | Functionality | Kanban board, drag | HTML5-native drag-and-drop only (`draggable`, `@dragstart`/`@dragover`/`@drop`); no touch handlers, no pointer-events fallback — unusable with touch alone. No visual drop indicator: a synthesized `dragover` on the target column produced **zero** visual change (no highlight, no insertion line) — confirmed by diffing before/after screenshots pixel-for-pixel. | [`resources/views/projects/board.blade.php:53,43`](../../resources/views/projects/board.blade.php#L43); live: dispatched real `DragEvent`s via JS, [12-drag-in-progress-no-visual-feedback.png](projects-ui/12-drag-in-progress-no-visual-feedback.png) is visually identical to the non-dragging state | Major | Add a drop-zone highlight and insertion-point indicator at minimum; touch support is a larger project, see §6. |
| PRJ-012 | Routing | Any `/projects/*` URL | Locale-prefixed URLs (`/en/projects`, as used for the public/auth pages) 404 for the authenticated app — confirmed live (console/network: `GET /en/projects → 404`). Not a bug per se (the dashboard's locale is session/account state, not a URL prefix), but worth noting since it is an easy trap for anyone used to the public pages' URL scheme. | Live network trace | Polish | None needed if intentional; otherwise document the split. |
| PRJ-013 | i18n | Kanban board | Nav label for this whole section reads "Tasks" (en) in the sidebar, while the page itself is "Projects"/"[board name]" and the route is `projects.*`. Not wrong, but a juror bouncing between the sidebar label and the heading sees two different names for the same thing. | [`resources/views/layouts/dashboard.blade.php:151`](../../resources/views/layouts/dashboard.blade.php#L151) `__('agents.nav.projects')` → "Tasks" | Polish | Align the nav label and the page's own vocabulary ("Projects" or "Boards"). |

## 4. Part D — claim verification

All nine were checked **live**, not just read from code; verdicts below name the evidence.

| # | Claim | Verdict | Evidence |
|---|---|---|---|
| 1 | Assigning a person sends no notification; only bell is the CRM lead bell. | **CONFIRMED** | No `Notification::`, `Mail::`, or `broadcast()` call anywhere in `app/Http/Controllers/Projects`, `app/Services/Projects`, `Task.php`, or `ProjectTaskEvent.php`. Live: assigned QA Admin to a card as Owner — no toast, no bell-badge change, nothing in QA Admin's own session when checked separately. |
| 2 | Assignee changes aren't recorded in the activity log; updated/moved events show no field-level detail. | **CONFIRMED**, with nuance | `ProjectTaskController::update()` *does* write `details: {fields: [...]}` for `'updated'` and `ProjectTaskWorkflow::move()` *does* write `{from_column_id, to_column_id}` for `'moved'` — but `assignee_ids`/`label_ids` are never in the tracked `$changes` field list, and the task panel template (`task-panel.blade.php:48`) never reads `$event->details` at all, only the generic `event_{kind}` label. Live: changed description + priority + due date + 2 assignees + 1 label in one save → activity feed showed only "QA Audit updated the task", nothing else, confirmed in [06-task-panel-checklist-activity-dark.png](projects-ui/06-task-panel-checklist-activity-dark.png). So: the data partially exists for non-assignee fields but is never surfaced; assignee/label changes are invisible even in the raw data. |
| 3 | Task panel opens/closes via full page navigation; close drops q/filter. | **CONFIRMED, and more severe than stated** | The state is lost the instant a card is **opened**, not just on close — the card's own link never carries `q`/`filter` (see PRJ-005). Live-reproduced with a search in place. |
| 4 | After a drag, the column counter is overwritten with the current-page count. | **CONFIRMED**, and a second, more common variant found | For a >50-card (paginated) column this is true by construction. Far more commonly hit: *any* drag wipes the WIP-limit suffix off *every* column's counter, confirmed live (PRJ-007). |
| 5 | Drag-and-drop is mouse-only (HTML5 drag); no touch support; no drop indicator. | **CONFIRMED** | See PRJ-011. |
| 6 | Mixed Unicode glyphs used as icons; "+add task" input and full-width button repeated per column. | **CONFIRMED** | See PRJ-009, PRJ-010. |
| 7 | Task panel has no focus trap; Esc triggers a full reload. | **CONFIRMED** | No `x-trap`/focus-management code in `task-panel.blade.php`. Esc's hard reload confirmed via live network trace (PRJ-006) — every static asset re-fetched. |
| 8 | No workload/overview for a team lead (who has what, what's overdue/unassigned). | **CONFIRMED** | `/projects/my-work` (`BoardController::myWork()`) is hard-scoped to the signed-in user (`user_id = $request->user()->id OR assignees contains them`) — it is a personal "my work" view, not a team-wide one. No other overview route/view exists anywhere under `projects.*`. |
| 9 | Comments can't be edited or deleted; no @mentions. | **CONFIRMED** | Routes only define `POST .../comments` (`ProjectTaskController::comment()`); no update/destroy route exists. Live: typed `@qa-admin` in a comment — plain text, no autocomplete dropdown, confirmed by screenshot before submit. |

## 5. Part C — permissions vs. UI

Tested **live** with real accounts for all four roles (not static-only): `qa-audit` (Owner),
`qa-admin` (Admin), `qa-operator` (Operator), `qa-viewer` (Viewer), all on the same team/board.
Baseline from `PermissionCatalog::defaultMatrix()`:

| Permission | Owner | Admin | Operator | Viewer |
|---|---|---|---|---|
| `projects.view` | ✓ | ✓ | ✓ | ✓ |
| `projects.create` | ✓ | ✓ | ✓ | — |
| `projects.edit` | ✓ | ✓ | ✓ | — |
| `projects.assign` | ✓ | ✓ | ✓ | — |
| `projects.comment` | ✓ | ✓ | ✓ | — |
| `projects.manage` | ✓ | ✓ | — | — |

**Result: zero mismatches found.** For every role, the UI showed exactly the controls the
matrix predicts and nothing more:
- **Owner/Admin** — identical surface: Board Settings, "+ Add stage", inline add-card, full
  task-edit form, assign, comment, move, archive. No difference observed (matches the catalog,
  which grants Admin the full Projects bundle).
- **Operator** — "Board Settings" and "+ Add stage" correctly absent; inline add-card, full
  task-edit form, assign, comment, move, archive all correctly present.
- **Viewer** — correctly read-only everywhere: no inline add-card forms on the board, the task
  panel renders as plain text (no form, no "Save", checklist shown as static ✓/□ glyphs, no
  comment box).
- **Server-side bypass check**: as Viewer, sent a raw `fetch()` `PATCH` (via method override) to
  `projects.tasks.update` directly, bypassing the UI entirely → **403 "This action is
  unauthorized."** Confirms enforcement isn't just hidden buttons.

Nothing here needed "static only" — all four roles were real, logged-in accounts. One aside,
not Projects-specific: that 403 response body included a full Laravel debug stack trace
(file paths, line numbers) — expected with `APP_DEBUG=true` in local dev, worth a reminder to
confirm `APP_DEBUG=false` in production, but out of this audit's scope to verify further.

## 6. What's already good

- **Permission enforcement is airtight** — UI and server agree, for all four roles, verified live including a direct bypass attempt (§5).
- **WIP limits are enforced correctly and gracefully**: a rejected card-create showed a clear, translated error banner ("This stage has reached its task limit. Complete or move a task first.") rather than a 500 or a silent failure; a rejected drag optimistically moved the card and then correctly reverted it on the server's 422, with an error toast.
- **The double-submit guard works in production use**, not just in tests: a genuine rapid double-click on "Add card" created exactly **one** row, confirmed by querying the database directly after the click.
- **No N+1 per card** — assignees/labels/checklist items are eager-loaded with one `whereIn(...)` per column, not per card, even on a 10-card, 3-column board.
- **i18n key parity is solid**: `en`/`ru`/`tg` all define exactly 100 keys for the `projects` namespace. The i18n problem here (PRJ-001/002) is two literal strings that bypass the translation system entirely, not missing translations.
- **Soft-delete + archive + WIP-aware restore is coherent**: archiving, restoring, and the "Archived" filter all behaved correctly and consistently across the testing session.
- **CRM/Projects task unification works as documented** — reminders created from a customer/deal land on the same `_inbox` board and obey the same WIP/column rules as a native Projects card.

## 7. Missing features, ranked by value for a jury demo

1. **A translated default board/columns** (fixes PRJ-001/002) — nearly free, removes the single most visible defect.
2. **A team-lead workload view** (who has what, what's overdue/unassigned) — Linear/Asana/Trello all have this; Projects currently has nothing above "my own tasks" (confirmed claim 8). This is the single biggest functional gap for the stated audience ("a team lead").
3. **Keep search/filter state across opening a card** (PRJ-005) — small fix, large perceived-polish gain since it's hit on every single click-into-a-card during a filtered session.
4. **A real activity log** — surface the `details` that's already being recorded (PRJ-002); this is mostly a template change, the data exists.
5. **Editable/deletable comments** (claim 9) — table stakes for any comparable tool; currently a one-way append-only log.
6. **@mentions with a notification** (claims 1 + 9) — bigger lift (needs a notification channel, which doesn't exist for Projects at all today), but is the single feature every comparable tool leads with for "team lead assigns, gets confirmation" workflows.
7. **Touch drag support or a non-drag "move" affordance that's equally fast** — the existing "Move to" `<select>` already covers the *functional* gap (keyboard/touch users are not blocked), but it's a second-class, multi-step path next to drag.
8. **Bulk actions** (multi-select cards to move/label/archive) — not present at all; common in Trello/Linear for board cleanup.
9. **Saved/shareable filter views** — the current filter set (all/mine/overdue/soon/archived) is fixed and not combinable with the search box's state in the URL in a bookmarkable way, compounded by PRJ-005.

## 8. Proposed implementation stages

1. **i18n + icon pass** (PRJ-001, PRJ-002, PRJ-009, PRJ-010) — translate default board/column names at creation time; swap Unicode glyphs for the existing SVG icon set; collapse the duplicated add-card affordance. No schema change, no permission change — safe, high-visibility first stage.
2. **Task-panel navigation fixes** (PRJ-005, PRJ-006) — carry `q`/`filter` through the task link and close/Esc actions; make Esc use the same Turbo-aware close path as the ✕ button. Pure front-end, testable by asserting on the rendered `href`s the way the existing `TurboNavigationTest` does.
3. **Board Settings panel persistence** (PRJ-004) — stop the panel from closing itself after every edit; likely needs the panel's open state to survive the redirect via something other than the URL fragment.
4. **Drag feedback + counter fix** (PRJ-007, PRJ-011 partial) — fix the WIP-limit-suffix-eating bug (small, isolated); add a drop-zone highlight/insertion indicator (larger, needs design input).
5. **Accessibility pass on the task panel** (PRJ-008 + focus trap) — fix the duplicate `aria-label`s; add a focus trap to the modal dialog.
6. **Workload/overview view + activity-log detail surfacing** — the two biggest functional gaps (§7 items 2 and 4); larger scope, best planned as its own stage once the polish above ships.

---

*Screenshots referenced above live in `docs/audit/projects-ui/`. Test accounts
(`qa-audit`/`qa-admin`/`qa-operator`/`qa-viewer`@example.test, password `password`) and board
`[AUDIT] Demo` (id 4) were left in place for anyone who wants to reproduce a finding; all are
clearly labeled as throwaway and touch no other team's data.*
