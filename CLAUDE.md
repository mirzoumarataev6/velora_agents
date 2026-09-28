# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

This is **not** a skeleton app — it's a working product ("Velora Agents" per the landing page): a SaaS platform where a business owner configures an AI customer-service agent (persona, knowledge base, product catalog, pricing rules) and connects it to Telegram, WhatsApp, and Instagram. Incoming customer messages are answered automatically via a RAG pipeline over the owner's own knowledge base and product data, with escalation to a human when needed.

PHP ^8.3 / Laravel ^13.8. Frontend is Vite + Tailwind CSS v4 (`@tailwindcss/vite`). Locales: `ru`, `en`, `tg` (`resources/lang/{ru,en,tg}`), Russian and Tajik are first-class throughout (see `resources/views/partials/fonts.blade.php`).

**The frontend is mid-migration to Vue 3 + Inertia.js.** Phases (a)–(d) are done, and (e) is **in progress**. Tokens, the Inertia shell, a 24-component library reviewable at `/design-system`, the dashboard, and — from phase (e) — the agents list, the agent form, leads and settings. Read `docs/DESIGN.md` before touching anything visual.

### Where the frontend stands

Two stacks are live at once, and each page belongs to exactly one:

| | Blade + Alpine (legacy) | Vue 3 + Inertia (migrated) |
|---|---|---|
| Entry | `resources/js/app.js` | `resources/js/inertia.js` |
| Shell | `layouts/dashboard`, `layouts/auth` | `resources/views/app.blade.php` |
| Pages | agent detail (7 tabs), inbox, landing | auth (4), dashboard, agents list + form, leads, settings, `/design-system` |
| UI | hand-written Blade markup | `resources/js/Components/ui/` (24 components) |
| i18n | `partials/i18n-bundle` (all 3 locales, `[data-i18n]` swap) | `translations` prop, current locale only |

- Vue lives in `resources/js/{Pages,Layouts,Components}` — **capitalised**, and `config/inertia.php` points at `js/Pages` because Inertia's default is lowercase and silently finds nothing on a case-sensitive filesystem.
- Ziggy's JS is aliased to the **Composer** package in `vite.config.js`, so the route helper can never drift from the PHP that generates the route list. Do not `npm install ziggy-js`.
- `HandleInertiaRequests` is appended **last** in `bootstrap/app.php`. That position is load-bearing in both directions — see the comment there before reordering middleware.
- **Charts** go through `Components/ui/ApexChart.vue`, which reads colours from CSS tokens and re-renders on theme/stage change. It drives ApexCharts directly — do not reinstall `vue3-apexcharts`, which vendors a second copy of the library. See `docs/DESIGN.md` §7.
- The dashboard's live panel polls `conversations.list` only. **Never `conversations.show`** — it stamps `operator_read_at`, so a polling dashboard would silently mark every conversation read.
- **Build UI from `Components/ui/`**, never fresh markup — and review a change at `/design-system`, which shows every component in light, dark and stage at once.
- Tests for migrated pages use `assertInertia`, not `assertSee`: Inertia sends props as JSON and escapes Cyrillic to `\uXXXX`, so `assertSee('Панель')` fails while an ASCII assertion passes accidentally.

There is also a small standalone Python service, `instagram_service/` (FastAPI + instagrapi), for Instagram DM polling — see "Channel drivers" below.

## Design system — read `docs/DESIGN.md` first

"Velora Midnight". Authored in exactly one file, `resources/css/tokens.css`: raw
swatches on `:root`/`.dark`/`[data-mode="stage"]`, mapped to semantic roles via
`@theme inline`. The full rules, palette and rationale are in `docs/DESIGN.md`.

The short version, for anyone editing a view:

- **Use semantic tokens only** — `bg-surface`, `text-muted`, `bg-primary`,
  `border-border`. Never a raw `--v-*` token and **never a hex**. A hardcoded
  colour fails `tests/Feature/BrandConsistencyTest.php`, which renders all nine
  real surfaces and checks them against every retired palette.
- **This product is judged on a projector in a lit hall.** Contrast is a
  specification, not a preference: body ≥ 12:1, secondary ≥ 7:1, non-text UI
  ≥ 3:1 — all enforced by that same test. No glassmorphism, no glow, no weight
  below 400, no text under 13px, and status is never signalled by colour alone.
- **`#38BDF8` is reserved for AI moments** — agent suggestions, AI insights, the
  sparkle. As text on a light ground use `--color-sparkle-ink` instead.
- **Legacy aliases** (`brand`, `paper`, `ink`, `night-*`, `ember-*`, `sand-*`)
  exist at the end of `tokens.css` so unmigrated Blade views re-skin untouched.
  Do not use them in new markup; they are deleted in phase (f).
- **Fonts: Onest 2.001 + a numerals-only cut of Inter**, self-hosted in
  `public/fonts`. Onest MUST come from the GitHub release, never a CDN — Bunny's
  subsets have no Tajik glyphs. After any font change run `npm run fonts:verify`,
  which opens the shipped woff2 and checks all six Tajik pairs per glyph.
- **Stage mode** is `data-mode="stage"` on `<html>`, persisted in `localStorage`
  (never the account — it is a property of the room, not the person).

## Architecture

### Channel drivers

All four channels implement `App\Services\Channels\ChannelDriverInterface` and are resolved via `ChannelDriverManager`:

- **`TelegramDriver`** — Bot API, standard webhook.
- **`WhatsAppDriver` / `InstagramDriver`** — official Meta Graph API, share webhook verification (`VerifiesMetaWebhook`) and are both driven through `MetaWebhookController` (the `type` route parameter picks whatsapp vs instagram).
- **`GreenApiDriver`** — unofficial WhatsApp via the Green API provider, its own webhook controller (`GreenApiWebhookController`), no signature header (checks `idInstance` in the payload instead).
- **`InstagrapiDriver`** — unofficial Instagram DMs via `instagrapi` (a private-API client), driven by the Python microservice in `instagram_service/`, which polls Instagram and POSTs new DMs to `POST /internal/instagram/incoming` (`InstagramIncomingController`, restricted to localhost — see `routes/internal.php`). This channel has no public webhook route and no 24-hour messaging-window rule (that's a Graph API rule).

Shared behavior lives in traits/concerns: `ChecksMessagingWindow` (Meta's 24h customer-service window), `SplitsLongMessages`, `HasNoMessageFeedback`, `DescribesImagesWithConfidence`, `OutboundMarkdown` (Markdown → each platform's formatting).

### Message-handling pipeline

Every inbound webhook (Telegram/Meta/Green API) validates and enqueues only, then returns 200 immediately; `App\Jobs\HandleIncomingMessageJob` does the real work:

1. Claim the update (dedup redelivered webhooks — `claimUpdate()`).
2. Route feedback taps (👍/👎) separately — never treated as a message.
3. Parse the incoming message via the driver; handle the Telegram owner-notification-linking command (`/start notify_<code>`) as a side case.
4. Idempotently find-or-create the `Conversation` and store the `Message` (unique DB constraints back this up, not just in-job checks).
5. Skip auto-reply if `human_takeover` or the agent is paused.
6. Otherwise call `AgentReplyService` to generate a reply (RAG + tools), then send it back through the same driver, optionally asking for a 👍/👎 rating (`FeedbackCadence`).

`AgentReplyService` (`app/Services/AgentReplyService.php`) is the core: embeds the question, retrieves relevant knowledge chunks (`VectorStoreInterface` / `MySqlCosineVectorStore`), can call a `search_products` tool (product Q&A detection via `Rag/ProductQuestionDetector`), builds the system prompt, calls the configured LLM provider, and persists both sides with retrieval/timing/usage metadata. It also handles voice messages (`Services/Voice/VoiceTranscriber`), non-answer/closing-message quality checks (`Services/Quality/*`), language detection and locale-matched replies (`Services/Reply/*`), lead capture, escalation, and price-haggling.

### Knowledge base / RAG pipeline

Uploaded/URL/text knowledge sources go through a job chain, each a `KnowledgeJob` subclass with shared retry/backoff/failure handling: `ExtractTextJob` → `ChunkTextJob` → `EmbedChunksJob`. Extraction supports files (PDF/Word/etc. via `smalot/pdfparser`, `phpoffice/*`), URLs (`Services/Knowledge/SafeUrlGuard`, `XmlCatalogExtractor`), and plain text. Chunking is `Services/Knowledge/ChunkerService`; embeddings go through `Services/Llm/{OpenAiProvider,GeminiProvider}` (config: `LLM_PROVIDER` in `config/agent.php`) and are stored per `KnowledgeChunk`, tagged with the embedding model so switching providers doesn't silently compare incompatible vectors (`php artisan embeddings:reindex` after changing `LLM_PROVIDER`). A queue watchdog/heartbeat system (`config/worker.php`, `QueueWatchdogCommand`, `QueueSuperviseCommand`) detects stuck knowledge sources and stale workers — there's no Supervisor/systemd in local dev.

### Dataset / product catalog

Separate from the knowledge base: this is a structured, row-per-product catalog so the agent can filter by price/stock exactly rather than doing semantic retrieval. Two mutually-exclusive sources per agent (`Agent::usesLiveDatabase()` / product_source):

- **Uploaded dataset** — `Services/Dataset/{DatasetFileReader,ColumnMapper,DatasetImporter,ValueParser,Transliterator}` import a spreadsheet/CSV into `DatasetProduct` rows; `ProductSearchService`/`DatasetProductSource` search and present them (`NormalizesSearchTerms`, `PresentsProducts`).
- **Live database connection** — `Services/Database/*` (`MySqlDriver`, `PostgresDriver`, schema `Discovery`, `SafeHostGuard` against SSRF/port-scanning) lets an owner point the agent at their own MySQL/Postgres product table instead of uploading a file (`LiveProductSource`).

### Analytics, quality, haggling, leads

- `Services/Analytics/DashboardMetrics` powers the dashboard: message volume, response times, funnel, ratings, per-agent comparison, after-hours/heatmap, timezone-aware (Asia/Dushanbe in the demo data).
- `Services/Quality/*` — closing-message detection, non-answer detection, feedback cadence (when to ask for a 👍/👎 rating, cap on how often).
- `Services/Haggling/{HagglingService,HaggleDecisionRelay}` — optional price-negotiation behavior; the agent can propose a discount down to a configured floor, and owner price-approval decisions go through `LeadController::approvePrice/declinePrice`, not a generic status update.
- `Services/Leads/*`, `LeadCaptureService`, `EscalationService` (+ `Services/Escalation/HostilityDetector`) — turning a conversation into a `Lead`, detecting hostile customers or messages that need a human, and notifying the owner (`SendLeadNotificationJob`, `SendEscalationNotificationJob`, Telegram owner-linking via `TelegramNotifyLinker`).

### Demo seeder

`database/seeders/DemoSeeder.php` + `database/seeders/data/demo.php` (data-only, ~1100 lines) rebuild a fixed set of presentation agents (owned by slug, so re-running is safe and never touches real agents/customers) via `php artisan db:demo`. Used for demos/screenshots, not for tests (tests use factories and an in-memory SQLite DB).

## Commands

**PHP dependencies:** `composer install`
**JS dependencies:** `npm install`

**Local dev (server + queue supervisor + Vite + Instagram polling service, concurrently):**
```
composer run dev
```
(`composer run dev:fast` skips Vite/Instagram and runs an optimized server+queue only.)

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

**Verify font coverage (all six Tajik pairs, per glyph):** `npm run fonts:verify`
**Regenerate the landing's token partial from tokens.css:** `npm run palette`

**Rebuild demo agents:** `php artisan db:demo`
**Reindex embeddings after changing LLM_PROVIDER:** `php artisan embeddings:reindex`

## Testing / environment notes

- Test suite uses native PHPUnit (not Pest) with two suites: `tests/Unit` and `tests/Feature`, both extending `tests/TestCase.php`. 1300+ feature/unit tests.
- Testing environment (`phpunit.xml`) forces SQLite in-memory DB, array cache/session, sync queue, and array mailer — tests never touch the real MySQL dev database.
- Local `.env` is configured for MySQL (`DB_DATABASE=agent`) with database-backed session/cache/queue drivers — a real MySQL server and an `agent` database must exist for local (non-test) usage.
- Autoloading (PSR-4): `App\` → `app/`, `Database\Factories\` → `database/factories/`, `Database\Seeders\` → `database/seeders/`, `Tests\` → `tests/`.
- Comment style in `app/`: most methods carry a single terse Russian one-line comment (`// Возвращает ...`) rather than English docblocks — match that style when adding new methods, unless the surrounding file already uses longer English "why" comments (some files, e.g. `vite.config.js`, `fonts.blade.php`, do — match whichever style the file you're editing already uses).
