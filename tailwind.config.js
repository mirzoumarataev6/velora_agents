/**
 * VELORA DESIGN SYSTEM — the single authored source of truth for the product.
 *
 * How this reaches the CSS
 * -----------------------
 * Tailwind v4 is CSS-first: `@theme` in a stylesheet is what generates both the
 * utilities (`bg-ember-500`) and the `--color-*` custom properties that inline
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
 * DESIGN DIRECTION — "warm daylight", terracotta edition
 *
 * Warm paper, generous air, hairline borders instead of heavy boxes, and ONE
 * accent: a warm terracotta (#e27551). It replaces a brighter cut of the same
 * hue (#ee6c4d), the vivid ember orange (#fb6309) before that, and the
 * cream/dusty-terracotta scheme before that.
 *
 * The neutrals are warm on purpose — #faf9f5 paper, #2b2b2b charcoal ink,
 * #1a1815 in the dark. A neutral grey next to this accent reads cold and cheap;
 * a warm one makes the accent look like a decision rather than a highlight.
 *
 * TYPE is two voices: a self-hosted serif for the display range and a
 * self-hosted sans for body and UI — see `fontFamily` below.
 *
 * The Velora mark keeps its own orange gradient (see `logo` below): it is a
 * fixed asset, and orange sits a few degrees off terracotta on the same warm
 * axis, so the two harmonise instead of clashing.
 *
 * Every foreground/background pair this system ships is contrast-checked; the
 * numbers live in tests/Feature/BrandConsistencyTest.php and are reproduced in
 * the comments below.
 * ---------------------------------------------------------------------------
 */

/* --- Accent: EMBER (terracotta) --------------------------------------------
   ember-500 (#e27551) is the brand accent — the "spark" terracotta, a step
   warmer and softer than the #ee6c4d it replaces. The scale splits by job, and
   the split is a contrast requirement, not a preference:

     50–200   TINTS. Selected-row fills, badge backgrounds, soft washes.
              ember-100 (#fae4db) is the approved chip/badge tint.
     300–600  DECORATION + non-text UI. Glows, gradients, borders, focus rings,
              iconography. ember-500 lives here, so the brand colour is what the
              eye actually reads as "Velora".
     700–900  INTERACTION. Filled button backgrounds and link text — anything
              that has to survive a contrast check as text.

   Measured on white:
     #e27551  white on it 3.04:1 · as text 3.04:1   ← below the 4.5 text floor
     #cc5c39  white on it 4.07:1 · as text 4.07:1   ← still below
     #ab4a2c  white on it 5.60:1 · as text 5.60:1   ← clears both
   So ember-500 stays the identity and ember-700 carries text and button fills.

   The focus ring is ember-600: WCAG 2.2 puts a 3:1 floor on non-text UI, and
   ember-500 measures 2.89:1 against the sand-50 canvas — it fails on the page.
   ember-600 is 3.87:1 there, and 3.48:1 on the sunk surface. */
const ember = {
    50: '#fdf3ef',
    100: '#fae4db', // the approved soft tint — chips, badges, subtle fills
    200: '#f6cbba',
    300: '#f0b096',
    400: '#ee9a7c', // dark-mode accent text — 6.63:1 on the night canvas
    500: '#e27551', // THE ACCENT
    600: '#cc5c39', // focus rings, large accent text, icon strokes
    700: '#ab4a2c', // filled buttons, link text
    800: '#8b3c23', // button hover / pressed (white on it 8.09:1)
    900: '#71321d',
    950: '#3d1a0f',
};

/* The accent gradient — light-to-deep across the terracotta scale. */
const gradient = {
    from: ember[400],
    via: ember[500],
    to: ember[600],
};

/* Ink for a label sitting ON an accent fill or gradient.
 *
 * LIGHT, by design decision: a warm off-white rather than pure white, so a
 * filled button reads as part of a warm palette instead of a stock UI kit. The
 * warmth costs a hair of contrast (2.90:1 on ember-500 versus 3.04:1 for pure
 * white) and buys the accent fill looking deliberate.
 *
 * This is a trade, and it is worth naming: at ember-500 an off-white label
 * measures 2.90:1, which clears WCAG's non-text and large-text floors but not
 * the 4.5:1 body-text floor. Two things keep it honest — the hover state moves
 * to ember-600 (3.87:1), and the landing's gradient is weighted so a label sits
 * over the 500–600 half of it rather than the 400 end (2.09:1), see --o-grad.
 * Moving filled buttons to `brand-strong` would clear 4.5:1 outright (5.33:1),
 * at the cost of the fill no longer being the specified accent.
 */
const onAccent = '#fff8f3';

/* The Velora mark's own sweep. Deliberately NOT the accent gradient: the logo
   is a fixed asset in orange, and this keeps anything that reproduces the mark
   in CSS matched to the artwork rather than drifting toward the accent. */
const logo = {
    from: '#ff9a4d',
    to: '#f0530a',
};

/* --- Neutrals: SAND --------------------------------------------------------
   Warm paper rather than white — sand-50 (#faf9f5) is the canvas, and the ink
   is a charcoal (#2b2b2b) rather than black, so long-form copy on the page
   never has the hard, printed-on-glass feel of pure black on pure white.

   The three light surfaces are deliberately close together. Depth comes from
   hairlines and shadow, not from stacking ever-darker greys. */
const sand = {
    0: '#ffffff', //  surface — cards, sheets, inputs
    50: '#faf9f5', //  canvas — the page itself
    100: '#f0ede6', //  sunk — table headers, wells, code blocks
    200: '#e9e5dc', //  border — the standard hairline
    300: '#ddd8cb', //  stronger border, dividers on a filled surface
    400: '#b5afa0', //  disabled text, dividers on dark fills
    500: '#8a8578', //  placeholder, non-essential icon (3.68:1 — never body text)
    600: '#6b665a', //  muted / secondary body (5.71:1 on white)
    700: '#4a463c', //  secondary heading (9.41:1)
    800: '#332f28',
    900: '#2b2b2b', //  ink — primary text (14.17:1)
    950: '#1a1815',
};

/* --- Dark mode -------------------------------------------------------------
   Warm near-black rather than blue-black, so dark mode is the same brand in a
   different light rather than a different product. Four elevation steps, each
   one a real surface a component can sit on. */
const night = {
    canvas: '#1a1815',
    soft: '#201d18',
    card: '#242019',
    raised: '#2b2620',
    ink: '#f5f1e8', // 15.71:1 on canvas
    muted: '#a39d90', // 6.56:1 on canvas
    field: '#221e18',
};

/* --- Semantics -------------------------------------------------------------
   A terracotta accent crowds both red and amber, so these are pushed
   deliberately away from the brand hue: danger toward crimson (well clear of
   ember-700, which is only a few degrees off a brick red), warning toward true
   yellow, and success/info carry the cool end alone. Status is never signalled
   by colour by itself — every use pairs with an icon or a label. */
const semantic = {
    success: '#0e7a4a', // 5.48:1 on white
    successLight: '#57cf94', // 9.07:1 on the night canvas
    warning: '#96620a', // 5.30:1 on white — warm amber, already in key
    warningLight: '#f0b429',
    danger: '#c2183a', // 6.02:1 on white — crimson, not brick
    dangerDeep: '#a01230', // destructive button hover (white on it 8.00:1)
    dangerLight: '#f2899a',
    info: '#1f6f8b',
    infoLight: '#63c5e0',
};

export default {
    theme: {
        extend: {
            colors: {
                ember,
                sand,

                /* ---- Semantic aliases -------------------------------------
                   Views name the role, not the swatch, so a future re-skin is
                   one edit here. These resolve to the literals above, keeping
                   exactly one place a hex is written. */

                /* The identity terracotta: borders, tints, glows, decorative fills. */
                brand: ember[500],
                'brand-ring': ember[600],
                'brand-hover': ember[600],
                /* Text and filled buttons — see the note on `ember`. */
                'brand-strong': ember[700],
                'brand-deep': ember[800],
                /* Dark mode lightens the accent so it reads on warm near-black. */
                'brand-light': ember[400],

                'grad-from': gradient.from,
                'grad-via': gradient.via,
                'grad-to': gradient.to,
                /* Ink for a label sitting ON an accent fill or gradient. */
                'on-accent': onAccent,
                /* The mark's own orange — logo reproductions only. */
                'logo-from': logo.from,
                'logo-to': logo.to,

                surface: sand[0],
                paper: sand[50],
                sunk: sand[100],
                hairline: sand[200],
                'hairline-strong': sand[300],
                ink: sand[900],
                'ink-soft': sand[700],
                muted: sand[600],
                faint: sand[500],

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
                   The old cream/charcoal/orange scale names, re-pointed at the
                   new system so no surface breaks mid-redesign. Views still
                   referencing these are rewritten stage by stage; when the last
                   one goes, so does this block. Do not use in new markup. */
                cream: {
                    50: sand[0],
                    100: sand[50],
                    200: sand[50],
                    300: sand[100],
                    400: sand[200],
                    500: sand[300],
                },
                charcoal: {
                    50: night.ink,
                    100: sand[200],
                    300: night.muted,
                    500: sand[600],
                    700: night.raised,
                    800: night.soft,
                    900: sand[900],
                    950: night.canvas,
                },
                orange: ember,
            },

            fontFamily: {
                /* GEIST, self-hosted.
                 *
                 * Replaces Inter, which read as chunky at heading sizes. Geist
                 * is narrower with more open counters and a less mechanical
                 * rhythm, so headlines feel composed rather than heavy, and it
                 * still holds up at 13px in the dashboard — one family, both
                 * jobs. Only 300–600 ship: there is deliberately no 700, so a
                 * heading cannot be made bold by accident.
                 *
                 * Coverage verified per glyph against the actual woff2 subsets
                 * (not the declared unicode-range, which is what makes fonts
                 * look covered when they are not) — full Latin, full Russian,
                 * and all six Tajik pairs:
                 *   Ғғ U+0492/3 · Ққ U+049A/B · Ҳҳ U+04B2/3
                 *   Ҷҷ U+04B6/7 · Ӣӣ U+04E2/3 · Ӯӯ U+04EE/F
                 *
                 * Still failing that same test: Onest, Manrope, Jost, Unbounded
                 * and both Wix Madefor faces (no Tajik pairs at all), Rubik
                 * (missing Ҳҳ alone), Mona Sans (no Cyrillic whatsoever).
                 *
                 * Only upright weights ship, so the type rules still set
                 * emphasis in weight and colour; the only italic on the product
                 * is the landing's deliberate serif accent, which is its own
                 * family and not a synthesised oblique of this one.
                 */
                sans: [
                    'Geist',
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

                /* SOURCE SERIF 4, self-hosted — the display voice.
                 *
                 * Headings, the hero and big numbers only. Body, UI, tables,
                 * forms and labels stay on the sans above: a serif at 13px in a
                 * dashboard costs readability and buys nothing.
                 *
                 * Coverage decided this. Of the three faces proposed, Fraunces
                 * and Newsreader ship no Cyrillic whatsoever, which ends the
                 * conversation for a product whose primary locales are Russian
                 * and Tajik. Source Serif 4 carries cyrillic and cyrillic-ext,
                 * and scripts/verify-font-coverage.mjs proves it per glyph
                 * against the shipped woff2 rather than trusting the declared
                 * unicode-range — full Latin, full Russian, and all six Tajik
                 * pairs (Ғғ Ққ Ҳҳ Ҷҷ Ӣӣ Ӯӯ).
                 *
                 * The fallbacks are the same book-serif shape, so a first paint
                 * before the webfont lands is a near-miss rather than a jump.
                 */
                serif: [
                    'Source Serif 4',
                    'Iowan Old Style',
                    'Palatino Linotype',
                    'Palatino',
                    'Georgia',
                    'ui-serif',
                    'serif',
                ],
                /* `display` is the role name the product already uses (.font-display,
                   theme(--font-display)); it now resolves to the serif, so every
                   surface that opted into "this is a display string" gets the new
                   voice without a single class being rewritten. */
                display: [
                    'Source Serif 4',
                    'Iowan Old Style',
                    'Palatino Linotype',
                    'Palatino',
                    'Georgia',
                    'ui-serif',
                    'serif',
                ],
            },

            /* --- Type scale --------------------------------------------------
               A fluid display range that collapses cleanly on a phone, over a
               fixed UI range where predictability matters more than fluidity.
               Tracking tightens as size grows — the single most reliable way to
               make a grotesque look intentional at headline sizes. */
            fontSize: {
                overline: ['0.6875rem', { lineHeight: '1', letterSpacing: '0.08em', fontWeight: '600' }],
                caption: ['0.75rem', { lineHeight: '1.4', letterSpacing: '0' }],
                'body-sm': ['0.8125rem', { lineHeight: '1.5', letterSpacing: '0' }],
                body: ['0.9375rem', { lineHeight: '1.6', letterSpacing: '-0.005em' }],
                'body-lg': ['1.0625rem', { lineHeight: '1.6', letterSpacing: '-0.01em' }],
                lead: ['clamp(1.0625rem, 0.95rem + 0.5vw, 1.25rem)', { lineHeight: '1.55', letterSpacing: '-0.011em' }],

                /* h3/h4 are component titles — card headers, panel labels — and
                   stay on the sans with the grotesque's tight tracking. */
                h4: ['1.125rem', { lineHeight: '1.4', letterSpacing: '-0.015em', fontWeight: '600' }],
                h3: ['clamp(1.25rem, 1.1rem + 0.6vw, 1.5rem)', { lineHeight: '1.3', letterSpacing: '-0.02em', fontWeight: '600' }],

                /* h1/h2 and the display range are set in the serif, so their
                   metrics change with it: tracking eases back (a book serif is
                   drawn with its own spacing built in — pulling it as tight as a
                   grotesque makes the serifs collide), weight drops to 400 (a
                   serif carries authority through shape, not mass), and the
                   leading opens a touch for the taller ascenders. */
                h2: ['clamp(1.625rem, 1.3rem + 1.4vw, 2.25rem)', { lineHeight: '1.25', letterSpacing: '-0.014em', fontWeight: '400' }],
                h1: ['clamp(2rem, 1.5rem + 2.2vw, 3rem)', { lineHeight: '1.18', letterSpacing: '-0.016em', fontWeight: '400' }],

                'display-sm': ['clamp(2.25rem, 1.6rem + 2.8vw, 3.5rem)', { lineHeight: '1.12', letterSpacing: '-0.018em', fontWeight: '400' }],
                display: ['clamp(2.75rem, 1.7rem + 4.4vw, 4.5rem)', { lineHeight: '1.08', letterSpacing: '-0.02em', fontWeight: '400' }],
                'display-lg': ['clamp(3rem, 1.5rem + 6.2vw, 5.5rem)', { lineHeight: '1.04', letterSpacing: '-0.022em', fontWeight: '400' }],
            },

            /* --- Radii -------------------------------------------------------
               Consistent curvature is most of what separates a designed product
               from an assembled one. Controls sit at `md`, cards at `xl`, the
               big marketing panels at `3xl`, anything pill-shaped at `pill`. */
            borderRadius: {
                xs: '0.375rem',
                sm: '0.5rem',
                md: '0.625rem',
                lg: '0.875rem',
                xl: '1rem',
                '2xl': '1.25rem',
                '3xl': '1.75rem',
                '4xl': '2.25rem',
                pill: '999px',
            },

            /* --- Elevation ---------------------------------------------------
               Warm-tinted shadows. Neutral black over a warm canvas goes grey
               and dirty; these carry the ink's own hue so a raised surface still
               looks like it belongs to the page. Five steps, used sparingly —
               most surfaces get a hairline and nothing else. */
            boxShadow: {
                e1: '0 1px 2px rgba(26, 24, 21, 0.05), 0 1px 3px -1px rgba(26, 24, 21, 0.04)',
                e2: '0 1px 2px rgba(26, 24, 21, 0.04), 0 4px 12px -4px rgba(26, 24, 21, 0.10)',
                e3: '0 1px 2px rgba(26, 24, 21, 0.04), 0 12px 28px -12px rgba(26, 24, 21, 0.18)',
                e4: '0 2px 4px rgba(26, 24, 21, 0.04), 0 24px 48px -24px rgba(26, 24, 21, 0.24)',
                e5: '0 2px 8px rgba(26, 24, 21, 0.05), 0 40px 80px -32px rgba(26, 24, 21, 0.30)',
                /* ember-500 (#e27551) as rgb — the accent's own halo. */
                'glow-ember': '0 8px 28px -10px rgba(226, 117, 81, 0.45)',
                'glow-ember-lg': '0 16px 40px -12px rgba(226, 117, 81, 0.55)',
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

