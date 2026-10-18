# Projects hardening — progress

Branch: `codex/projects-hardening` (pushed, not merged). Each item below is
one commit; `composer run test` is green after every commit (full suite,
~2570 tests as of the last one).

## Done — Phase 1 (P0)

| # | Item | Commit | Test(s) |
|---|---|---|---|
| 1 | Deadlock on concurrent moves | `ee6f34b` | `tests/Feature/Projects/ProjectTaskMoveDeadlockTest.php` |
| 2 | WIP limit bypass on concurrent create | **Already fixed** (commits `b023aff`, `b6bdff5`, pre-existing on this branch's base) — audited, not re-touched. See `tests/Unit/Projects/TaskWipLimitLockGuardTest.php` and `TaskWipLimitOccupancyLockTest.php`. No new commit. |
| 3 | Blank/duplicate deal & customer options in task panel | `d4163c2` | `tests/Feature/Crm/DealTitleDefaultTest.php`, `tests/Feature/Projects/ProjectTaskCrmLinkPickerTest.php` |
| 4 | `due_at` out-of-range (MySQL TIMESTAMP) causing 500 | `9e2a4b7` | `tests/Feature/Projects/ProjectTaskDueAtRangeTest.php`, `tests/Feature/Crm/TaskTest.php` (appended) |
| 5 | Mobile sidebar permanently open | `8293a47` | `tests/Feature/Frontend/MobileSidebarAlpineBindingTest.php` — root cause (Alpine's `start()` never walks a `data-turbo-permanent` element with no `x-data` ancestor) found and confirmed with a real headless browser, not guessed. |
| 6 | Debug leak in error responses | `30b92f4` | `tests/Feature/Projects/ProjectErrorResponseDebugLeakTest.php` |

Phase 1 pushed and verified in a real browser (items 5 and 8 specifically;
see their commit messages for what was actually observed).

## Done — Phase 2 (P1)

| # | Item | Commit | Test(s) |
|---|---|---|---|
| 7 | Activity log shows only "updated the task" | `06c5084` | `tests/Feature/Projects/ProjectActivityLogDetailsTest.php` |
| 8 | Task panel accessibility (focus trap, checklist toggle names) | `2001fa3` | `tests/Feature/Projects/ProjectTaskPanelAccessibilityTest.php` — focus-trap/return-to-card behavior verified live in a real browser (bug found and fixed in the process: `turbo:before-visit` was wiping the saved focus-return id on the closing navigation itself). |
| 9 | Validation messages: raw field names / English fallbacks | `fa95f2e` | `tests/Unit/ValidationTranslationParityTest.php`, `tests/Feature/Projects/ProjectValidationAttributeNamesTest.php` — also filled in missing rule templates (`exists`, `distinct`, `date`, `after`, `before`, `prohibited`) that the attribute-name fix alone didn't cover. |
| 10 | `?q[]=a` (array in query params) → 500 | `ebb7f79` | `tests/Feature/Projects/ProjectBoardMalformedQueryTest.php` |
| 11 | Duplicate label/column names | `f98f4e8` | `tests/Feature/Projects/ProjectDuplicateNamesTest.php` — validation only, not a DB unique index (this dev DB already has a real duplicate-label row; see the commit for why a migration isn't safe to ship as-is). |
| 12 | Drag disabled board-wide once any column passes 50 cards | `54b67d9` | `tests/Feature/Projects/ProjectBoardDragPaginationTest.php` |
| 13 | Page weight (i18n bundle ~380KB/page) | `b659191` | `tests/Feature/Frontend/I18nBundleSizeTest.php` — measured before/after: `/projects` 441,834 → 134,046 bytes (−307,788, same visible content, no behaviour change). |
| 14 | Column counter loses "/ limit" after a drop | `abb71ae` | `tests/Feature/Projects/ProjectColumnCounterMarkupTest.php` — fix verified live in a real browser. |
| 15 | Drag feedback (target-column highlight, insertion indicator) | `ba2651e` | `tests/Feature/Projects/ProjectBoardDragFeedbackTest.php` — verified live in a real browser; reduced-motion respected, tokens only (`--color-brand`, not a hardcoded hex). |
| 16 | Unsaved-edits warning on task panel close | `86b5793` | `tests/Feature/Projects/ProjectTaskPanelUnsavedChangesTest.php` — picked `confirm()` over autosave-on-blur (see the WHY comment in task-panel.blade.php); verified live in a real browser (no-edits close, edit+Cancel stays open, edit+OK closes). |

**Phase 2 is complete and pushed.**

## Not started — Phase 3 (P2)

Phase 2 is now fully green and pushed, so Phase 3 can start next.

- 17. Projects index: duplicated "Boards" eyebrow/heading; board cards show open/overdue/member count
- 18. Card meta text 11px → 12px minimum; checklist progress bar + x/y
- 19. Task panel: assignees list nested scroll box; dark-theme border token
- 20. Team page: garbled date string; invite form default role
- 21. Reject zero-width/invisible characters in names

## Notes for whoever picks this up

- Always `composer run test`, never bare `php artisan test` (see CLAUDE.md —
  a cached config can point the suite at the real MySQL dev database).
- The real dev database (`agent`, local MySQL) already has one duplicate
  label name on one board — relevant context for item 11 if it's ever
  revisited, and for any future unique-index migration on
  `project_labels`/`project_columns`.
- Items 5, 8, 13, 14, 15 and 16 were verified against a live
  `php artisan serve` + Playwright (headless Chromium), not just PHPUnit —
  PHPUnit alone can't execute the JS/Alpine behavior those items are about.
  A throwaway QA user (`qa-sidebar-check@example.test`) was created and
  deleted each time for this; none should remain in the dev database.
- `resources/js/app.js`, `resources/css/app.css` and `public/build/*` were
  rebuilt (`npm run build`) during manual verification for items 5, 8, 14
  and 15 — the committed source is current; if `public/build` looks stale
  when picking this up, just `npm run build` again before testing live.
