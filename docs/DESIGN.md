# Velora Midnight — the design system

The design system for Velora Agents. Everything here is enforced somewhere: by
`resources/css/tokens.css`, by `tests/Feature/BrandConsistencyTest.php`, or by
`scripts/verify-font-coverage.mjs`. If a rule below has no enforcement, it says
so.

**The one-line version:** this product is judged on a projector in a lit hall,
so clarity and contrast beat decoration every time.

---

## 1. The constraint that explains everything

Most design systems are tuned for a laptop at arm's length. This one is tuned
for a **projector in a lit room, seen from the back**. That single constraint
produces nearly every rule that follows, and it is why a change that "looks
nicer on my screen" is often wrong here:

| A projector does this | So the system does this |
| --- | --- |
| Raises black level, crushes the dark end | No pure-black ground; depth via borders, not dark shades |
| Washes out low-contrast detail | Body ≥ 12:1, secondary ≥ 7:1 — far above WCAG AA |
| Destroys 1px hairlines | Stage mode doubles borders to 2px and darkens them |
| Turns blur and transparency to grey mush | No glassmorphism. Anywhere. |
| Loses thin strokes | Nothing below weight 400; icon strokes 1.75–2px |
| Has a marginal frame rate | Motion 150–200ms, or none |

### Contrast floors (enforced)

| Role | Floor | Where |
| --- | --- | --- |
| Body text | **12:1** | `contrastPairs()` in `BrandConsistencyTest` |
| Secondary / muted text | **7:1** | same |
| Non-text UI — input borders, focus rings, icon strokes, chart lines | **3:1** | same |
| Label on a filled brand button | 4.5:1 | same |

The last one is deliberately lower. A filled primary button at 12:1 would need a
near-black fill, at which point it is no longer a primary button.

### Colour is never the only signal

Status = **colour + icon + text**. Chart series = **colour + marker/dash +
direct label**. A viewer with protanopia, or a projector with a blue-shifted
bulb, must lose nothing.

---

## 2. Tokens

Authored in exactly one file: **`resources/css/tokens.css`**. Two layers.

**Layer 1 — raw swatches** on `:root`, re-declared on `:root.dark` and
`:root[data-mode="stage"]`:

```css
:root      { --v-blue-500: #1e5eff; --v-surface: #ffffff; }
:root.dark { --v-surface: #111c36; }
```

**Layer 2 — semantic roles** mapped in `@theme inline`:

```css
@theme inline {
  --color-surface: var(--v-surface);
  --color-primary: var(--v-primary);
}
```

`inline` is load-bearing. It makes the generated utility emit `var(--v-…)`
instead of baking the value in at build time, which is what lets one `.dark`
block re-point every surface in the product **without a `dark:` utility being
written anywhere**.

> ### The rule
> **Components use ONLY semantic tokens.** Never a raw `--v-*`, never a hex.
> `bg-surface`, `text-muted`, `border-border`, `bg-primary` — not `bg-[#1e5eff]`.

A hardcoded hex is caught by `test_surface_uses_the_current_brand`, which renders
all nine real surfaces and fails on any retired colour.

### Palette

**Neutrals — slate-navy.** Cool, blue-cast greys. A true neutral grey next to
this primary reads muddy; the blue cast makes them one family.

`#F4F6FB` canvas · `#EBEFF6` sunk · `#D5DCE8` border · `#B4BFD2` strong border ·
`#7C8AA0` input border · `#5B6882` placeholder · `#4A5670` · `#414C66` muted
text · `#33405A` stage muted · `#1E2A44` · `#0E1A33` ink · `#0B1636` sidebar

**Primary — electric blue.** `#1E5EFF` is the identity: fills, active nav, focus
rings. **Text and button labels use `#1748CC`**, because `#1E5EFF` as text is
5.12:1 on white — over the AA floor but under this product's 7:1 bar.

`#E8EFFF` tint · `#5B8CFF` dark-mode primary · `#1E5EFF` **primary** ·
`#1748CC` hover/text · `#123AA8` pressed

**Sidebar** is dark in *both* themes, so it has its own tokens and does not
follow the surface swap: `#0B1636` ground, `#16244A` hover, `#1E5EFF` active,
`#A9B8D6` text (8.89:1).

**Status** — each has a *text* cut (contrast-checked as type) and a *fill* cut:

| | Text | Fill | Dark |
| --- | --- | --- | --- |
| Success / money | `#0A7F5A` | `#12B886` | `#34D399` |
| Warning | `#B45309` | `#F59E0B` | `#FBBF24` |
| Danger | `#D92D20` | `#D92D20` | `#F87171` |

**Dark theme.** Never pure black, never pure white: `#0A1226` bg · `#111C36`
surface · `#172445` surface-2 · `#2A3A5E` border · `#EEF2FA` text · `#AAB6CE`
muted · `#5B8CFF` primary.

### The AI accent — sparkle

`#38BDF8` is **reserved**. It marks AI moments and nothing else: agent
suggestions, AI-insight titles, the sparkle in the logo. Using it for an
ordinary control is what would make the AI signal meaningless.

As a **fill or icon on a dark ground** it is `#38BDF8`. As **text on a light
ground** it must be `--color-sparkle-ink` (`#0369A1`, 5.93:1) — `#38BDF8` is
1.86:1 on the canvas and unreadable.

### Two deliberate deviations from the original spec

Both were measured, and in both cases the contrast rule outranked the swatch.

1. **Muted text is `#414C66`, not the specified `#4A5670`.** `#4A5670` measures
   6.80:1 on the canvas and 6.38:1 on the sunk surface — it clears 7:1 only on
   pure white, the one light ground muted text rarely sits on. `#414C66` gives
   7.93 / 8.57 / 7.43. `#4A5670` remains in the scale for non-text use.

2. **The dark input border is `#5F7299`, not the `#2A3A5E` card border.** An
   input is an interactive target and owes the 3:1 non-text floor; `#2A3A5E`
   measures 1.50:1 on a dark card. `#5F7299` gives 3.50:1.

---

## 3. Typography

**One voice plus figures.** Onest carries UI, body and headings; Inter supplies
only the numerals. 96 KB for the entire type system.

| Face | File | Size | Job |
| --- | --- | --- | --- |
| **Onest 2.001** | `public/fonts/onest-var-2.001.woff2` | 84 KB | Everything. Variable, weights 100–900. |
| **Inter 4.1** | `public/fonts/inter-numerals-4.1.woff2` | 12 KB | Numerals only. Variable, `tnum`. |

### Onest must be self-hosted from GitHub, never a CDN

This repo previously rejected Onest on evidence it had no Tajik glyphs. That
evidence was real but measured against **Bunny's subsets**. The upstream release
at `github.com/simpals/onest` carries all six Tajik pairs — Ғғ Ққ Ҳҳ Ҷҷ Ӣӣ Ӯӯ —
in every weight, verified against the shipped file's cmap.

Re-prove it, never re-assume it: `npm run fonts:verify`.

### How the numerals split works

`--font-sans` lists `'InterNum', 'Onest', …`. InterNum's `@font-face` declares a
`unicode-range` covering only digits and numeric punctuation, so the browser
tries Inter first, finds nothing outside that range, and falls through to Onest
for every letter.

So `1 250,00 сомонӣ` sets its **figures in Inter** and its **word in Onest**,
from one font stack. `U+20` (space) is deliberately excluded from the range —
letting Inter serve every space would change word spacing in Onest body copy.

> The order is load-bearing. Reverse it and Onest answers first for everything,
> the tabular figures silently stop being used, and dashboard numbers jitter as
> they update. Pinned by `test_the_numeral_face_is_ordered_ahead_of_the_ui_face`.

### Scale

Body 15–16px. **No label under 13px.** Weights: body 400, labels 500, headings
600–800 — nothing in the system is lighter than 400.

| Token | Size | Weight | Use |
| --- | --- | --- | --- |
| `text-overline` | 13px | 600 | The floor. Nothing smaller ships. |
| `text-body` | 15px | 400 | Default |
| `text-body-lg` | 16px | 400 | Long-form |
| `text-h3` | 18px | 600 | Card titles |
| `text-h1` | 26px | 700 | Page title |
| `text-kpi-sm` → `text-kpi-lg` | 32 → 44px | 700–800 | KPI figures |

---

## 4. Stage mode

A persisted toggle that stamps `data-mode="stage"` on `<html>`. Not a zoom — it
trades the finish that reads on a laptop for the weight that survives
projection:

- root font-size → **112.5%** (everything in `rem` grows)
- muted text darkened a full step (7.93:1 → 9.61:1)
- borders darkened **and doubled to 2px**
- shadows off, transparency flattened to real surfaces

Applies over either theme. Implemented as a token swap in `tokens.css` plus the
`stage:` variant; the transparency flattening is in `app.css`, because
`bg-white/10` bakes its alpha into the generated class where no token can reach
it.

**It is stored in `localStorage`, never on the account** — stage mode is a
property of the *room*, not the person. The same owner wants it on for the
projector and off at their desk twenty minutes later.

Applied **pre-paint** by `partials/stage-mode.blade.php`, included in all three
document heads. Everything it changes is layout-affecting, so applying it after
first paint would reflow the whole page in front of the audience — on the one
setting whose entire purpose is being used in front of an audience.

---

## 5. Charts

Okabe-Ito derived: distinguishable under the three common forms of colour
blindness *and* under a projector's crushed gamma.

**Light:** `#1E5EFF` `#009E73` `#D55E00` `#B86E00` `#CC79A7`, total `#0E1A33`,
previous period `#7C8AA0` **dashed**
**Dark:** `#6B93FF` `#34C79A` `#F07A2A` `#E69F00` `#E08FC0`

**Fixed channel colours, product-wide** — a customer learns Telegram is blue
once, and it must not shift between two screens:
Telegram `#1E5EFF` · WhatsApp `#009E73` · Instagram `#CC79A7`

**Income vs expense is green vs orange, never red vs green.**

Rules: max 5–6 series · lines 2.5–3px · axis labels 13–14px/500 · horizontal
gridlines only · chart title written as an **insight**, not a label ("Telegram
drove 62% of leads", not "Leads by channel") · colours read from CSS custom
properties and **re-read on theme or stage change** (`theme:changed`,
`mode:changed`).

### One known weakness

`#D55E00` and `#B86E00` (series 3 and 4) are **9.4° apart in hue** with a 0.06
lightness gap — the closest pair in the Okabe-Ito set and the hardest to
separate under protanopia. They ship because colour is never the only signal:
these two **must** carry distinct markers and direct labels.

This is documented as an explicit exemption in
`test_chart_series_are_distinguishable_from_each_other`, so that if a *second*
pair ever drifts this close, the test fails instead of quietly accepting it.

> That test measures **hue and lightness**, not contrast ratio. An earlier
> version used contrast and was wrong: it flagged blue vs amber (1.28:1, but
> 173° apart and impossible to confuse) while passing the pair that is genuinely
> a problem. Luminance is blind to hue, and hue is most of what separates a
> categorical palette.

---

## 6. The component library

`resources/js/Components/ui/` — 24 components. Review them at **`/design-system`**,
which renders each one in light, dark and stage **side by side**, because a
muted label that is fine in light and vanishes in dark is obvious in a row of
three and invisible when you have to click between them.

| Group | Components |
| --- | --- |
| Surface | `Card` `PageHeader` `EmptyState` `Skeleton` |
| Actions | `Button` `Dropdown` `DropdownItem` `Tooltip` |
| Forms | `Input` `Textarea` `Select` `Checkbox` `Switch` |
| Data | `Table` `Badge` `Avatar` `StatCard` `Sparkline` `Funnel` `ChartCard` |
| Overlay | `Modal` `Drawer` `ToastHost` `Tabs` |

### Decisions worth not re-litigating

- **`Switch` vs `Checkbox`** — a switch applies immediately; a checkbox commits
  on submit. That is the whole distinction, and it is why both exist.
- **`Select` is a native `<select>`.** A custom listbox would match the system
  more exactly and would also be the component most likely to be subtly broken
  for a keyboard or a screen reader — and on a phone it replaces the OS picker
  with something worse.
- **`Dropdown` closes via a full-screen sibling overlay**, not a document click
  listener. A listener has to guess what counts as "inside", gets it wrong the
  moment something stops propagation, and fires on every click while open.
- **`StatCard` takes `invert`** because up is not always good — response time
  rising is bad, and the card has to be told rather than assuming green-for-up.
- **`Funnel` draws bars, not a tapering trapezoid.** A trapezoid encodes value
  as area, the hardest encoding to read; length on a shared baseline is the
  easiest and survives the back of a room.
- **`ChartCard`'s title is an insight**, not a label — "Telegram drove 62% of
  leads", not "Leads by channel". The axes already say what the data is.
- **Overlays teleport to `<body>`**, so they cannot sit inside a theme island
  and always follow the page theme. That is why the gallery previews them with
  ordinary triggers rather than in a three-mode row.

### Two silent failures this phase found, both now tested

1. **`duration-*` tokens generated no CSS.** Tailwind v4 has an `--ease-*` theme
   namespace but **no `--duration-*` one** — `duration-*` takes a bare number or
   an arbitrary value only. Two dozen components referenced `duration-fast` and
   every one was inert. Fixed with real `@utility` declarations in `tokens.css`;
   guarded by `test_every_duration_token_has_a_real_utility`.

2. **Theme selectors must stay unanchored from `:root`.** `.dark`, not
   `:root.dark`. Re-anchoring breaks nothing visible in the app — it silently
   collapses all three gallery panes to one mode. Guarded by
   `test_theme_selectors_stay_scopable_to_a_nested_element`.

---

## 7. Charts, in practice

Every chart goes through `Components/ui/ApexChart.vue`, which resolves colours
from the CSS tokens **of its own subtree** and re-resolves them on
`theme:changed` / `mode:changed`. A chart in a `.dark` gallery pane draws the
dark palette; a chart already on screen recolours when the theme is toggled.

The house defaults live in `resources/js/chart-theme.js`: 2.5px strokes (3px in
stage mode), 13px axis labels at weight 500, horizontal gridlines only, and
**animations off** — Apex's 800ms entrance is long enough to be watched rather
than felt, and it replays on every filter change.

### It drives ApexCharts directly, not through `vue3-apexcharts`

That wrapper is 7 KB of glue, but its `dist/` **vendors its own 750 KB copy of
apexcharts**, so the build shipped the library twice: 136 KB + 272 KB gzipped of
the same code, on the one page a demo is judged on. Driving the imperative API
ourselves leaves one copy at 270 KB gzipped, lazily imported so only the
dashboard pays for it.

> Do not reinstall `vue3-apexcharts`. The modular entry points
> (`apexcharts/core` + per-type) are not a win either — `core.esm.js` alone is
> larger than the full build.

Colour changes **destroy and re-create** the chart; data changes call
`updateOptions`. Apex leaves the previous palette on paths it has already drawn,
so a theme switch that only updated options would recolour the axes and leave
the series behind.

---

## 8. Layout and density

Tabler's rhythm, opened one notch for projection.

- **8px spacing grid**, 12-column layout
- Card padding **16–20px**, **24px** between blocks
- Table rows **44–48px**
- Radius **12px** for cards (`--radius-xl`); controls one step below, so a button
  inside a card never looks rounder than the card
- Icons: Tabler outline, stroke **1.75–2px**
- **Surfaces are separated by a 1px border**, never by a background shade or a
  shadow alone
- **60-30-10**: 60% light neutrals, 30% navy/text, 10% primary + signals
- **At most one attention-grabbing element per screen**

### Prohibited

No glassmorphism · no neon or glow · no low-contrast gradients · no pastel-only
badges (meaning must survive in text and border) · no font weight below 400 · no
text under 13px · no colour-only status.

`.glass` and `.glow-warm` still exist as classes — neutralised, not deleted, so
unmigrated views pick up the replacement instead of needing a rewrite. They go
with the last Blade view.

### Motion

150–200ms, `--ease-out` for entrances. Everything is switched off under
`prefers-reduced-motion`, except the live status dot, which stops moving but
stays — removing it would remove information.

---

## 9. Migration state

The product is mid-migration from Blade + Alpine to Vue 3 + Inertia. Phases (a)
through (d) are complete — tokens and fonts, the Inertia shell, a 24-component
library, and the dashboard — and phase (e) is under way.

**Migrated so far:** auth (4 pages), the dashboard, the agents list, the agent
create/edit wizard, leads, settings, `/design-system`.

**Still Blade:** the agent detail page (seven tabs, ~3,400 lines), the unified
inbox (~1,050 lines), and the landing (phase f). Both layouts coexist: a Blade
page loads `app.js` (Alpine), an Inertia page loads `inertia.js` (Vue), and
`InertiaShellTest` pins that neither pulls in the other's framework.

**The dashboard** (`Pages/Dashboard.vue` + `Components/dashboard/`) reads one
metrics pass from `DashboardMetrics` — unchanged, and passed to Vue as-is, since
it was already a plain JSON-serialisable array. Changing the range or the agent
filter is an Inertia visit, so no two numbers on the page can come from
different moments.

Its KPI trends are computed in `resources/js/trend.js` by comparing the two
halves of the returned window, and labelled as exactly that. A true
previous-period comparison needs data from outside the window, which means
changing the metrics service — deliberately out of scope for this migration.

**The app shell** is `Layouts/AppLayout.vue`: `AppSidebar` (navy, collapsible,
dark in both themes) plus `AppTopbar` (light, carrying only view-level controls
— language, theme, stage, notifications, account). Navigation lives in the rail;
nothing in the bar moves you between screens except the account menu.

**The mark** is `Components/VeloraMark.vue`, traced from
`resources/brand/logo-source.png` and verified against it at **99.4% pixel
agreement**. `resources/brand/velora-mark.svg` is the master;
`scripts/generate-brand-assets.php` renders every favicon, app icon and OG image
*from the geometry* at each size, so a 16px favicon is as crisp as the 512px
icon. Small sizes use the **flat navy** cut — below ~48px the gradient's dark end
and a dark browser tab converge and the mark loses its silhouette.

**Legacy aliases.** `tokens.css` ends with a block mapping the terracotta-era
names (`brand`, `paper`, `ink`, `night-*`, `ember-*`, `sand-*`) onto Velora
Midnight, so all 53 Blade views re-skinned without being touched. A view still
asking for `bg-brand` gets the new primary.

> **Do not use legacy names in new markup.** The block is deleted in phase (f),
> with the last Blade view.

The `night-*` family stays fixed-dark in both themes on purpose: those tokens are
only ever written behind a `dark:` variant, so they must not follow the surface
swap.

**One authored source, still.** `scripts/build-palette.mjs` compiles `tokens.css`
into `partials/palette-vars.blade.php`, because the landing ships its own inline
stylesheet and cannot `@import` the token file. The script and the partial are
both deleted in phase (f).

---

## 10. Commands

```bash
npm run fonts:verify   # per-glyph Tajik coverage — run after ANY font change
npm run palette        # regenerate the landing's token partial from tokens.css
npm run build          # runs palette, then vite build

php artisan test --filter=BrandConsistencyTest   # the palette + contrast guard
```
