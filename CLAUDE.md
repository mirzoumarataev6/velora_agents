# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

This is a stock Laravel 13 skeleton application (PHP ^8.3) — nothing has been customized yet beyond the framework defaults. There are no custom routes, controllers, models, or views beyond the `welcome` page and the default `User` model. Treat this as a greenfield app: architectural decisions (directory conventions beyond Laravel defaults, API structure, frontend approach) have not been made yet.

Frontend tooling is Vite + Tailwind CSS v4 (`@tailwindcss/vite` plugin) with plain JS (`resources/js/app.js`), no framework (React/Vue) currently wired in.

## Commands

**PHP dependencies:** `composer install`
**JS dependencies:** `npm install`

**Local dev (server + queue listener + Vite, concurrently):**
```
composer run dev
```

**Run the app only:** `php artisan serve`

**Build frontend assets:** `npm run build`
**Frontend dev server (hot reload) only:** `npm run dev`

**Run all tests:**
```
composer run test
```
This clears cached config first, then runs `php artisan test`. Equivalent to `php artisan test` / `vendor/bin/phpunit` directly.

**Run a single test file:**
```
php artisan test tests/Feature/ExampleTest.php
```

**Run a single test by name:**
```
php artisan test --filter=test_method_name
```

**Lint / format PHP (Pint):**
```
vendor/bin/pint
```

**Tinker REPL:** `php artisan tinker`

**Tail logs (Pail):** `php artisan pail`

## Architecture notes

- Standard Laravel structure: `app/Http/Controllers`, `app/Models`, `app/Providers` (only `AppServiceProvider` registered so far), `routes/web.php`, `routes/console.php`.
- Test suite uses native PHPUnit (not Pest) with two suites: `tests/Unit` and `tests/Feature`, both extending `tests/TestCase.php`.
- Testing environment (`phpunit.xml`) forces SQLite in-memory DB, array cache/session, sync queue, and array mailer — tests never touch the real MySQL dev database.
- Local `.env` is configured for MySQL (`DB_DATABASE=agent`) with database-backed session/cache/queue drivers — a real MySQL server and a `agent` database must exist for local (non-test) usage.
- Autoloading (PSR-4): `App\` → `app/`, `Database\Factories\` → `database/factories/`, `Database\Seeders\` → `database/seeders/`, `Tests\` → `tests/`.
