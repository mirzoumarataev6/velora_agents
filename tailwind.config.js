/**
 * VELORA DESIGN SYSTEM — the single authored source of truth for the product.
 *
 * How this reaches the CSS
 * -----------------------
 * Tailwind v4 is CSS-first: `@theme` in a stylesheet is what generates both the
 * utilities (`bg-azure-500`) and the `--color-*` custom properties that inline
 * styles read with `var()`. A v3-style JS config loaded through `@config` only
 * produces the utilities — verified by compiling a probe: `.bg-probe-500`
 * appeared, `--color-probe-500` did not. The landing sets a lot of its surface
 * through inline `style="…var(--…)"`, so utilities alone are not enough.
 *
 * Rather than maintain the system twice, this file stays the one place a token
 * is ever typed, and `scripts/build-palette.mjs` compiles it into
 * `resources/css/palette.generated.css` as a `@theme` block. That runs
 * automatically before `npm run dev` and `npm run build`.
 *
 * Change a token here. Never edit the generated file.
 *
 * ---------------------------------------------------------------------------
 * DESIGN DIRECTION — "open daylight"
 *
 * Airy, modern, serious. A light blue-grey canvas (#f3f6fb), content on white
 * cards with a 14–16px radius, NO border, and a very soft two-layer shadow that
 * deepens on hover. Accent material on a light-blue tint, blue outline icons in
 * light-blue circles, green pills for positive movement and amber for anything
 * waiting on a person.
 *
 * This is a correction of the flat Eskhata pass, not a new palette: the blues,
 * greys and semantics below are unchanged and still carry their sampled
 * provenance. What changed is everything that made that pass read as dated —
 * the 700/800 type, the flat blue header band, the borderless zero-elevation
 * cards, and the half-empty widgets. Type now caps at 600, the band is gone in
 * favour of a light page header, cards have a whisper of elevation so the page
 * has depth, and every widget carries enough content to earn its area.
 *
 * Before that: the "warm daylight" terracotta scheme (#e27551 on #faf9f5), and
 * the ember/cream/charcoal schemes before it.
 *
 * EVERY COLOUR BELOW WAS SAMPLED FROM THE REFERENCE SCREENSHOTS in
 * docs/reference/eskhata/ — median of a flat patch per element, and for text
 * and thin strokes the median of the darkest/bluest few percent of pixels in
 * the glyph box (a naive average of antialiased type reads several stops too
 * light). The sampled value is quoted next to every token it produced.
 *
 * Three greys were deliberately DARKENED from what the reference uses, because
 * the reference does not clear this product's contrast floor — body ≥ 7:1 and
 * secondary ≥ 4.5:1, set so the dashboard survives a projector. Each one is
 * marked ✎ below with both numbers.
 *
 * TYPE is one voice: Onest 2.001, self-hosted — see `fontFamily`. Capped at
 * weight 600 everywhere; nothing in this product is 700 or above.
 *
 * Every foreground/background pair this system ships is contrast-checked; the
 * numbers live in tests/Feature/BrandConsistencyTest.php and are reproduced in
 * the comments below.
 * ---------------------------------------------------------------------------
 */

/* --- Accent: AZURE ---------------------------------------------------------
   Two blues do the work, and the reference is explicit about which goes where:

     azure-500 (#1a50fc)  the BRIGHT blue — sampled from the round scan button,
                          the "Шаблоны" star and the filled currency icon
                          (#1a50fc / #245cf9 / #1a51f8 across three shots).
                          This is the identity: the colour the eye reads as the
                          brand. 5.84:1 on white.

     azure-600 (#0052cc)  the DEEP blue — sampled from the "Все продукты"
                          button, the active bottom-nav item and every outline
                          icon (#0051c7 / #0052cd / #0052cd). This is the
                          working blue: filled buttons, link text, icon strokes,
                          anything that must survive a contrast check. 6.82:1.

   The scale splits by job, and the split is a contrast requirement:

     50–200   TINTS. azure-100 (#e6f1fc) is the icon-circle fill and azure-200
              (#d8e6f8) the accent-card wash — both sampled, not mixed.
     300–400  DECORATION + dark-mode accent text.
     500–600  THE BRAND. Identity fills, FABs, buttons, links, icon strokes.
     700–950  PRESSED STATES and deep washes.

   Measured on white:
     #1a50fc  white on it 5.84:1 · as text 5.84:1   ← clears 4.5
     #0052cc  white on it 6.82:1 · as text 6.82:1   ← clears 4.5 comfortably
     #00429f  white on it 8.71:1
   so azure-500 is the identity and azure-600 carries text and button fills. */
const azure = {
    50: '#eff5ff',
    100: '#e6f1fc', // SAMPLED — icon-circle fill (#e2f0fb / #e8f2fb, two shots)
    200: '#d8e6f8', // SAMPLED — accent-card wash, "Идентификация" (#d4e5f7)
    300: '#aecbf5',
    400: '#7aa5ff', // dark-mode accent text — 7.80:1 on the night canvas
    500: '#1a50fc', // SAMPLED — THE ACCENT (scan button, star, filled icons)
    600: '#0052cc', // SAMPLED — filled buttons, link text, icon strokes
    700: '#00429f', // button hover / pressed (white on it 8.71:1)
    800: '#003480',
    900: '#002862',
    950: '#001536',
};

/* The soft blue header band behind the balance — the one measured value this
 * system does NOT ship as sampled.
 *
 * The reference band is #6696de, and white text on it measures 3.01:1. That
 * clears WCAG's large-text floor, which is why it works on a phone showing one
 * big number, and fails this product's floor the moment a label or a secondary
 * line sits on the same band — which the dashboard header does.
 *
 * ✎ Darkened to #2f66c4: white on it is 5.50:1. Going all the way to 7:1 needs
 * #1b54ae, at which point the band stops reading as "soft" at all and becomes a
 * second button. #2f66c4 is the compromise, and the rule that comes with it is
 * that only large text sits on the band.
 */
const band = '#2f66c4';

/* The accent gradient — bright-to-deep across the working half of the scale. */
const gradient = {
    from: azure[500],
    via: azure[600],
    to: azure[700],
};

/* Ink for a label sitting ON an accent fill or gradient.
 *
 * Pure white, unlike the warm off-white the terracotta scheme used. That
 * off-white existed to keep a warm accent from looking like a stock UI kit; on
 * a cool blue it just reads as dirty, and it costs contrast for nothing. White
 * on azure-600 is 6.82:1 and on azure-500 5.84:1 — both clear the body floor,
 * so filled buttons need no caveat here at all. */
const onAccent = '#ffffff';

/* The Velora mark's own sweep, sampled from the ARTWORK — the 1st and 99th
   luminance percentile of the opaque pixels in resources/brand/logo-source.png,
   which is the supplied logo and the master every icon is generated from.

   The previous pair (#4b83fc → #1523a0) was read off a hand-traced SVG of that
   artwork rather than the artwork itself, and was visibly lighter at the dark
   end than the real mark. The trace is retired; these are the real values.

   Deliberately its own pair and not the accent gradient: the logo is a fixed
   asset, and this keeps anything that reproduces the mark in CSS matched to
   the file rather than drifting toward the accent. */
const logo = {
    from: '#62a9fc',
    to: '#01014e',
};

/* --- Neutrals: SLATE -------------------------------------------------------
   Cool grey-blue rather than warm paper — slate-50 (#f1f2f6) is the canvas,
   sampled identically on three different screens of the reference, and white is
   reserved for cards sitting on it. That one-step separation IS the layout: the
   reference draws no card borders and casts no card shadows, which a scan
   across a card edge confirms (#f1f2f6 → #ffffff in a single pixel, no
   intermediate ring).

   The ink is a near-black with a faint blue cast rather than the reference's
   flat #040404, which is pure neutral and goes slightly harsh on a large white
   field. */
const slate = {
    0: '#ffffff', //  surface — cards, sheets, the app's white
    50: '#f3f6fb', //  canvas, the page itself — the brief's airier, bluer ground
    100: '#e8ebf1', //  sunk — table headers, wells, input fills
    200: '#dde2ea', //  divider — the thin rule inside a card
    300: '#cbd2dd', //  stronger divider, rules on a filled surface
    400: '#b9c0cc', //  disabled text, rules on dark fills
    500: '#7d8592', //  ✎ chevrons and non-essential icons — 3.72:1, never text
    600: '#5c6470', //  ✎ muted / secondary body — 5.98:1 on white
    700: '#3a4150', //  secondary heading (10.23:1)
    800: '#232935',
    900: '#0f1115', //  ink — primary text (18.90:1)
    950: '#080a0e',
};

/* ✎ The three darkened greys, stated once so the deviation is auditable:
 *
 *   role            reference    ships as    on white
 *   secondary text  #818181      #5c6470     3.90:1 → 5.98:1
 *   nav inactive    #bfc1c3      #5c6470     1.81:1 → 5.98:1
 *   chevron (icon)  #b7b7b8      #7d8592     2.00:1 → 3.72:1
 *
 * The first two are text and are held to the 4.5:1 secondary floor. The chevron
 * is non-text UI and is held to WCAG 2.2's 3:1 floor instead — matching the
 * reference's visual weight as closely as that allows.
 */

/* --- Dark mode -------------------------------------------------------------
   The same blue family after dark: a cool near-black rather than the warm one
   the terracotta scheme used, so dark mode is this brand in a different light
   rather than a different product. Four elevation steps, each one a real
   surface a component can sit on. */
const night = {
    canvas: '#0d1117',
    soft: '#111823',
    card: '#151b26',
    raised: '#1d2433',
    ink: '#e8ecf5', // 15.99:1 on canvas
    muted: '#9aa6bd', // 7.72:1 on canvas
    field: '#121926',
};

/* --- Semantics -------------------------------------------------------------
   A blue accent leaves red, amber and green uncrowded, so these sit where they
   naturally belong — no pushing away from the brand hue is needed here, unlike
   the terracotta scheme. Info is the exception: it is deliberately pulled to
   teal so an informational badge never reads as a brand element. Status is
   never signalled by colour by itself — every use pairs with an icon or a
   label. */
const semantic = {
    success: '#0e7a4a', // 5.48:1 on white
    successLight: '#57cf94', // 8.54:1 on the night canvas
    warning: '#96620a', // 5.30:1 on white
    warningLight: '#f0b429',
    danger: '#c2183a', // 6.02:1 on white
    dangerDeep: '#a01230', // destructive button hover (white on it 8.00:1)
    dangerLight: '#f2899a',
    info: '#0e6f8b', // teal-leaning, so it cannot be mistaken for the accent
    infoLight: '#63c5e0',
};

export default {
    theme: {
        extend: {
            colors: {
                azure,
                slate,

                /* ---- Semantic aliases -------------------------------------
                   Views name the role, not the swatch, so a future re-skin is
                   one edit here. These resolve to the literals above, keeping
                   exactly one place a hex is written. */

                /* The identity blue: FABs, decorative fills, the live dot. */
                brand: azure[500],
                'brand-ring': azure[600],
                'brand-hover': azure[700],
                /* Text and filled buttons — see the note on `azure`. */
                'brand-strong': azure[600],
                'brand-deep': azure[700],
                /* Dark mode lightens the accent so it reads on cool near-black. */
                'brand-light': azure[400],

                /* The two sampled tints, named by the job the reference gives
                   them, so a view never has to remember which step to reach
                   for: a circle behind an icon, or a whole accent card. */
                'brand-tint': azure[200], //  accent / promo cards
                'brand-circle': azure[100], //  icon circles
                /* The soft header band — see the note on `band`. */
                'brand-band': band,

                'grad-from': gradient.from,
                'grad-via': gradient.via,
                'grad-to': gradient.to,
                /* Ink for a label sitting ON an accent fill or gradient. */
                'on-accent': onAccent,
                /* The mark's own blue — logo reproductions only. */
                'logo-from': logo.from,
                'logo-to': logo.to,

                surface: slate[0],
                paper: slate[50],
                sunk: slate[100],
                hairline: slate[200],
                'hairline-strong': slate[300],
                ink: slate[900],
                'ink-soft': slate[700],
                muted: slate[600],
                faint: slate[500],

                night: night.canvas,
                'night-soft': night.soft,
                'night-card': night.card,
                'night-raised': night.raised,
                'night-ink': night.ink,
                'night-muted': night.muted,
                'field-dark': night.field,

                success: semantic.success,
                'success-light': semantic.successLight,
                danger: semantic.danger,
                'danger-deep': semantic.dangerDeep,
                'danger-light': semantic.dangerLight,
                warning: semantic.warning,
                'warning-light': semantic.warningLight,
                info: semantic.info,
                'info-light': semantic.infoLight,

                /* ---- DEPRECATED ------------------------------------------
                   The old scale names, re-pointed at the new system so no
                   surface breaks mid-redesign. Views still referencing these
                   are rewritten stage by stage; when the last one goes, so does
                   this block. Do not use in new markup.

                   `ember` and `orange` are the terracotta scale's names and now
                   resolve to azure — a view that still says `bg-orange-500`
                   renders blue rather than reintroducing the old accent. */
                cream: {
                    50: slate[0],
                    100: slate[50],
                    200: slate[50],
                    300: slate[100],
                    400: slate[200],
                    500: slate[300],
                },
                charcoal: {
                    50: night.ink,
                    100: slate[200],
                    300: night.muted,
                    500: slate[600],
                    700: night.raised,
                    800: night.soft,
                    900: slate[900],
                    950: night.canvas,
                },
                sand: slate,
                ember: azure,
                orange: azure,
            },

            fontFamily: {
                /* ONEST 2.001, self-hosted — see the @font-face block at the
                 * top of resources/css/app.css for why it is vendored from the
                 * foundry rather than fetched through the fonts plugin.
                 *
                 * This replaces Golos Text, which rendered heavy and dated at
                 * the weights the last pass used it at. Onest is a Cyrillic-
                 * first design with a taller x-height, more open apertures and
                 * noticeably lighter stems at the same nominal weight, which is
                 * what lets the whole product cap out at 600 and still hold its
                 * hierarchy.
                 *
                 * Coverage verified per glyph against the shipped woff2 cmap
                 * (not the declared unicode-range, which is what makes a font
                 * look covered when it is not) — full Latin, full Russian, and
                 * all six Tajik pairs:
                 *   Ғғ U+0492/3 · Ққ U+049A/B · Ҳҳ U+04B2/3
                 *   Ҷҷ U+04B6/7 · Ӣӣ U+04E2/3 · Ӯӯ U+04EE/F
                 *
                 * 400 body · 500 labels, buttons, nav · 600 headings and KPI
                 * figures. No 700 is shipped, by design.
                 */
                sans: [
                    'Onest',
                    'ui-sans-serif',
                    'system-ui',
                    '-apple-system',
                    'Segoe UI',
                    'Helvetica',
                    'Arial',
                    'sans-serif',
                    'Apple Color Emoji',
                    'Segoe UI Emoji',
                ],

                /* ONE VOICE. `serif` and `display` are kept as names because a
                 * lot of the product already says `.font-display` and
                 * `theme(--font-display)`; pointing them at the same stack
                 * re-voices every one of those surfaces without a class being
                 * rewritten. No second family is downloaded on any page. */
                serif: ['Onest', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Helvetica', 'Arial', 'sans-serif'],
                display: ['Onest', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Helvetica', 'Arial', 'sans-serif'],
            },

            /* --- Type scale --------------------------------------------------
               LIGHT AND MODERN, and the weights are the whole point of this
               revision. The previous pass ran h1/h2 and every KPI figure at
               700, with a display range up to 88px — on screen that read heavy,
               chunky and about a decade old. Everything here now tops out at
               600, and the sizes came down with it.

               What replaces mass as the carrier of hierarchy:
                 · SIZE steps that are still clearly separated, just smaller.
                 · TRACKING that tightens as size grows (-0.01em to -0.022em) —
                   the single most reliable way to make a grotesque look drawn
                   rather than defaulted.
                 · COLOUR: a 600 in full ink against a 400 in muted grey opens a
                   far bigger gap than 700-vs-400 in the same colour ever did.

               Page titles land at 22–26px (h3) and section titles at 18px (h4),
               per the brief. h1/h2 survive for the landing's marketing range,
               where a genuinely large headline is the point, but they are 600
               now too and their ceilings came down.

               The display range no longer exists as a separate idea: a KPI
               figure is `kpi` (24–28px), and the landing hero is h1. The three
               old `display*` steps are kept as aliases so no view breaks, but
               they resolve into the new, smaller ceiling. */
            fontSize: {
                overline: ['0.6875rem', { lineHeight: '1', letterSpacing: '0.06em', fontWeight: '500' }],
                caption: ['0.75rem', { lineHeight: '1.45', letterSpacing: '0' }],
                'body-sm': ['0.8125rem', { lineHeight: '1.55', letterSpacing: '0' }],
                body: ['0.9375rem', { lineHeight: '1.6', letterSpacing: '-0.005em' }],
                'body-lg': ['1.0625rem', { lineHeight: '1.6', letterSpacing: '-0.01em' }],
                lead: ['clamp(1.0625rem, 0.95rem + 0.5vw, 1.1875rem)', { lineHeight: '1.55', letterSpacing: '-0.011em', fontWeight: '400' }],

                /* Component titles — card headers and panel labels. */
                h4: ['1.125rem', { lineHeight: '1.35', letterSpacing: '-0.014em', fontWeight: '600' }],
                /* THE PAGE TITLE. 22px → 26px, the brief's range exactly. */
                h3: ['clamp(1.375rem, 1.25rem + 0.45vw, 1.625rem)', { lineHeight: '1.28', letterSpacing: '-0.018em', fontWeight: '600' }],

                /* Marketing range. Still fluid, still 600. */
                h2: ['clamp(1.5rem, 1.25rem + 1.1vw, 2rem)', { lineHeight: '1.24', letterSpacing: '-0.02em', fontWeight: '600' }],
                h1: ['clamp(1.875rem, 1.4rem + 1.9vw, 2.625rem)', { lineHeight: '1.16', letterSpacing: '-0.022em', fontWeight: '600' }],

                /* THE KPI FIGURE. 24–28px per the brief — a headline number,
                   not a hero. Tabular is applied by `.kpi-value`, not here, so
                   the step stays reusable. */
                kpi: ['clamp(1.5rem, 1.3rem + 0.6vw, 1.75rem)', { lineHeight: '1.1', letterSpacing: '-0.02em', fontWeight: '600' }],
                'kpi-lg': ['clamp(1.75rem, 1.45rem + 1vw, 2.25rem)', { lineHeight: '1.08', letterSpacing: '-0.022em', fontWeight: '600' }],

                /* Deprecated aliases. Views still saying `text-display` get the
                   new ceiling rather than the old 700-weight monsters. */
                'display-sm': ['clamp(1.75rem, 1.45rem + 1vw, 2.25rem)', { lineHeight: '1.08', letterSpacing: '-0.022em', fontWeight: '600' }],
                display: ['clamp(1.875rem, 1.4rem + 1.9vw, 2.625rem)', { lineHeight: '1.16', letterSpacing: '-0.022em', fontWeight: '600' }],
                'display-lg': ['clamp(2rem, 1.4rem + 2.4vw, 3rem)', { lineHeight: '1.12', letterSpacing: '-0.024em', fontWeight: '600' }],
            },

            /* --- Radii -------------------------------------------------------
               MEASURED off the reference, not chosen. Each corner was traced by
               scanning the fill edge inward one row at a time and reading where
               the arc flattens, in a 576px-wide screenshot (≡ 360dp on device),
               then scaled to desktop density:

                 white card    24px screenshot → 2xl (20px)   ← the card radius
                 accent card   same arc        → 2xl (20px)
                 primary button ~12px          → lg  (14px)
                 filter chip   ~12px, on a 47px-tall pill-shaped element that is
                               NOT a pill (a pill would measure 23.5px) → md (12px)

               Controls sit at `md`, buttons at `lg`, cards at `2xl`, the big
               marketing panels at `3xl`, anything genuinely pill-shaped at
               `pill`. */
            borderRadius: {
                xs: '0.5rem', //  8px  — the smallest chip, a tag
                sm: '0.625rem', // 10px — dense controls
                md: '0.75rem', //  12px — MEASURED: filter chips, inputs, selects
                lg: '0.875rem', // 14px — MEASURED: buttons
                xl: '0.875rem', // 14px — tiles, nested panels, the smaller card
                '2xl': '1rem', //  16px — CARDS. Was 20px; the brief's 14–16px range
                //        reads tighter and more current at desktop density.
                '3xl': '1.5rem', // 24px — large panels, modals
                '4xl': '2rem', //  32px — marketing surfaces
                pill: '999px',
            },

            /* --- Elevation ---------------------------------------------------
               The flat pass gave cards `none` and let the gap between them do
               all the separating. On a large light canvas that read as unfinished
               rather than as calm — the page had no depth at all.

               e1 is the brief's card shadow, two layers: a 1px contact shadow
               that defines the edge, and a wide, very light ambient one that
               lifts the card off the canvas without anybody consciously seeing
               a shadow. e2 is the same thing deepened, which is what a card
               moves to on hover (see `.card-lift`).

               Cool-tinted (rgba(15,23,41,…), the ink's own hue) so a lifted
               surface stays in the blue family rather than going grey.

               e3–e5 are for genuinely floating material — dropdowns, modals,
               toasts, the notification popover — and are unchanged. */
            boxShadow: {
                e1: '0 1px 2px rgba(15, 23, 41, 0.04), 0 4px 16px rgba(15, 23, 41, 0.04)',
                e2: '0 2px 4px rgba(15, 23, 41, 0.05), 0 12px 28px rgba(15, 23, 41, 0.07)',
                e3: '0 1px 2px rgba(15, 23, 41, 0.04), 0 12px 28px -12px rgba(15, 23, 41, 0.16)',
                e4: '0 2px 4px rgba(15, 23, 41, 0.04), 0 24px 48px -24px rgba(15, 23, 41, 0.22)',
                e5: '0 2px 8px rgba(15, 23, 41, 0.05), 0 40px 80px -32px rgba(15, 23, 41, 0.28)',
                /* azure-500 (#1a50fc) as rgb — the accent's own halo, kept for
                   the one place a glow is still wanted: the landing's hero CTA. */
                'glow-ember': '0 8px 28px -10px rgba(26, 80, 252, 0.35)',
                'glow-ember-lg': '0 16px 40px -12px rgba(26, 80, 252, 0.45)',
                inset: 'inset 0 1px 0 rgba(255, 255, 255, 0.6)',
            },

            /* --- Motion ------------------------------------------------------
               Two easings do nearly all the work: `out` for anything entering or
               responding to a click (fast start, soft landing — reads as
               responsive), `inout` for anything moving between two states. The
               spring is reserved for playful confirmations. Durations are short
               on purpose; premium motion is quick and quiet, not slow. */
            transitionTimingFunction: {
                out: 'cubic-bezier(0.22, 1, 0.36, 1)',
                inout: 'cubic-bezier(0.65, 0, 0.35, 1)',
                spring: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
            },
            transitionDuration: {
                instant: '90ms',
                fast: '160ms',
                base: '240ms',
                slow: '380ms',
                slower: '640ms',
            },
        },
    },
};
