# Projects hardening — final report

Branch: `codex/projects-hardening`, pushed, **not merged**. All 21 items are
done. Each item is one commit; `composer run test` is green after every
commit (full suite, 2605 tests as of the last one).

## Final report: item → commit → test → what I saw in the browser

| # | Item | Commit | Test(s) | Browser verification |
|---|---|---|---|---|
| 1 | Deadlock on concurrent moves | `ee6f34b` | `ProjectTaskMoveDeadlockTest` | Not browser-testable (requires two genuinely simultaneous MySQL connections; this suite runs on sqlite). Proven instead via source-level lock-ordering assertion + a functional swap test. |
| 2 | WIP limit bypass on concurrent create | **Already fixed**, no commit | n/a | Audited `Task::assertColumnHasRoom()` — already locks the column row and the occupancy count (commits `b023aff`, `b6bdff5`, pre-existing on this branch's base). Did not re-touch; see `tests/Unit/Projects/TaskWipLimitLockGuardTest.php` / `TaskWipLimitOccupancyLockTest.php`. |
| 3 | Blank/duplicate deal & customer options | `d4163c2` | `DealTitleDefaultTest`, `ProjectTaskCrmLinkPickerTest` | Not separately screenshotted; covered by server-rendered-content assertions (label text, `data-customer` attributes). |
| 4 | `due_at` out of MySQL TIMESTAMP range → 500 | `9e2a4b7` | `ProjectTaskDueAtRangeTest`, `TaskTest` | Reproduced the 500 directly before fixing (`9999-12-31`, `1900-01-01`); confirmed a validation error after. |
| 5 | Mobile sidebar permanently open | `8293a47` | `MobileSidebarAlpineBindingTest` | **Yes.** Headless Chromium at 375px: before the fix, the sidebar covered the content after a hard reload and after a Turbo visit (`rectX: 0`, no `-translate-x-full`). After: closed by default (`rectX: -256`) on fresh nav, hard reload, and after a Turbo visit; opens via the burger, closes via the scrim. Root cause (Alpine's `start()` never walks a `data-turbo-permanent` element with no `x-data` ancestor) confirmed by manually calling `Alpine.initTree()` on the stuck node and watching it bind correctly. |
| 6 | Debug leak in error responses | `30b92f4` | `ProjectErrorResponseDebugLeakTest` | Not browser-tested; a 403 JSON/HTML body was asserted to carry no `file`/`trace`/`exception` keys or `base_path()`/SQL text with `app.debug` forced false. |
| 7 | Activity log shows only "updated the task" | `06c5084` | `ProjectActivityLogDetailsTest` | Not separately screenshotted; covered by content assertions for each change type (fields, assignees, labels, move). |
| 8 | Task panel accessibility | `2001fa3` | `ProjectTaskPanelAccessibilityTest` | **Yes.** Focus lands on the close button on open; Tab wraps at the dialog's own boundary without escaping to the board behind it; closing via Escape, the X, and the backdrop all correctly return focus to the card that opened it. Found and fixed a real bug in the process: `turbo:before-visit` was unconditionally wiping the saved focus-return id on the *closing* navigation itself, before `turbo:load` ever got to consume it. |
| 9 | Validation: raw field names / English fallbacks | `fa95f2e` | `ValidationTranslationParityTest`, `ProjectValidationAttributeNamesTest` | Not browser-tested; confirmed via HTTP response assertions in Russian locale. Also found and fixed a second gap the attribute-name fix alone didn't cover: `exists`/`distinct`/`date`/`after`/`before`/`prohibited` had no rule-message translation in *any* locale. |
| 10 | `?q[]=a` → 500 | `ebb7f79` | `ProjectBoardMalformedQueryTest` | Reproduced the 500 directly before fixing; confirmed 200 after, for `q`, `filter`, and `column_{id}` as arrays. |
| 11 | Duplicate label/column names | `f98f4e8` | `ProjectDuplicateNamesTest` | Not browser-tested. Validation only, not a DB unique index — this dev database already has a real duplicate-label row (audited via `LOWER(name)` grouping before writing the fix), so a migration would fail outright on existing data. |
| 12 | Drag disabled board-wide past 50 cards | `54b67d9` | `ProjectBoardDragPaginationTest` | Not browser-tested (the drag-disable logic itself is server-rendered markup); confirmed via `draggable="true"`/`data-has-more-pages` assertions on a 60-card column. |
| 13 | Page weight (~380KB/page i18n bundle) | `b659191` | `I18nBundleSizeTest` | Measured directly against a live server: `/projects` 441,834 → 134,046 bytes (−307,788), `/dashboard` and `/crm` saw the identical drop, `/agents` unchanged. Same visible content, confirmed by loading `/projects` and `/agents` and comparing. |
| 14 | Column counter loses "/ limit" after a drop | `abb71ae` | `ProjectColumnCounterMarkupTest` | **Yes.** Simulated a real drop via Alpine: a column showing "3 / 10" read "2 / 10" after a card left it, not "2". |
| 15 | Drag feedback (highlight, insertion line) | `ba2651e` | `ProjectBoardDragFeedbackTest` | **Yes.** Screenshotted mid-drag: a visible insertion line above the target card; confirmed via computed styles that the target column's background/border actually change colour (`oklab(...)` before vs. after), not just that the CSS class toggles. Confirmed the no-flicker-between-cards case and the leaves-the-column-entirely case both behave correctly. |
| 16 | Unsaved-edits warning on panel close | `86b5793` | `ProjectTaskPanelUnsavedChangesTest` | **Yes.** Closing with no edits navigates straight through, no prompt. Editing the title then pressing Esc shows the native `confirm()` with the correct Russian message; Cancel keeps the panel open with the edit still in the field; OK closes it. |
| 17 | Projects index: duplicated heading; board cards lack overdue/member count | `debd9a0` | `ProjectIndexCardStatsTest` | **Yes.** Screenshotted: "Доски" heading appears once; a card reads "Активные 2 · Просрочено 1 (red) · Участники 1". |
| 18 | Card meta text 11px; checklist progress text-only | `d56befe` | `ProjectCardChecklistBarTest` | **Yes.** Close-up screenshot of a card: legible 12px meta row, a blue-filled progress bar next to "2/3" for a 2-of-3-done checklist. |
| 19 | Assignees list nested scroll box; light dark-mode border | `efbc34f` | `ProjectTaskPanelAssigneesStylingTest` | **Yes.** Dark-theme screenshot of the assignees box: border now visibly distinct against the dark card surface (previously would have been nearly invisible — same token as the surface it sits on). |
| 20 | Garbled invitation date; invite form defaults to Owner | `98aaabf` | `TeamInvitePageTest` | **Yes.** Reproduced the garbled date directly against Carbon before fixing (`now()->translatedFormat('d MMM Y')` → `"18 октябряоктября10 2026"`). Screenshotted the team page after: "Истекает 23 октября 2026" (clean), and the invite role `<select>` defaulting to "Наблюдатель" (Viewer), confirmed via the DOM's `option:checked`. |
| 21 | Zero-width/invisible characters in names | `0a07247` | `InvisibleCharactersTest`, `ProjectInvisibleCharactersTest` | Not browser-tested (a paste-based repro has no reliable headless-Chromium equivalent); covered by direct unit tests against the stripping function (11 invisible-character variants) and feature tests against all 7 write paths (task/board/column/label, create and update). |

## Found but explicitly out of scope — not fixed

While fixing item 20's garbled-date bug, grepped for the same broken
`translatedFormat('d MMM...')` pattern elsewhere and found it in **four more
places**, none of them under Projects:

- `resources/views/emails/team-invitation.blade.php:22` — `'d MMMM Y'`
- `app/Services/Crm/DealTrend.php:119` — `'MMM Y'` and `'d MMM'` (two bugs on one line)
- `app/Services/Crm/DealBoard.php:229` and `:237` — `'d MMM Y'`

Same bug, same fix (`'d F Y'` / `'d MMMM'` → `'d F'`), but these are CRM
code, outside this pass's stated scope (Projects hardening). Left alone;
flagging here rather than silently expanding scope.

## Notes for whoever picks this up

- Always `composer run test`, never bare `php artisan test` (see CLAUDE.md —
  a cached config can point the suite at the real MySQL dev database).
- The real dev database (`agent`, local MySQL) already has one duplicate
  label name on one board — relevant context for item 11 if it's ever
  revisited, and for any future unique-index migration on
  `project_labels`/`project_columns`.
- Items 5, 8, 13, 14, 15, 17, 18, 19 and 20 were verified against a live
  `php artisan serve` + Playwright (headless Chromium), not just PHPUnit —
  PHPUnit alone can't execute the JS/Alpine behavior most of those items are
  about. A throwaway QA user (`qa-sidebar-check@example.test`) was created
  and deleted each time for this; none should remain in the dev database.
- `resources/js/app.js`, `resources/css/app.css` and `public/build/*` were
  rebuilt (`npm run build`) during manual verification for items 5, 8, 14,
  15, 17, 18 and 19 — the committed source is current; if `public/build`
  looks stale when picking this up, just `npm run build` again before
  testing live.
- The branch is pushed but **not merged**, per the original instructions.
