# Comments → Russian: progress log

Branch: `chore/russian-comments`. One commit per area. No behaviour changes —
every commit must pass `scripts/verify-comments-only.php` for every file it touches.

## Areas

| # | Area | Status | Commit |
|---|---|---|---|
| 1 | Setup (verify script, progress log) | done | (this commit) |
| 2 | `app/Services/` | pending | |
| 3 | `app/Http/` + `app/Models/` + `app/Jobs/` + `app/Policies/` + rest of `app/` | pending | |
| 4 | `database/` + `routes/` + `config/` | pending | |
| 5 | `resources/views/` (Blade) | pending | |
| 6 | `resources/js/` + `resources/css/` + `scripts/` + tailwind/vite configs | pending | |
| 7 | `CLAUDE.md` house-style update | pending | |

## Scope recap

In: app/, routes/, config/, database/, resources/views (Blade `{{-- --}}` and
HTML `<!-- -->`), resources/js, resources/css, scripts/, tailwind/vite configs.
Out: tests/, vendor/, node_modules/, public/build, *.generated.*, lang file
VALUES (comments inside lang files ARE in scope), ngrok.exe.

## Rules each subagent must follow

- Russian, 1-2 lines, plain, "what + where". No essays.
- Add a short comment above every class and non-trivial function/method that
  has none. Skip trivial getters/setters/constructors.
- Preserve WHY facts: an incident, a rejected alternative, a security
  property, a cost — compress into one sentence starting with "Почему так:".
  The fact that bare `php artisan test` once wiped the local database MUST
  survive somewhere reachable from TestCase/testing docs.
- Keep existing Russian comments as-is.
- PHPDoc: keep every @param/@return/@var/@use/@throws tag and type exactly;
  translate/drop only prose description.
- Never touch: @phpstan-*, @psalm-*, phpcs/pint ignores, eslint/prettier
  directives, @vite, @use HasFactory<...>, license headers, shebangs.
- Never change strings, identifiers, whitespace of code, or execution order.
  Only comment text/lines may change.
- Before committing: run `php scripts/verify-comments-only.php <file> --base=main`
  (or `--base=chore/russian-comments` if based on a later point) on every
  touched file. Any mismatch → revert that file, redo it.
- Put verify output in the commit message.

## Notes / anything left alone

(filled in as areas complete)
