# CLAUDE.md

Guidance for Claude Code (claude.ai/code) when working in this repository.

## What this project is

**Velora** — a multi-tenant SaaS where a business owner configures AI sales agents
that talk to their customers over Telegram, WhatsApp, Instagram and Green API, plus
a CRM to work the resulting pipeline and a self-service BI module to analyse their
own data.

Laravel 13 · PHP 8.5 · MySQL 8.4 · Blade + Alpine + Tailwind v4 · native PHPUnit.
**No frontend framework.** Analysis pages load ApexCharts on demand; the rest
of the product keeps its hand-rolled SVG charts.

It is a large, mature codebase (~200 classes, 50 migrations, ~1670 tests). It is not
a skeleton, whatever an older version of this file said.

## Commands

| Task | Command |
|---|---|
| Install | `composer install` · `npm install` |
| One-time per environment (owner-uploaded product photos) | `php artisan storage:link` — product photos live on the `public` disk; without this symlink they 404 in production even though they upload and save fine. |
| Local dev (server + 2 queue workers + Vite + Instagram service) | `composer run dev` |
| Local dev, backend only | `composer run dev:fast` |
| Queue workers only | `composer run queue` |
| **Run all tests** | `composer run test` |
| One file / one test | `composer run test -- tests/Feature/Bi/IngestionTest.php` · `composer run test -- --filter=test_name` |
| Format PHP | `php vendor/bin/pint` |
| Build / watch assets | `npm run build` · `npm run dev` |
| Queue health | `php artisan queue:health` (add `--json`) |
| Tail logs | `php artisan pail` |

**Always `composer run test`, never a bare `php artisan test`.** The former clears
the config cache first. Without that, a cached config can point the suite at the real
MySQL database, and `RefreshDatabase` runs `migrate:fresh` — which destroyed the local
`agent` database on 2026-08-21. `tests/TestCase::guardAgainstRunningOnARealDatabase()`
now refuses to run outside sqlite `:memory:`; read its docblock before changing
anything about test database setup.

## Deployment

`APP_DEBUG` must be `false` wherever this app is reachable by a customer. A debug
403/500 page renders file paths and, for a failed query, the SQL itself —
`config('app.debug')` already falls back to `false` if the env var is unset at all,
and `.env.example` ships `APP_DEBUG=false` for the same reason: that file is what a
careless deploy copies verbatim into a real `.env`. Set `APP_DEBUG=true` only in a
local `.env` that never leaves your machine.

## Architecture

### Tenancy — read this first
Teams own tenant data. The signed-in user's `current_team_id` is navigation
state; `activeTeamId()` accepts it only while membership exists. CRM, BI,
agent and conversation lists use `team_id` from that active team and return no
rows for a stale or missing membership. Resource policies use the row's own
`team_id`, so switching the menu does not change authorization for a bound
resource. `user_id` records the creator/owner for attribution and cleanup; it
is not a substitute for the team boundary.

- `PermissionCatalog` defines Owner/Admin/Operator/Viewer rights. Named Gates
  and model policies check team membership and the role's permissions. Owner's
  shortcut applies only to catalog permissions, so protected resources such
  as default CRM folders and permanent stages retain their policy invariants.
- Controllers authorize list/create operations before reading or writing, and
  validate related IDs in the resource's team. Background paths use the
  agent's team explicitly rather than the account owner's active menu.
- The tenant key is denormalized onto the row being read, rather than reached
  through a join (see the `dataset_products` and `bi_datasets` migrations for
  the reasoning): a forgotten `where` cannot leak a book, a forgotten join can.
- A solo account has a hidden personal team as its data namespace. Creating its
  first named team promotes that same row so existing agent, CRM, and BI data
  stay attached; invited members see resources through their role permissions.
- `DeletesAccount` blocks deletion when the user created an explicit team,
  sent invitations, owns a board, or has attributed rows in one. This prevents
  `user_id` cascades from deleting collaborators' data. Ownership transfer and deleting
  an explicit team remain separate future work; a member with no shared
  attribution can still delete their own account.

### Project boards and tasks
`/projects` is the main task workspace for solo accounts and teams. It uses the
existing CRM `tasks` rows as cards: customer/deal reminders keep their original
IDs and links, and legacy CRM writes land on the team's default `_inbox` board.
The CRM task navigation tab is hidden, while the old endpoints remain for inline
customer/deal actions and compatibility. A card belongs to one board and column;
the composite task→column FK prevents cross-board or cross-team placement.
`ProjectTaskWorkflow` handles ordered moves, completion time, and activity;
`Task` enforces work-in-progress limits for CRM writes and board writes alike.
Board access combines `projects.*` role permissions with
optional private-board membership; archived boards remain readable for recovery
but reject edits. The default board cannot be archived because CRM inline task
creation still needs it. The Archived filter finds cards across all columns
and lets editors restore them; other card writes are denied until restoration.

### Services (`app/Services/`)
`Channels/` messaging drivers behind `ChannelDriverInterface` · `Llm/` OpenAI +
Gemini behind `LlmProviderInterface` (provider-agnostic function calling) ·
`Rag/` embeddings + MySQL cosine vector store · `Dataset/` uploaded product catalog ·
`Database/` **live read-only customer DB access** · `Bi/` the BI module ·
`Crm/`, `Reply/`, `Quality/`, `Haggling/`, `Escalation/`, `Analytics/`.

### The two safe-SQL layers — the most important pattern here
`app/Services/Database/` and `app/Services/Bi/` both run queries against data we do
not control, and both use the **same** design. **Learn it before touching either.**

1. **No component ever accepts SQL.** `DatabaseDriver` has seven verbs, none taking
   a query string.
2. **A validated query IR, not a string.** `ProductQuery` (live DB) and the BI
   module's specs are structures whose constructors check every identifier against a
   stored schema snapshot (`database_connections.schema_columns`, `bi_columns`).
   An identifier that is not in the snapshot cannot reach SQL.
3. **Values bound, always.** The only interpolated value anywhere is a row limit,
   int-cast and range-checked first.
4. **`AbstractPdoDriver::assertSelectOnly()`** re-checks the finished string as a
   last line of defence, scanning with quoted identifiers stripped.
5. **Read-only, time-limited sessions** on the customer's server, plus
   `SafeHostGuard` (SSRF: every resolved A/AAAA record must be public).
6. **Compilers are `public` so their output is assertable in tests** without a live
   server — the SQL's shape *is* the security property. See
   `tests/Unit/Database/QueryCompilerTest.php`.

### BI module (`app/Services/Bi/`, `app/Models/Bi*`)
Upload a file → query it → build dashboards. Storage is **one typed physical table
per dataset**, `bi_ds_{id}`, materialized from NDJSON shards:

```
upload → NormalizeSourceJob   (read the file ONCE; write shards + infer schema)
       → MaterializeDatasetJob (CREATE TABLE bi_ds_{id}; chunked insert; indexes)
```

Non-obvious properties, all deliberate:
- **The original file is parsed exactly once.** Correcting a wrong type inference
  re-runs materialization from the shards — seconds, not a second .xlsx parse.
- **Runtime DDL is the only DDL in the product.** Table names must match
  `/^bi_ds_\d+$/` and column names `/^c\d+$/`, re-checked on every use
  (`BiDataset::assertSafeTableName()`, `BiColumn::safePhysicalName()`). The owner's
  header text is **never** an identifier — only a `bi_columns` row.
- **Tenancy is the FROM clause**, not a WHERE: the table name derives from the
  dataset's own primary key, so reaching another owner's rows requires holding their
  `BiDataset`, which the policy prevents. Proven in
  `tests/Feature/Bi/TenantIsolationTest.php`.
- **A column only gets a type if ≥95% of its non-blank values fit**, else it stays
  text. See `SchemaInferrer`'s docblock — this is what stops a revenue chart from
  silently omitting the rows that said "по запросу".
- **`DateFormatVoter` reports when it guessed.** `03.04.2026` is unresolvable; it
  prefers day-first and sets `date_ambiguous` so the UI can say so.
- Deleting a source/dataset/**account** must drop the tables and shards. A SQL
  cascade cannot: `UserObserver` → `BiSourceObserver` → `BiDatasetObserver` →
  `DropDatasetTableJob` is that chain. Do not bypass it.

### Queues — two pools, on purpose
`config/worker.php` defines **`default`** (customer-facing: `HandleIncomingMessageJob`,
knowledge pipeline) and **`ingest`** (BI file imports, minutes long).
`queue:supervise` runs one `queue:work` child per pool, each with its own `--queue`,
its own timeout, and its own heartbeat key via `WORKER_POOL`.

- **Never put slow work on `default`** — it is a customer waiting for a reply.
- Heartbeats are **per pool**. One shared key meant an idle worker kept clearing the
  other's "working" state, so a hung job could never be detected.
- `queue:supervise` exists because this dev environment has no Supervisor and
  `queue:work --timeout` is a no-op without pcntl.
- Base classes: `KnowledgeJob`, `Bi\BiJob` — both classify transient vs permanent
  failures and **persist a user-facing reason** in `failed()`, so nothing is left
  saying "processing" forever.

### Error handling convention
Failures carry a **classified code**, never a prebuilt sentence:
`DatabaseConnectionException::$reason`, `BiIngestionException::$reason` (named
`$reason` because `Exception::$code` already exists and shadowing it is a fatal
error). The code is stored on the row, translated through `bi.errors.{code}` /
equivalent at render time, and the technical remainder rides alongside as
`error_detail` — path-stripped, never a stack trace.

### Frontend
- **Blade + Alpine 3, no build-time framework.** Interactivity is `x-data`
  components defined inline in the view.
- **Outside analysis, charts are hand-rolled SVG**:
  `resources/views/partials/chart-helpers.blade.php` (Catmull-Rom→bézier smoothing,
  "nice ceiling" axes) and `components/area-chart.blade.php`. Read that component's
  comments before adding a chart — notably why axis labels are HTML *outside* the
  SVG (`preserveAspectRatio="none"` would stretch text) and why strokes use
  `vector-effect: non-scaling-stroke`.
- **Analysis dashboards use ApexCharts**, dynamically imported only on BI pages
  from `resources/js/bi-charts.js`. Its tooltips, stacked bars, donuts and
  resize behaviour support interactive analysis without growing the global
  bundle. Chart colours are read from generated CSS palette variables at
  runtime; theme changes update the charts in place.
- **Colour is typed in exactly one place**: `tailwind.config.js` →
  `scripts/build-palette.mjs` → `resources/css/palette.generated.css`. Never
  hardcode a hex.
- Component vocabulary in `app.css`: `.card`/`.card-pad`, `.field`, `.btn`/`.btn-primary`/
  `.btn-ghost`, `.nav-item`, `.page-header`/`.page-title`/`.page-sub`, `.tabs`/`.tab`,
  `.badge-*`, `.stat-card`, `.rule`, `.icon-circle`. Muted text is
  `text-muted dark:text-night-muted`. Full dark mode via the `.dark` variant.
- Typeface is self-hosted Onest; `@font-face` lives in `partials/fonts.blade.php`
  beside its preloads, deliberately (see that file).

### i18n — ru / tg / en
`resources/lang/{ru,tg,en}/*.php`, loaded from `resource_path('lang')`. First paint
is server-side; the locale switcher then swaps `[data-i18n]`, `[data-i18n-ph]` and
`[data-i18n-al]` in place via `partials/i18n-bundle.blade.php` (a view passes
`$i18nNamespaces`). **Russian is the primary locale.** Keys must exist in all three
files — `MalformedFileTest` asserts parity for error codes, and it is worth doing the
same for any new namespace.

### Routes
`routes/web.php` — everything behind `['auth', 'auth.session']`. Sections: `/dashboard`,
`/agents/{agent}/…` (knowledge, catalog, database, channels, playground), `/crm`,
`/bi`, `/conversations`, `/leads`, `/settings`. Locale-prefixed public pages are
registered **last** so `{locale}` cannot shadow static single-segment routes.
`webhooks.php` and `internal.php` are separate and unauthenticated by design.

Conventions: declare static segments (`poll`, `status`, `search`) **before**
`{model}` wildcards; `->scopeBindings()` on nested resources; throttle anything that
dials out, costs an LLM call, or stages a large file (`database-test`, `bi-upload`).

## Testing

Native PHPUnit (not Pest), suites `tests/Unit` and `tests/Feature`, both extending
`tests/TestCase.php`. `phpunit.xml` forces sqlite `:memory:`, array cache/session,
sync queue, array mailer, and `AGENT_DB_ALLOW_PRIVATE_HOSTS=false` (so the suite
tests shipped behaviour, not the developer's `.env`).

Helpers in `tests/Support/`: `FakeDatabaseDriver`, `PixelShopCatalog`,
`BuildsBiFixtures` (writes **real** xlsx/xls/ods/csv/json fixtures — a date cell
needs a number format or it round-trips as an integer), `DropsAnalyticalTables`
(runtime `bi_ds_*` tables are invisible to `RefreshDatabase`).

What the suite is expected to cover, and where the bar is:
- **Tenant isolation proven by test**, not asserted in a comment.
- **Compiled SQL asserted as a string**, per engine.
- **Malformed input for every reader**, each mapping to a classified code whose
  translation exists in all three locales.
- **Query budgets** — `tests/Feature/Perf/` fails a page that grows an N+1.

## House style

**Comments are short Russian notes: what the code does and where it is used, 1-2
lines, plain and direct — as if the owner wrote them for themselves.** Add one above
every class and every non-trivial function/method that has none; skip trivial
getters/setters/constructors.

The thing that most distinguishes this codebase: **comments explain WHY, including
what was tried and rejected, and what a decision costs.** Preserve that fact in a
single line starting with "Почему так:" — a rejected alternative, an incident, a
security property, a cost. A comment restating the code is worse than none; a
comment recording why a tempting simpler approach is wrong is the most valuable
thing in the file. (Example: `tests/TestCase::guardAgainstRunningOnARealDatabase()`
exists because bare `php artisan test` once wiped the local database — that fact
must survive any rewrite.)

Blade views carry substantial design rationale in `{{-- --}}` blocks — read them
before restyling; keep the "Почему так:" facts even when trimming the prose around
them. Honest over convenient: prefer telling the owner what went wrong and what it
cost (rows skipped, values rejected, a format assumed) over quietly producing
something that looks fine.
