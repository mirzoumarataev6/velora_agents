{{--
    Velora Agents — landing page (served at /, /ru, /en, /tg).

    Self-contained STYLESHEET — no Tailwind, no preflight — but NOT its own
    palette: partials/palette-vars carries the product tokens (generated from
    tailwind.config.js) and every colour below is expressed in terms of them.
    It also shares the product's i18n engine (partials/i18n-bundle), so the
    language switcher swaps every string in place without a reload — every
    visible string carries data-i18n and resolves in all three locales.

    PALETTE — the product's, not a second one invented here:
        terracotta ember-400 → ember-500 → ember-600   (the accent gradient)
        neutrals   surface · paper · hairline · night
    One accent, warm neutrals, one warm near-black. Light mode is paper with
    the terracotta gradient; dark mode is the night canvas with the same
    gradient. The tokens are mapped to --l-*/--d-* once and switched by three
    short blocks below.

    LAYOUT — deliberately not a stack of identical containers: the hero is
    off-centre with a floating tilted frame, the bento has four different cell
    sizes, "sources" is a two-column split with offset rails, the flow diagram
    is a connected asymmetric path, and the manifesto is full-bleed inverted.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="showcase-root">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="light dark" />
<script>
    (function () {
        var stored = null;
        try { stored = localStorage.getItem('theme'); } catch (e) {}
        if (stored !== 'light' && stored !== 'dark') stored = null;
        var root = document.documentElement;
        root.classList.remove('light', 'dark');
        if (stored) root.classList.add(stored);
        root.classList.add('js');
    })();
</script>
@include('partials.stage-mode')
<title>{{ __('landing.meta.title') }}</title>
@include('partials.seo-meta', [
    'seoTitle' => __('landing.meta.title'),
    'seoDescription' => __('landing.meta.description'),
])
@include('partials.favicons')
@include('partials.fonts')
{{-- The product palette, generated from tailwind.config.js. This page ships no
     Tailwind, so the tokens arrive as plain custom properties and everything
     below is expressed in terms of them — there is no second palette here. --}}
@include('partials.palette-vars')
<style>
/* ═══════════════════════════════════════════════════════════════════════
   0 · Reset
   ═══════════════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }

/* ═══════════════════════════════════════════════════════════════════════
   1 · Raw values — the brand sheet, declared once
   ═══════════════════════════════════════════════════════════════════════ */
:root {
    /* The accent, straight from the product palette. Three stops of one
       terracotta: ember-400 → ember-500 → ember-600. Nothing here is a hex —
       every value resolves to a token in tailwind.config.js. */
    --o-1: var(--color-ember-400);
    --o-2: var(--color-ember-500);
    --o-3: var(--color-ember-600);
    /* Stops are weighted, not evenly spaced: labels on a filled accent are
       light now, and the lightest stop (ember-400) is the one they read worst
       against — 2.09:1 versus 3.87:1 on the deep stop. Pulling ember-400 back
       to the first third keeps it as a corner highlight while a centred label
       sits over the 500–600 half of the sweep. Same three approved colours. */
    --o-grad: linear-gradient(135deg, var(--o-1), var(--o-2) 32%, var(--o-3));
    --o-light-rgb: var(--rgb-ember-400); /* o-1, for washes and sheens */
    --o-rgb:       var(--rgb-ember-500); /* o-2, for glows */
    --o-deep-rgb:  var(--rgb-ember-600); /* o-3, for accent shadows */

    /* Neutrals. */
    --n-white: var(--color-surface);
    --n-50:    var(--color-paper);
    --n-200:   var(--color-hairline);
    --n-900:   var(--color-night);

    /* The alpha ramps below are all cut from the warm near-black rather than
       from the charcoal ink: at low alpha a warm base keeps hairlines and
       shadows from going grey on the cream canvas. */
    --ink-rgb:      var(--rgb-night);
    --dark-ink-rgb: var(--rgb-night-ink);

    /* Terracotta dark enough to carry small text on paper: ember-500 measures
       2.92:1 as text and fails the 4.5:1 floor, so accent TEXT uses ember-700
       (5.49:1) while the gradient stays exactly as drawn. */
    --o-text: var(--color-ember-700);

    /* Ink that sits ON an accent fill. Measured against all three gradient
       stops, not just the dark end: white is 2.92:1 on ember-500 and fails.
       The warm near-black clears 4.5:1 at every stop (7.82 / 6.06 / 4.55),
       so a filled accent carries dark ink. */
    --on-orange: var(--color-on-accent);

    /* ---- LIGHT ---------------------------------------------------------- */
    --l-bg:        var(--n-white);
    --l-bg-alt:    var(--n-50);
    --l-fg:        var(--color-ink);
    --l-fg-2:      rgba(var(--ink-rgb), .72);
    --l-fg-3:      rgba(var(--ink-rgb), .56);
    --l-fg-4:      rgba(var(--ink-rgb), .52);   /* .46 measured 2.89:1 — under the 3:1 floor */
    --l-line:      var(--n-200);
    --l-line-2:    rgba(var(--ink-rgb), .07);
    --l-accent:    var(--o-text);
    --l-surf:      var(--n-white);
    --l-surf-2:    var(--n-50);
    --l-sunken:    var(--color-sunk);
    --l-dot-fill:  rgba(var(--ink-rgb), .13);
    --l-nav:       rgba(var(--rgb-surface), .82);
    --l-grain-blend: multiply;
    --l-grain-op:    .1;
    --l-glow-1:  rgba(var(--o-light-rgb), .34);
    --l-glow-2:  rgba(var(--o-rgb), .26);
    --l-glow-3:  rgba(var(--o-deep-rgb), .16);
    --l-glow-op: .85;
    --l-grid:    rgba(var(--ink-rgb), .05);
    --l-rule:    linear-gradient(90deg, transparent, rgba(var(--o-rgb), .5) 50%, transparent);
    --l-num-grad: linear-gradient(140deg, var(--color-ink), var(--o-text));
    --l-sheen:   rgba(var(--o-light-rgb), .18);
    --l-shadow-sm: 0 1px 2px rgba(var(--ink-rgb), .05);
    --l-shadow:    0 1px 2px rgba(var(--ink-rgb), .04), 0 16px 36px -24px rgba(var(--ink-rgb), .28);
    --l-shadow-lg: 0 40px 90px -50px rgba(var(--ink-rgb), .38), 0 60px 130px -60px rgba(var(--o-rgb), .4);
    --l-bubble:        var(--color-sunk);
    --l-bubble-border: var(--l-line);
    --l-ghost-bg:      var(--n-white);
    --l-ghost-border:  var(--n-200);
    --l-ghost-hov:     var(--color-sunk);
    --l-ghost-hov-bd:  rgba(var(--ink-rgb), .2);
    --l-o-shadow:     0 12px 30px -14px rgba(var(--o-deep-rgb), .55);
    --l-o-shadow-hov: 0 20px 46px -16px rgba(var(--o-deep-rgb), .7);
    --l-tint:        rgba(var(--o-light-rgb), .14);
    --l-tint-border: rgba(var(--o-rgb), .3);
    --l-inv-bg:  var(--n-900);           /* full-bleed inverted band */
    --l-inv-fg:  var(--color-night-ink);
    --l-inv-fg2: rgba(var(--dark-ink-rgb), .66);
    --l-sel-bg:  rgba(var(--o-light-rgb), .38);
    /* NOT on-accent: the selection background is a translucent orange wash over
       white paper, so the surface under the text stays light and the text on it
       must stay dark. `on-accent` is for a SOLID accent fill only. */
    --l-sel-fg:  var(--color-ink);
    --l-sun:  none;
    --l-moon: block;

    /* ---- DARK ----------------------------------------------------------- */
    --d-bg:        var(--n-900);
    --d-bg-alt:    var(--color-night-soft);
    --d-fg:        var(--color-night-ink);
    --d-fg-2:      rgba(var(--dark-ink-rgb), .72);
    --d-fg-3:      rgba(var(--dark-ink-rgb), .56);
    --d-fg-4:      rgba(var(--dark-ink-rgb), .44);
    --d-line:      rgba(var(--dark-ink-rgb), .12);
    --d-line-2:    rgba(var(--dark-ink-rgb), .07);
    --d-accent:    var(--o-1);
    --d-surf:      var(--color-night-card);
    --d-surf-2:    var(--color-night-raised);
    --d-sunken:    rgba(0, 0, 0, .24);
    --d-dot-fill:  rgba(var(--dark-ink-rgb), .18);
    --d-nav:       rgba(var(--ink-rgb), .78);
    --d-grain-blend: overlay;
    --d-grain-op:    .3;
    --d-glow-1:  rgba(var(--o-light-rgb), .34);
    --d-glow-2:  rgba(var(--o-rgb), .3);
    --d-glow-3:  rgba(var(--o-deep-rgb), .3);
    --d-glow-op: .55;
    --d-grid:    rgba(var(--dark-ink-rgb), .05);
    --d-rule:    linear-gradient(90deg, transparent, rgba(var(--o-light-rgb), .55) 50%, transparent);
    --d-num-grad: linear-gradient(140deg, var(--color-surface), var(--o-1));
    --d-sheen:   rgba(var(--o-light-rgb), .14);
    --d-shadow-sm: none;
    --d-shadow:    0 20px 44px -32px rgba(0, 0, 0, .9);
    --d-shadow-lg: 0 40px 100px -40px rgba(0, 0, 0, .95), 0 60px 140px -60px rgba(var(--o-rgb), .34);
    --d-bubble:        rgba(var(--dark-ink-rgb), .07);
    --d-bubble-border: rgba(var(--dark-ink-rgb), .09);
    --d-ghost-bg:      rgba(var(--dark-ink-rgb), .05);
    --d-ghost-border:  rgba(var(--dark-ink-rgb), .13);
    --d-ghost-hov:     rgba(var(--dark-ink-rgb), .09);
    --d-ghost-hov-bd:  rgba(var(--dark-ink-rgb), .26);
    --d-o-shadow:     0 12px 34px -14px rgba(var(--o-deep-rgb), .7);
    --d-o-shadow-hov: 0 20px 50px -16px rgba(var(--o-deep-rgb), .85);
    --d-tint:        rgba(var(--o-light-rgb), .1);
    --d-tint-border: rgba(var(--o-light-rgb), .24);
    /* One step below the canvas, mixed rather than declared, so the inverted
       band tracks the canvas token instead of pinning its own near-black. */
    --d-inv-bg:  color-mix(in srgb, var(--color-night) 78%, #000);
    --d-inv-fg:  var(--color-night-ink);
    --d-inv-fg2: rgba(var(--dark-ink-rgb), .66);
    --d-sel-bg:  rgba(var(--o-light-rgb), .32);
    --d-sel-fg:  var(--color-surface);
    --d-sun:  block;
    --d-moon: none;

    /* ---- Non-colour ----------------------------------------------------- */
    --shell: min(1200px, 100% - 3rem);
    --ease: cubic-bezier(.22, 1, .36, 1);
    --nav-h: 80px; /* island height incl. its outer padding — anchor offset */

    /* The product's two families, self-hosted and shared with the dashboard:
       the sans carries body and UI, the serif carries the display range. Both
       are verified per glyph for Tajik by scripts/verify-font-coverage.mjs. */
    --font: Geist, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --serif: 'Source Serif 4', 'Iowan Old Style', 'Palatino Linotype', Palatino, Georgia, ui-serif, serif;

    --t-xs:   .8125rem;
    --t-sm:   .875rem;
    --t-base: .9375rem;
    --t-md:   1rem;
    --t-lg:   1.0625rem;

    --section-y: clamp(4.5rem, 9vw, 7.5rem);
}

/* ═══════════════════════════════════════════════════════════════════════
   2 · Mapping
   ═══════════════════════════════════════════════════════════════════════ */
:root, :root.light {
    --bg: var(--l-bg);              --bg-alt: var(--l-bg-alt);
    --fg: var(--l-fg);              --fg-2: var(--l-fg-2);
    --fg-3: var(--l-fg-3);          --fg-4: var(--l-fg-4);
    --line: var(--l-line);          --line-2: var(--l-line-2);
    --accent: var(--l-accent);      --surf: var(--l-surf);
    --surf-2: var(--l-surf-2);      --sunken: var(--l-sunken);
    --dot-fill: var(--l-dot-fill);  --nav-bg: var(--l-nav);
    --grain-blend: var(--l-grain-blend); --grain-op: var(--l-grain-op);
    --glow-1: var(--l-glow-1);      --glow-2: var(--l-glow-2);
    --glow-3: var(--l-glow-3);      --glow-op: var(--l-glow-op);
    --grid-line: var(--l-grid);     --rule: var(--l-rule);
    --num-grad: var(--l-num-grad);  --sheen: var(--l-sheen);
    --shadow-sm: var(--l-shadow-sm); --shadow: var(--l-shadow);
    --shadow-lg: var(--l-shadow-lg);
    --bubble: var(--l-bubble);      --bubble-border: var(--l-bubble-border);
    --ghost-bg: var(--l-ghost-bg);  --ghost-border: var(--l-ghost-border);
    --ghost-hov: var(--l-ghost-hov); --ghost-hov-bd: var(--l-ghost-hov-bd);
    --o-shadow: var(--l-o-shadow);  --o-shadow-hov: var(--l-o-shadow-hov);
    --tint: var(--l-tint);          --tint-border: var(--l-tint-border);
    --inv-bg: var(--l-inv-bg);      --inv-fg: var(--l-inv-fg);
    --inv-fg2: var(--l-inv-fg2);
    --sel-bg: var(--l-sel-bg);      --sel-fg: var(--l-sel-fg);
    --sun-display: var(--l-sun);    --moon-display: var(--l-moon);
}

@media (prefers-color-scheme: dark) {
    :root:not(.light) {
        --bg: var(--d-bg);              --bg-alt: var(--d-bg-alt);
        --fg: var(--d-fg);              --fg-2: var(--d-fg-2);
        --fg-3: var(--d-fg-3);          --fg-4: var(--d-fg-4);
        --line: var(--d-line);          --line-2: var(--d-line-2);
        --accent: var(--d-accent);      --surf: var(--d-surf);
        --surf-2: var(--d-surf-2);      --sunken: var(--d-sunken);
        --dot-fill: var(--d-dot-fill);  --nav-bg: var(--d-nav);
        --grain-blend: var(--d-grain-blend); --grain-op: var(--d-grain-op);
        --glow-1: var(--d-glow-1);      --glow-2: var(--d-glow-2);
        --glow-3: var(--d-glow-3);      --glow-op: var(--d-glow-op);
        --grid-line: var(--d-grid);     --rule: var(--d-rule);
        --num-grad: var(--d-num-grad);  --sheen: var(--d-sheen);
        --shadow-sm: var(--d-shadow-sm); --shadow: var(--d-shadow);
        --shadow-lg: var(--d-shadow-lg);
        --bubble: var(--d-bubble);      --bubble-border: var(--d-bubble-border);
        --ghost-bg: var(--d-ghost-bg);  --ghost-border: var(--d-ghost-border);
        --ghost-hov: var(--d-ghost-hov); --ghost-hov-bd: var(--d-ghost-hov-bd);
        --o-shadow: var(--d-o-shadow);  --o-shadow-hov: var(--d-o-shadow-hov);
        --tint: var(--d-tint);          --tint-border: var(--d-tint-border);
        --inv-bg: var(--d-inv-bg);      --inv-fg: var(--d-inv-fg);
        --inv-fg2: var(--d-inv-fg2);
        --sel-bg: var(--d-sel-bg);      --sel-fg: var(--d-sel-fg);
        --sun-display: var(--d-sun);    --moon-display: var(--d-moon);
    }
}

:root.dark {
    --bg: var(--d-bg);              --bg-alt: var(--d-bg-alt);
    --fg: var(--d-fg);              --fg-2: var(--d-fg-2);
    --fg-3: var(--d-fg-3);          --fg-4: var(--d-fg-4);
    --line: var(--d-line);          --line-2: var(--d-line-2);
    --accent: var(--d-accent);      --surf: var(--d-surf);
    --surf-2: var(--d-surf-2);      --sunken: var(--d-sunken);
    --dot-fill: var(--d-dot-fill);  --nav-bg: var(--d-nav);
    --grain-blend: var(--d-grain-blend); --grain-op: var(--d-grain-op);
    --glow-1: var(--d-glow-1);      --glow-2: var(--d-glow-2);
    --glow-3: var(--d-glow-3);      --glow-op: var(--d-glow-op);
    --grid-line: var(--d-grid);     --rule: var(--d-rule);
    --num-grad: var(--d-num-grad);  --sheen: var(--d-sheen);
    --shadow-sm: var(--d-shadow-sm); --shadow: var(--d-shadow);
    --shadow-lg: var(--d-shadow-lg);
    --bubble: var(--d-bubble);      --bubble-border: var(--d-bubble-border);
    --ghost-bg: var(--d-ghost-bg);  --ghost-border: var(--d-ghost-border);
    --ghost-hov: var(--d-ghost-hov); --ghost-hov-bd: var(--d-ghost-hov-bd);
    --o-shadow: var(--d-o-shadow);  --o-shadow-hov: var(--d-o-shadow-hov);
    --tint: var(--d-tint);          --tint-border: var(--d-tint-border);
    --inv-bg: var(--d-inv-bg);      --inv-fg: var(--d-inv-fg);
    --inv-fg2: var(--d-inv-fg2);
    --sel-bg: var(--d-sel-bg);      --sel-fg: var(--d-sel-fg);
    --sun-display: var(--d-sun);    --moon-display: var(--d-moon);
}

/* ═══════════════════════════════════════════════════════════════════════
   3 · Base — light by default; bold is rationed
   ═══════════════════════════════════════════════════════════════════════ */
body {
    background: var(--bg);
    color: var(--fg);
    font-family: var(--font);
    font-size: var(--t-md);
    font-weight: 400;
    line-height: 1.6;
    letter-spacing: -.004em;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    overflow-x: hidden;
    transition: background-color .5s var(--ease), color .5s var(--ease);
}

::selection { background: var(--sel-bg); color: var(--sel-fg); }
a { color: inherit; text-decoration: none; }
img { max-width: 100%; display: block; }
button { font: inherit; color: inherit; }

:where(a, button, summary, [tabindex]:not([tabindex='-1'])):focus-visible {
    outline: 2px solid var(--o-3);
    outline-offset: 3px;
    border-radius: 6px;
}
[id] { scroll-margin-top: calc(var(--nav-h) + 1.5rem); }

.skip-link {
    position: absolute; left: 50%; top: .6rem; translate: -50% -200%;
    z-index: 100; padding: .7rem 1.2rem; border-radius: 100px;
    background: var(--o-3); color: var(--on-orange); font-weight: 500; font-size: var(--t-sm);
    transition: translate .3s var(--ease);
}
.skip-link:focus-visible { translate: -50% 0; }
.sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}

body::after {
    content: ''; position: fixed; inset: -50%; pointer-events: none; z-index: 9;
    opacity: var(--grain-op); mix-blend-mode: var(--grain-blend);
    transition: opacity .5s var(--ease);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='.5'/%3E%3C/svg%3E");
}

.shell { width: var(--shell); margin-inline: auto; }

/* ═══════════════════════════════════════════════════════════════════════
   4 · Type — two voices

   The display range (hero, section titles, the pull quote, the big figures)
   is set in the serif; body, UI, buttons and labels stay on the sans. Weight
   drops to 400 in the serif and the tracking eases back from the grotesque's
   -.035em: a book serif is drawn with its spacing built in, and pulling it as
   tight as a sans makes the serifs collide at display size.
   ═══════════════════════════════════════════════════════════════════════ */
.display {
    font-family: var(--serif);
    font-size: clamp(2.6rem, 7.4vw, 5.75rem);
    line-height: 1.06;
    letter-spacing: -.018em;
    font-weight: 400;
    margin: 0;
    text-wrap: balance;
}
.display .grad {
    background: var(--o-grad);
    -webkit-background-clip: text; background-clip: text; color: transparent;
}

/* ── the hero lockup ────────────────────────────────────────────────────
   "Velora Agents" was set in the page's UI sans at one flat ink colour, so
   it read as a heading that happens to contain the name, not as the name.
   Both words now carry the same wordmark treatment — the serif at display
   size, cut from a vertical ink gradient so the letterforms have depth
   instead of a single flat fill — so the two read as one name rather than
   a name with a label bolted on. The single saturated colour on the page
   stays on the rotating line below, so the two never compete. */
.wordmark {
    display: block;
    font-family: var(--serif);
    /* 400, not 500: the fallback stacks (Iowan, Palatino, Georgia) have no
       500, so browsers were already rendering this at regular. Naming it
       keeps the lockup identical now that a real 500-capable webfont is
       loaded, instead of silently getting heavier. */
    font-weight: 400; letter-spacing: -.022em;
    background: linear-gradient(178deg, var(--fg) 22%, var(--fg-2) 96%);
    -webkit-background-clip: text; background-clip: text; color: transparent;
    /* background-clip:text paints only inside the element's own box, and the
       display line-height (1.02) cuts that box off at the baseline — which
       sliced the tail off the serif's deep "g" descender. The padding grows
       the paint box; the negative margin gives the space straight back so
       the rotating line below does not move. */
    padding-bottom: .2em; margin-bottom: -.2em;
}
/* The gap is set here, not with a text space: the serif's own word space is
   loose at display size and would break the lockup apart. */
.wm-suffix { margin-left: .26em; }
h2.title {
    font-family: var(--serif);
    font-size: clamp(1.85rem, 3.9vw, 2.85rem);
    line-height: 1.2; letter-spacing: -.014em; font-weight: 400;
    margin: 0; text-wrap: balance;
}
.lede {
    font-size: clamp(1.02rem, 1.25vw, 1.12rem);
    line-height: 1.68; color: var(--fg-2); letter-spacing: -.006em;
    margin: 0; max-width: 40rem; font-weight: 400; text-wrap: pretty;
}
.eyebrow {
    font-size: var(--t-xs); text-transform: uppercase; letter-spacing: .14em;
    color: var(--accent); font-weight: 500; margin: 0;
}
@media (forced-colors: active) {
    .display .grad, .stat .n, .wordmark {
        background: none !important; -webkit-text-fill-color: currentColor;
        color: currentColor !important;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   5 · Atoms
   ═══════════════════════════════════════════════════════════════════════ */
.pill {
    display: inline-flex; align-items: center; gap: .55rem;
    padding: .38rem .85rem .38rem .5rem;
    border: 1px solid var(--tint-border); border-radius: 100px;
    background: var(--tint); font-size: var(--t-sm); color: var(--fg-2);
}
.pill .dot {
    width: 7px; height: 7px; border-radius: 50%; margin-left: .3rem;
    background: var(--o-2); animation: pulse 2.6s var(--ease) infinite;
}
@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 3px rgba(var(--o-rgb), .26); }
    50%      { box-shadow: 0 0 0 8px rgba(var(--o-rgb), 0); }
}

.btn {
    --mx: 50%; --my: 50%;
    position: relative; display: inline-flex; align-items: center; justify-content: center;
    gap: .5rem; padding: .88rem 1.5rem; border-radius: 100px;
    border: 1px solid transparent; font-weight: 500; font-size: var(--t-base);
    cursor: pointer; isolation: isolate; overflow: hidden;
    transition: transform .45s var(--ease), box-shadow .45s var(--ease),
                background-color .4s var(--ease), border-color .4s var(--ease);
}
.btn:active { transform: translateY(0); }
.btn-o { color: var(--on-orange); background: var(--o-grad); box-shadow: var(--o-shadow); }
.btn-o::before {
    content: ''; position: absolute; inset: 0; z-index: -1;
    background: radial-gradient(180px circle at var(--mx) var(--my), rgba(255,255,255,.42), transparent 65%);
    opacity: 0; transition: opacity .35s;
}
.btn-ghost {
    color: var(--fg); border-color: var(--ghost-border);
    background: var(--ghost-bg); box-shadow: var(--shadow-sm);
}
.btn-ghost .ico { color: var(--accent); }
@media (hover: hover) {
    .btn:hover { transform: translateY(-2px); }
    .btn-o:hover::before { opacity: 1; }
    .btn-o:hover { box-shadow: var(--o-shadow-hov); }
    .btn-ghost:hover { border-color: var(--ghost-hov-bd); background: var(--ghost-hov); }
}

/* ── icons: alive, but only just ───────────────────────────────────────
   Every glyph draws itself once when it scrolls in, then answers to hover
   with a small, specific motion rather than a generic wobble. */
.ico { width: 18px; height: 18px; flex: none; }
.ico path, .ico circle, .ico rect, .ico line, .ico polyline {
    vector-effect: non-scaling-stroke;
}
.glyph {
    width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center;
    background: var(--tint); border: 1px solid var(--tint-border);
    color: var(--accent);
    transition: transform .5s var(--ease), background-color .5s var(--ease);
}
.glyph .ico { width: 20px; height: 20px; }
/* self-drawing stroke on reveal */
.glyph .ico [data-draw] {
    stroke-dasharray: var(--len, 80);
    stroke-dashoffset: var(--len, 80);
    transition: stroke-dashoffset 1s var(--ease);
}
.is-in .glyph .ico [data-draw] { stroke-dashoffset: 0; }
@media (hover: hover) {
    .cell:hover .glyph, .flow-node:hover .glyph { transform: translateY(-3px) rotate(-4deg); }
    .cell:hover .glyph .ico .spin { animation: spin 1.6s linear infinite; transform-origin: center; }
    .cell:hover .glyph .ico .nudge { animation: nudge .9s var(--ease) infinite; }
    .cell:hover .glyph .ico .blip { animation: blip 1.1s var(--ease) infinite; }
}
@keyframes spin  { to { transform: rotate(360deg); } }
@keyframes nudge { 0%,100% { transform: translateX(0); } 50% { transform: translateX(2.5px); } }
@keyframes blip  { 0%,100% { opacity: 1; } 50% { opacity: .25; } }

/* ═══════════════════════════════════════════════════════════════════════
   6 · Header — a floating island: mark left, links optically centred,
       tools right; no auth buttons

   The bar is not a full-bleed band but a capsule that hovers over the page:
   at the top of the document it is invisible (only the marks show), and on
   scroll it materialises as a frosted, bordered island. Three explicit grid
   columns (1fr | auto | 1fr) keep the links centred on the page's optical
   centre no matter how wide the mark or the tool cluster get — a flex row
   with margin-auto would drift as soon as the language label changes length
   (RU/TG/EN are all different widths).

   One row, one baseline: every child of .nav-in is 34px tall and centred, so
   the mark, the links, the language pill and the two round buttons all share
   the same optical line. Below 820px the links move into a drawer that drops
   out of the island, and the language + theme controls stay in the bar next
   to the burger — they are one tap either way.
   ═══════════════════════════════════════════════════════════════════════ */
.nav {
    position: fixed; inset: 0 0 auto; z-index: 60; padding: .85rem 0;
    transition: padding .5s var(--ease);
}
.nav-in {
    display: grid; grid-template-columns: 1fr auto 1fr; align-items: center;
    gap: 1rem; min-height: 34px;
    padding: .5rem .5rem .5rem .9rem; border-radius: 100px;
    border: 1px solid transparent; background: transparent;
    transition: background-color .5s, border-color .5s, box-shadow .5s,
                backdrop-filter .5s, width .5s var(--ease);
}
.nav.is-stuck .nav-in, .nav.is-open .nav-in {
    background: var(--nav-bg);
    backdrop-filter: blur(20px) saturate(1.5);
    -webkit-backdrop-filter: blur(20px) saturate(1.5);
    border-color: var(--line);
    box-shadow: var(--shadow), 0 0 0 1px var(--line-2);
}
/* Frosted glass is right for a bar the page slides under, and wrong for an
   open drawer: the hero would read straight through the menu (and on engines
   without backdrop-filter it is simply transparent). While the drawer is open
   the island goes to the solid surface colour, and its bottom corners square
   off so it reads as one piece with the panel below it. */
.nav.is-open .nav-in {
    background: var(--surf);
    backdrop-filter: none; -webkit-backdrop-filter: none;
    border-radius: 26px;
}

.brand {
    grid-column: 1; justify-self: start;
    display: inline-flex; align-items: center; flex: none;
    transition: opacity .3s var(--ease), transform .4s var(--ease);
}
.brand img { width: 30px; height: 30px; }
@media (hover: hover) { .brand:hover { opacity: .8; transform: scale(1.06); } }

/* Centre column. Links are hit-targets, not bare text: each sits in its own
   pill so the hover state has a shape and the row keeps an even rhythm. */
.nav-links {
    grid-column: 2; justify-self: center;
    display: flex; align-items: center; gap: .15rem;
}
.nav-links a {
    display: inline-flex; align-items: center; height: 34px; padding: 0 .85rem;
    border-radius: 100px;
    font-size: var(--t-base); font-weight: 450; letter-spacing: -.002em;
    color: var(--fg-2); position: relative;
    transition: color .3s, background-color .3s var(--ease);
}
/* The gradient tick stays, shrunk to a centred underline inside the pill —
   it marks the hovered item without fighting the pill's own fill. */
.nav-links a::after {
    content: ''; position: absolute; left: 50%; translate: -50% 0; bottom: 5px;
    height: 1.5px; width: 0; background: var(--o-grad); border-radius: 2px;
    transition: width .4s var(--ease);
}
.nav-tools {
    grid-column: 3; justify-self: end;
    display: flex; align-items: center; gap: .4rem; flex: none;
}
@media (hover: hover) {
    .nav-links a:hover { color: var(--fg); background: var(--sunken); }
    .nav-links a:hover::after { width: calc(100% - 1.7rem); }
}

/* ── shared shape for the two pill-ish controls ────────────────────── */
.nav-btn {
    flex: none; border-radius: 100px; cursor: pointer;
    border: 1px solid var(--ghost-border); background: var(--ghost-bg);
    box-shadow: var(--shadow-sm); color: var(--fg-2);
    transition: background-color .35s var(--ease), border-color .35s var(--ease), color .35s var(--ease);
}
@media (hover: hover) {
    .nav-btn:hover { background: var(--ghost-hov); border-color: var(--ghost-hov-bd); color: var(--fg); }
}

/* ── language: a vertical roll-down, not a row ─────────────────────── */
.lang { position: relative; flex: none; }
.lang > summary {
    list-style: none; cursor: pointer;
    display: flex; align-items: center; gap: .4rem;
    height: 34px; padding: 0 .75rem;
    font-size: var(--t-sm); font-weight: 500; letter-spacing: -.004em;
}
.lang > summary::-webkit-details-marker { display: none; }
.lang > summary .globe { width: 15px; height: 15px; flex: none; opacity: .85; }
.lang > summary .chev { width: 13px; height: 13px; flex: none; transition: transform .4s var(--ease); }
.lang[open] > summary { color: var(--fg); border-color: var(--ghost-hov-bd); background: var(--ghost-hov); }
.lang[open] > summary .chev { transform: rotate(180deg); }

.lang-menu {
    position: absolute; top: calc(100% + .5rem); right: 0; z-index: 50;
    min-width: 11.5rem; padding: .35rem;
    border: 1px solid var(--line); border-radius: 14px;
    background: var(--surf); box-shadow: var(--shadow), 0 0 0 1px var(--line-2);
    transform-origin: top right;
    animation: roll .3s var(--ease);
    overflow: hidden;
}
/* <details> has no closing state of its own; the script holds the panel open
   for one animation before dropping the attribute. */
.lang-menu.is-closing { animation: unroll .18s var(--ease) forwards; pointer-events: none; }
@keyframes roll {
    from { opacity: 0; transform: translateY(-8px) scaleY(.86); }
    to   { opacity: 1; transform: none; }
}
@keyframes unroll {
    from { opacity: 1; transform: none; }
    to   { opacity: 0; transform: translateY(-6px) scaleY(.9); }
}
.lang-menu a {
    display: flex; align-items: center; gap: .6rem;
    padding: .55rem .7rem; border-radius: 10px;
    font-size: var(--t-base); color: var(--fg-2);
    transition: background-color .25s, color .25s;
}
.lang-menu a .tick { width: 15px; height: 15px; opacity: 0; color: var(--accent); flex: none; }
.lang-menu a[aria-current='true'] { color: var(--fg); font-weight: 500; }
.lang-menu a[aria-current='true'] .tick { opacity: 1; }
@media (hover: hover) { .lang-menu a:hover { background: var(--sunken); color: var(--fg); } }

.theme-toggle {
    width: 34px; height: 34px; display: none; place-items: center; border-radius: 50%;
}
.js .theme-toggle { display: grid; }
.theme-toggle .ico { width: 15px; height: 15px; }
.theme-toggle .ic-sun  { display: var(--sun-display); }
.theme-toggle .ic-moon { display: var(--moon-display); }

/* ── burger + drawer: mobile only, and only with JS ─────────────────── */
.burger {
    width: 34px; height: 34px; display: none; place-items: center; border-radius: 50%;
}
.burger .bars { display: block; width: 15px; height: 11px; position: relative; }
.burger .bars span {
    position: absolute; left: 0; width: 100%; height: 1.6px; border-radius: 2px;
    background: currentColor;
    transition: transform .4s var(--ease), opacity .2s var(--ease);
}
.burger .bars span:nth-child(1) { top: 0; }
.burger .bars span:nth-child(2) { top: 4.7px; }
.burger .bars span:nth-child(3) { top: 9.4px; }
.burger[aria-expanded='true'] .bars span:nth-child(1) { transform: translateY(4.7px) rotate(45deg); }
.burger[aria-expanded='true'] .bars span:nth-child(2) { opacity: 0; }
.burger[aria-expanded='true'] .bars span:nth-child(3) { transform: translateY(-4.7px) rotate(-45deg); }

/* The panel is a card hung under the island, matched to its width and radius
   rather than a full-bleed sheet — the header stays one object on mobile. */
.drawer {
    display: none; position: absolute; top: calc(100% - .35rem);
    left: 50%; translate: -50% 0; width: var(--shell);
    border: 1px solid var(--line); border-radius: 26px;
    background: var(--surf);
    box-shadow: var(--shadow-lg), 0 0 0 1px var(--line-2);
    opacity: 0; visibility: hidden; transform: translateY(-10px) scale(.98);
    transform-origin: top center;
    transition: opacity .3s var(--ease), transform .38s var(--ease), visibility .38s;
}
.nav.is-open .drawer { opacity: 1; visibility: visible; transform: none; }
.drawer-in { display: flex; flex-direction: column; padding: .5rem 0 .7rem; }
.drawer-in a {
    padding: .8rem .9rem; border-radius: 14px;
    font-size: var(--t-lg); font-weight: 450;
    color: var(--fg-2);
    transition: color .3s, background-color .3s var(--ease), padding-left .3s var(--ease);
}
@media (hover: hover) { .drawer-in a:hover { color: var(--fg); background: var(--sunken); padding-left: 1.25rem; } }
.drawer-in a:active { color: var(--accent); background: var(--sunken); }

@media (max-width: 820px) {
    .nav-links { display: none; }
    .js .burger { display: grid; }
    .js .drawer { display: block; }
    /* No centre column to hold: mark hard left, tools hard right. */
    .nav-in { grid-template-columns: auto 1fr; }
    .nav-tools { grid-column: 2; }
}
@media (max-width: 420px) {
    :root { --shell: min(1200px, 100% - 2rem); }
    .nav-in { gap: .5rem; padding: .4rem .4rem .4rem .75rem; }
    .nav-tools { gap: .3rem; }
    .lang > summary { padding: 0 .55rem; }
}

/* ═══════════════════════════════════════════════════════════════════════
   7 · Hero — off-centre, not a centred stack
   ═══════════════════════════════════════════════════════════════════════ */
.hero { position: relative; padding: clamp(7rem, 14vh, 9.5rem) 0 0; overflow: hidden; }
.glows { position: absolute; inset: -25% -10% auto; height: 120vh; z-index: 0; pointer-events: none; }
.glow { position: absolute; border-radius: 50%; filter: blur(90px); opacity: var(--glow-op); will-change: transform; }
.glow-a {
    width: 58vw; height: 58vw; max-width: 760px; max-height: 760px; left: 46%; top: 2%; translate: -50% 0;
    background: radial-gradient(circle at 42% 40%, var(--glow-1), transparent 68%);
    animation: drift-a 24s ease-in-out infinite;
}
.glow-b {
    width: 42vw; height: 42vw; max-width: 580px; max-height: 580px; left: 8%; top: 24%;
    background: radial-gradient(circle at 50% 50%, var(--glow-2), transparent 66%);
    animation: drift-b 29s ease-in-out infinite;
}
.glow-c {
    width: 38vw; height: 38vw; max-width: 520px; max-height: 520px; right: 4%; top: 8%;
    background: radial-gradient(circle at 50% 50%, var(--glow-3), transparent 68%);
    animation: drift-c 33s ease-in-out infinite;
}
@keyframes drift-a { 50% { transform: translate3d(0, 5%, 0) scale(1.1); } }
@keyframes drift-b { 50% { transform: translate3d(8%, -6%, 0) scale(1.14); } }
@keyframes drift-c { 50% { transform: translate3d(-10%, 7%, 0) scale(.9); } }
.grid-bg {
    position: absolute; inset: 0; z-index: 0; pointer-events: none;
    background-image: linear-gradient(var(--grid-line) 1px, transparent 1px),
                      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 76px 76px;
    -webkit-mask-image: radial-gradient(ellipse 80% 55% at 40% 28%, #000 18%, transparent 72%);
    mask-image: radial-gradient(ellipse 80% 55% at 40% 28%, #000 18%, transparent 72%);
}

/* Asymmetric: copy sits in the left ~62%, meta rail on the right. */
.hero-in { position: relative; z-index: 2; display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(0, .85fr); gap: 3rem; align-items: end; }
@media (max-width: 940px) { .hero-in { grid-template-columns: 1fr; gap: 2.2rem; align-items: start; } }
.hero-copy > * + * { margin-top: 1.5rem; }
.hero-copy .display { margin-top: 1.7rem; }
.hero-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 2.2rem !important; }

/* typewriter — the rotating line reads as the sentence the wordmark starts,
   so it keeps the UI sans (the serif is the name's alone) and sits a hair
   below the lockup's baseline. */
.type-line {
    /* Single-cell grid: every phrase ghost and the typed output stack in the
       same cell, so the box is always sized to the LONGEST phrase in the
       current locale — at whatever width it happens to wrap to. Nothing below
       the headline moves while the line types and erases.
       Items stay full-width (the default stretch) so each one wraps exactly
       where the old block-level line did — and so the gradient still ramps
       across the whole column rather than resizing with the typed text. */
    display: grid; align-items: start;
    min-height: 1.05em; margin-top: .12em;
    /* Deliberately the SANS while the name above is the serif. The lockup is
       the name; this is a claim being typed about it, and the change of voice
       is what keeps the two from reading as one run-on headline. Smaller and
       lighter, too: the wordmark is the loudest thing in the hero. */
    font-family: var(--font);
    font-size: .74em; font-weight: 400; letter-spacing: -.028em;
}
.type-line > * { grid-area: 1 / 1; max-width: 100%; }
/* Space reservation only — never painted, never read, never hit-tested. The
   trailing pad is the caret's slot, so the last character can't force a wrap
   the ghosts didn't account for. */
.type-ghost {
    visibility: hidden; pointer-events: none; user-select: none; -webkit-user-select: none;
    padding-right: .14em;
}
.type-caret {
    display: inline-block; width: .06em; height: .82em; margin-left: .06em;
    background: var(--o-2); vertical-align: -.06em; border-radius: 2px;
    animation: caret 1.05s steps(1) infinite;
}
@keyframes caret { 0%, 45% { opacity: 1; } 46%, 100% { opacity: 0; } }

/* right-hand rail — a list, not a centred row */
.hero-rail { display: grid; gap: .1rem; padding-bottom: .4rem; }
.hero-rail li {
    display: flex; align-items: flex-start; gap: .7rem;
    padding: .8rem 0; border-top: 1px solid var(--line-2);
    font-size: var(--t-sm); color: var(--fg-3);
}
.hero-rail li:first-child { border-top: 0; }
.hero-rail .ico { width: 15px; height: 15px; color: var(--accent); margin-top: .15rem; }
.hero-rail ul { list-style: none; margin: 0; padding: 0; }

/* ═══════════════════════════════════════════════════════════════════════
   8 · Floating product frame
   ═══════════════════════════════════════════════════════════════════════ */
.stage { perspective: 2000px; margin-top: clamp(3rem, 6vw, 4.5rem); padding-bottom: clamp(4rem, 8vw, 6rem); position: relative; z-index: 2; }
.frame {
    width: var(--shell); margin-inline: auto; border-radius: 20px;
    border: 1px solid var(--line); background: var(--surf);
    box-shadow: var(--shadow-lg);
    transform: rotateX(12deg) scale(.97); transform-origin: 50% 0;
    transition: transform 1.3s var(--ease), box-shadow .5s var(--ease);
    overflow: hidden;
}
.frame.is-flat { transform: none; }
.frame-bar {
    display: flex; align-items: center; gap: .5rem; padding: .7rem 1.05rem;
    border-bottom: 1px solid var(--line-2); background: var(--sunken);
}
.frame-bar i { width: 10px; height: 10px; border-radius: 50%; background: var(--dot-fill); }
.frame-bar .addr {
    margin-left: .6rem; font-size: var(--t-xs); color: var(--fg-3);
    padding: .18rem .65rem; border-radius: 100px;
    border: 1px solid var(--line-2); background: var(--surf);
}
.frame-body { display: grid; grid-template-columns: 1.4fr .9fr; }
@media (max-width: 860px) { .frame-body { grid-template-columns: 1fr; } }

.chat { padding: 1.35rem; display: flex; flex-direction: column; gap: .7rem; border-right: 1px solid var(--line-2); min-height: 400px; }
@media (max-width: 860px) { .chat { border-right: 0; border-bottom: 1px solid var(--line-2); min-height: 340px; } }
.chat-head { display: flex; align-items: center; gap: .7rem; padding-bottom: .8rem; border-bottom: 1px solid var(--line-2); }
.avatar {
    width: 34px; height: 34px; border-radius: 50%; background: var(--o-grad);
    display: grid; place-items: center; font-weight: 500; color: var(--on-orange); font-size: var(--t-sm); flex: none;
    box-shadow: 0 6px 18px -8px rgba(var(--o-deep-rgb), .8);
}
.chat-head b { font-size: var(--t-base); font-weight: 500; display: block; }
.chat-head small { font-size: var(--t-xs); color: var(--fg-3); }
/* The transcript is height-reserved the same way the hero line is: a hidden
   copy of the finished conversation shares one grid cell with the live list, so
   the panel is already at its final height on the first frame. Bubbles arrive
   into a box that never grows, and the loop's reset doesn't collapse it. */
.msgs-frame { display: grid; flex: 1; min-height: 0; }
.msgs-frame > * { grid-area: 1 / 1; }
.msgs-ghost { visibility: hidden; pointer-events: none; user-select: none; -webkit-user-select: none; }
.msgs-ghost .msg { opacity: 1; transform: none; animation: none; }
.msgs { display: flex; flex-direction: column; gap: .5rem; justify-content: flex-end; }
.msg {
    max-width: 78%; padding: .62rem .88rem; border-radius: 15px;
    font-size: var(--t-base); line-height: 1.45; opacity: 0;
    transform: translateY(10px) scale(.98); animation: msg-in .6s var(--ease) forwards;
}
@keyframes msg-in { to { opacity: 1; transform: none; } }
.msg.them { align-self: flex-start; background: var(--bubble); border: 1px solid var(--bubble-border); border-bottom-left-radius: 5px; }
.msg.us { align-self: flex-end; background: var(--o-grad); color: var(--on-orange); border-bottom-right-radius: 5px; box-shadow: var(--o-shadow); }
.msg.us.is-typing { background: var(--bubble); border: 1px solid var(--bubble-border); box-shadow: none; }
.typing { display: inline-flex; gap: 4px; padding: .2rem 0; }
.typing i { width: 6px; height: 6px; border-radius: 50%; background: var(--fg-3); animation: bounce 1.2s infinite; }
.typing i:nth-child(2) { animation-delay: .15s; }
.typing i:nth-child(3) { animation-delay: .3s; }
@keyframes bounce { 0%,60%,100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-4px); opacity: 1; } }
.composer {
    display: flex; align-items: center; gap: .6rem; margin-top: .4rem;
    padding: .52rem .6rem .52rem 1rem; border: 1px solid var(--line);
    border-radius: 100px; background: var(--sunken); font-size: var(--t-sm); color: var(--fg-4);
}
.composer .send { margin-left: auto; width: 28px; height: 28px; border-radius: 50%; background: var(--o-grad); display: grid; place-items: center; flex: none; color: var(--on-orange); }
.composer .send .ico { width: 13px; height: 13px; }

.side { padding: 1.35rem; display: flex; flex-direction: column; gap: .8rem; background: var(--sunken); }
.side-label { font-size: var(--t-xs); text-transform: uppercase; letter-spacing: .13em; color: var(--fg-3); font-weight: 500; }
.lead-card {
    border: 1px solid var(--tint-border); border-radius: 15px; padding: 1rem;
    background: var(--tint); opacity: 0; transform: translateY(12px);
    transition: opacity .7s var(--ease), transform .7s var(--ease);
}
.lead-card.is-in { opacity: 1; transform: none; }
.lead-title { margin: 0 0 .1rem; font-size: var(--t-base); font-weight: 500; }
.lead-card .stamp { font-size: var(--t-xs); color: var(--accent); display: flex; align-items: center; gap: .4rem; margin-bottom: .65rem; }
.lead-row { display: flex; justify-content: space-between; gap: 1rem; font-size: var(--t-sm); padding: .3rem 0; border-top: 1px solid var(--line-2); }
.lead-row span:first-child { color: var(--fg-3); }
.lead-row span:last-child { color: var(--fg); text-align: right; }
.mini {
    border: 1px solid var(--line); border-radius: 13px; padding: .7rem .8rem;
    background: var(--surf); display: flex; align-items: center; gap: .7rem;
    font-size: var(--t-sm); color: var(--fg-2);
}
.mini .ic { width: 30px; height: 30px; border-radius: 9px; flex: none; display: grid; place-items: center; background: var(--tint); border: 1px solid var(--tint-border); color: var(--accent); }
.mini .ic .ico { width: 15px; height: 15px; }

/* ═══════════════════════════════════════════════════════════════════════
   9 · Sections
   ═══════════════════════════════════════════════════════════════════════ */
section { position: relative; }
.band { padding: var(--section-y) 0; }
.band + .band { padding-top: 0; }
.band-head { display: grid; gap: .85rem; max-width: 40rem; margin-bottom: clamp(2.5rem, 5vw, 3.75rem); }

/* ── bento: four genuinely different cell sizes ─────────────────────── */
.bento { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1rem; }
.cell {
    position: relative; padding: 1.7rem; border-radius: 18px;
    border: 1px solid var(--line); background: var(--surf);
    box-shadow: var(--shadow-sm); overflow: hidden;
    transition: border-color .5s var(--ease), transform .5s var(--ease), box-shadow .5s var(--ease);
}
.cell::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background: radial-gradient(360px circle at var(--mx, 50%) var(--my, 50%), var(--sheen), transparent 60%);
    opacity: 0; transition: opacity .45s;
}
@media (hover: hover) {
    .cell:hover { border-color: var(--tint-border); transform: translateY(-4px); box-shadow: var(--shadow); }
    .cell:hover::before { opacity: 1; }
}
.cell h3 { margin: 1rem 0 .5rem; font-size: var(--t-lg); font-weight: 500; letter-spacing: -.014em; line-height: 1.35; }
.cell p { margin: 0; font-size: var(--t-base); color: var(--fg-2); line-height: 1.62; }
/* the four sizes */
.c-hero  { grid-column: span 7; grid-row: span 2; background: var(--bg-alt); }
.c-hero h3 { font-size: clamp(1.25rem, 2vw, 1.6rem); letter-spacing: -.022em; }
.c-tall  { grid-column: span 5; grid-row: span 3; }
.c-wide  { grid-column: span 7; }
.c-small { grid-column: span 4; }
.c-mid   { grid-column: span 3; }
@media (max-width: 1000px) {
    .bento { grid-template-columns: repeat(6, 1fr); }
    .c-hero, .c-tall, .c-wide { grid-column: span 6; grid-row: auto; }
    .c-small, .c-mid { grid-column: span 3; }
}
@media (max-width: 680px) {
    .bento { grid-template-columns: 1fr; }
    .c-hero, .c-tall, .c-wide, .c-small, .c-mid { grid-column: 1 / -1; grid-row: auto; }
}
.dial { display: flex; gap: .4rem; margin-top: 1.1rem; }
.dial b {
    flex: 1; text-align: center; font-size: var(--t-xs); font-weight: 400;
    padding: .4rem .2rem; border-radius: 8px; border: 1px solid var(--line);
    color: var(--fg-3); background: var(--sunken);
}
.dial b.on { color: var(--on-orange); font-weight: 500; background: var(--o-grad); border-color: transparent; }
.chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: 1.1rem; }
.chips i {
    font-style: normal; font-size: var(--t-xs); padding: .3rem .7rem; border-radius: 100px;
    border: 1px solid var(--line); color: var(--fg-2); background: var(--surf);
}

/* ── sources: a two-column split with offset rails (replaces the marquee) ── */
.split { display: grid; grid-template-columns: 1fr 1fr; gap: clamp(1.5rem, 4vw, 3.5rem); align-items: start; }
@media (max-width: 820px) { .split { grid-template-columns: 1fr; gap: 2.5rem; } }
.split-col:last-child { margin-top: clamp(0px, 4vw, 3.5rem); }   /* the offset */
.split-label {
    font-size: var(--t-xs); text-transform: uppercase; letter-spacing: .13em;
    color: var(--fg-3); font-weight: 500; margin: 0 0 1.1rem;
    display: flex; align-items: center; gap: .6rem;
}
.split-label::after { content: ''; flex: 1; height: 1px; background: var(--line); }
.rail { list-style: none; margin: 0; padding: 0; }
.rail li {
    display: flex; align-items: center; gap: .9rem; padding: .95rem 0;
    border-bottom: 1px solid var(--line-2);
    transition: transform .4s var(--ease), padding-left .4s var(--ease);
}
.rail li .ic {
    width: 34px; height: 34px; border-radius: 10px; flex: none; display: grid; place-items: center;
    background: var(--tint); border: 1px solid var(--tint-border); color: var(--accent);
    transition: transform .45s var(--ease);
}
.rail li .ic .ico { width: 17px; height: 17px; }
.rail b { font-weight: 500; font-size: var(--t-md); display: block; }
.rail span { font-size: var(--t-sm); color: var(--fg-3); }
@media (hover: hover) {
    .rail li:hover { padding-left: .5rem; }
    .rail li:hover .ic { transform: scale(1.08) rotate(-5deg); }
}

/* ── flow: an asymmetric connected path, not three equal columns ────── */
.flow { position: relative; display: grid; gap: 1rem; }
.flow-node {
    display: grid; grid-template-columns: 42px 1fr; gap: 1.2rem; align-items: start;
    padding: 1.5rem 1.7rem; border-radius: 18px;
    border: 1px solid var(--line); background: var(--surf); box-shadow: var(--shadow-sm);
    transition: transform .5s var(--ease), border-color .5s var(--ease);
}
.flow-node:nth-child(1) { width: min(100%, 46rem); }
.flow-node:nth-child(2) { width: min(100%, 46rem); margin-left: auto; }
.flow-node:nth-child(3) { width: min(100%, 46rem); margin-inline: auto; }
.flow-node h3 { margin: 0 0 .4rem; font-size: var(--t-lg); font-weight: 500; }
.flow-node p { margin: 0; font-size: var(--t-base); color: var(--fg-2); line-height: 1.6; }
.flow-node .step-n {
    font-size: var(--t-xs); color: var(--accent); font-weight: 500;
    letter-spacing: .1em; display: block; margin-bottom: .35rem;
}
@media (hover: hover) { .flow-node:hover { transform: translateY(-3px); border-color: var(--tint-border); } }
@media (max-width: 620px) {
    .flow-node { grid-template-columns: 1fr; gap: .9rem; padding: 1.3rem; }
}

/* ── stats ─────────────────────────────────────────────────────────── */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--line); border: 1px solid var(--line); border-radius: 18px; overflow: hidden; }
.stat { padding: 1.8rem 1.4rem; background: var(--surf); }
.stat .n {
    /* Big figures belong to the display voice. `lining-nums` matters here:
       the serif's default figures are shorter and sit below the cap line,
       which reads as a typo in a stat tile. */
    font-family: var(--serif);
    font-size: clamp(1.9rem, 3.8vw, 2.7rem); font-weight: 400; letter-spacing: -.02em; line-height: 1;
    background: var(--num-grad); -webkit-background-clip: text; background-clip: text; color: transparent;
    font-variant-numeric: lining-nums tabular-nums;
    font-feature-settings: 'lnum' 1, 'tnum' 1;
}
.stat .l { margin-top: .5rem; font-size: var(--t-sm); color: var(--fg-3); }
@media (max-width: 780px) { .stats { grid-template-columns: repeat(2, 1fr); } }

/* ── manifesto: full-bleed inverted band ───────────────────────────── */
.manifesto {
    background: var(--inv-bg); color: var(--inv-fg);
    padding: clamp(5rem, 10vw, 8rem) 0; text-align: center; position: relative; overflow: hidden;
}
.manifesto::before {
    content: ''; position: absolute; left: 50%; top: 50%; translate: -50% -50%;
    width: 80vw; height: 60vh;
    background: radial-gradient(ellipse 50% 50% at 50% 50%, rgba(var(--o-rgb), .22), transparent 70%);
    filter: blur(40px); pointer-events: none;
}
/* The accent mark that opens the statement — 44px of the brand gradient, and
   nothing else. It is what tells the eye this band is a quote and not a
   heading, without spending a glyph on it. */
.manifesto .quote-rule {
    position: relative; display: block; width: 44px; height: 2px; border-radius: 2px;
    margin: 0 auto clamp(1.7rem, 3.4vw, 2.4rem);
    background: var(--o-grad);
}
.manifesto blockquote {
    position: relative; margin: 0 auto; max-width: 24ch;
    font-family: var(--serif);
    font-size: clamp(1.75rem, 4.4vw, 3rem); line-height: 1.28;
    letter-spacing: -.014em; font-weight: 400; text-wrap: balance;
}
.manifesto blockquote .grad { background: var(--o-grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
/* Not an attribution — the second beat of the statement, held quieter and
   narrower than the quote so the two read as one thought. */
.manifesto .sig {
    position: relative; margin: clamp(1.5rem, 3vw, 2rem) auto 0; max-width: 42ch;
    font-size: var(--t-sm); line-height: 1.6; color: var(--inv-fg2); text-wrap: balance;
}

/* ── faq ───────────────────────────────────────────────────────────── */
.faq-grid { display: grid; grid-template-columns: minmax(0, .8fr) minmax(0, 1.6fr); gap: clamp(1.5rem, 5vw, 4rem); align-items: start; }
@media (max-width: 880px) { .faq-grid { grid-template-columns: 1fr; gap: 2rem; } }
.faq { border-top: 1px solid var(--line); }
.faq details { border-bottom: 1px solid var(--line); }
.faq summary {
    list-style: none; cursor: pointer; padding: 1.25rem .25rem;
    display: flex; align-items: center; gap: 1rem;
    font-size: var(--t-lg); font-weight: 400; transition: color .3s;
}
.faq summary::-webkit-details-marker { display: none; }
.faq summary .plus { margin-left: auto; flex: none; width: 18px; height: 18px; position: relative; transition: transform .45s var(--ease); }
.faq summary .plus::before, .faq summary .plus::after {
    content: ''; position: absolute; inset: 50% 0 auto; height: 1.5px; border-radius: 2px;
    background: var(--fg-3); translate: 0 -50%;
}
.faq summary .plus::after { rotate: 90deg; transition: opacity .3s; }
.faq details[open] summary { color: var(--accent); }
.faq details[open] summary .plus { transform: rotate(180deg); }
.faq details[open] summary .plus::after { opacity: 0; }
.faq .answer { padding: 0 2.5rem 1.3rem .25rem; color: var(--fg-2); font-size: var(--t-base); line-height: 1.66; }
.faq details[open] .answer { animation: fade-down .5s var(--ease); }
@keyframes fade-down { from { opacity: 0; transform: translateY(-6px); } }
@media (hover: hover) { .faq summary:hover { color: var(--accent); } }

/* ── cta ───────────────────────────────────────────────────────────── */
.cta { position: relative; overflow: hidden; text-align: center; padding: clamp(5rem, 10vw, 8rem) 1.5rem; }
.cta::before {
    content: ''; position: absolute; left: 50%; bottom: -50%; translate: -50% 0;
    width: 110vw; height: 90vh;
    background: radial-gradient(ellipse 50% 50% at 50% 50%, var(--glow-2), transparent 68%);
    filter: blur(40px); pointer-events: none;
}
.cta-in { position: relative; z-index: 1; display: grid; justify-items: center; }
.cta-in .display { font-size: clamp(2.2rem, 5.6vw, 4.2rem); margin-bottom: 1.3rem; }
.cta-in .lede { max-width: 33rem; margin-bottom: 2rem; }
.cta-actions { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; }

/* ── footer ────────────────────────────────────────────────────────── */
footer { border-top: 1px solid var(--line); padding: 3rem 0 2.2rem; background: var(--bg-alt); }
.foot-grid { display: flex; flex-wrap: wrap; gap: 2.5rem 3rem; justify-content: space-between; }
.foot-brand { max-width: 21rem; display: grid; gap: .9rem; align-content: start; }
.foot-brand img { width: 30px; height: 30px; }
.foot-brand p { margin: 0; font-size: var(--t-base); color: var(--fg-3); line-height: 1.62; }
.foot-col { display: grid; gap: .55rem; align-content: start; }
.foot-col h2 { font-size: var(--t-xs); text-transform: uppercase; letter-spacing: .12em; color: var(--fg-3); font-weight: 500; margin: 0 0 .2rem; }
.foot-col ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .55rem; }
.foot-col a { font-size: var(--t-base); color: var(--fg-2); transition: color .3s; }
@media (hover: hover) { .foot-col a:hover { color: var(--accent); } }
.foot-bottom {
    margin-top: 2.6rem; padding-top: 1.3rem; border-top: 1px solid var(--line);
    display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between;
    font-size: var(--t-sm); color: var(--fg-3);
}

/* ── reveal + motion safety ────────────────────────────────────────── */
[data-reveal] { opacity: 0; transform: translateY(20px); transition: opacity .85s var(--ease), transform .85s var(--ease); }
[data-reveal].is-in { opacity: 1; transform: none; }
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .001ms !important; animation-iteration-count: 1 !important;
        transition-duration: .001ms !important; scroll-behavior: auto !important;
    }
    [data-reveal] { opacity: 1; transform: none; }
    .frame { transform: none; }
    .type-caret { display: none; }
    .glyph .ico [data-draw] { stroke-dashoffset: 0; }
}
</style>
</head>
<body>

<a class="skip-link" href="#main" data-i18n="landing.nav.skip">{{ __('landing.nav.skip') }}</a>

@php $__locale = app()->getLocale(); @endphp

<!-- ══════════════ HEADER ══════════════ -->
<header class="nav" id="nav">
    <div class="shell nav-in">
        <a href="#top" class="brand" data-i18n-al="landing.nav.home" aria-label="{{ __('landing.nav.home') }}">
            <img src="{{ asset('images/brand/gen/mark.webp') }}" alt="" width="30" height="30" />
        </a>
        <nav class="nav-links" data-i18n-al="landing.nav.menu" aria-label="{{ __('landing.nav.menu') }}">
            <a href="#product" data-i18n="landing.nav.product">{{ __('landing.nav.product') }}</a>
            <a href="#how" data-i18n="landing.nav.how">{{ __('landing.nav.how') }}</a>
            <a href="#faq" data-i18n="landing.nav.faq">{{ __('landing.nav.faq') }}</a>
        </nav>
        <div class="nav-tools">
            <details class="lang" id="lang">
                <summary class="nav-btn" data-i18n-al="landing.nav.language" aria-label="{{ __('landing.nav.language') }}">
                    <svg class="ico globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.2 9h17.6M3.2 15h17.6M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/></svg>
                    <span data-locale-label>{{ __('nav.locales.'.$__locale) }}</span>
                    <svg class="ico chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="lang-menu">
                    @foreach (['ru', 'tg', 'en'] as $loc)
                        <a href="/{{ $loc }}" lang="{{ $loc }}" hreflang="{{ $loc }}"
                           data-locale-link data-locale="{{ $loc }}"
                           aria-current="{{ $loc === $__locale ? 'true' : 'false' }}">
                            <svg class="ico tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                            {{ __('nav.locales.'.$loc) }}
                        </a>
                    @endforeach
                </div>
            </details>

            <button class="theme-toggle nav-btn" id="themeToggle" type="button" aria-label="Theme">
                <svg class="ico ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.4M12 19.6V22M2 12h2.4M19.6 12H22M4.9 4.9l1.7 1.7M17.4 17.4l1.7 1.7M19.1 4.9l-1.7 1.7M6.6 17.4l-1.7 1.7"/></svg>
                <svg class="ico ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.2A8.4 8.4 0 0 1 9.8 4a8.4 8.4 0 1 0 10.2 10.2Z"/></svg>
            </button>

            {{-- Shown by CSS only below 820px, and only when JS is on: without
                 it the drawer could never open and the links would be lost. --}}
            <button class="burger nav-btn" id="burger" type="button"
                    aria-expanded="false" aria-controls="drawer"
                    data-i18n-al="landing.nav.menu" aria-label="{{ __('landing.nav.menu') }}">
                <span class="bars" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
        </div>
    </div>

    <div class="drawer" id="drawer">
        <nav class="shell drawer-in" data-i18n-al="landing.nav.menu" aria-label="{{ __('landing.nav.menu') }}">
            <a href="#product" data-i18n="landing.nav.product">{{ __('landing.nav.product') }}</a>
            <a href="#how" data-i18n="landing.nav.how">{{ __('landing.nav.how') }}</a>
            <a href="#faq" data-i18n="landing.nav.faq">{{ __('landing.nav.faq') }}</a>
        </nav>
    </div>
</header>

<main id="main">

<!-- ══════════════ HERO ══════════════ -->
<section class="hero" id="top">
    <div class="glows" aria-hidden="true">
        <div class="glow glow-a"></div><div class="glow glow-b"></div><div class="glow glow-c"></div>
    </div>
    <div class="grid-bg" aria-hidden="true"></div>

    <div class="shell hero-in">
        <div class="hero-copy">
            <span class="pill" data-reveal><i class="dot" aria-hidden="true"></i> <span data-i18n="landing.hero.badge">{{ __('landing.hero.badge') }}</span></span>

            {{-- The lockup is deliberately not translated: "Velora Agents" is
                 the product name and reads identically in ru/tg/en, so it is
                 split into two spans here to be set as a wordmark rather than
                 pulled from landing.hero.brand as one flat string. The full
                 sentence still reaches assistive tech through aria-label. --}}
            <h1 class="display" data-reveal data-i18n-al="landing.hero.title_static" aria-label="{{ __('landing.hero.title_static') }}">
                <span class="wordmark">
                    <span class="wm-name">Velora</span><span class="wm-suffix">Agents</span>
                </span>
                {{-- The wrapper holds the geometry (ghosts for every phrase are
                     injected into it at boot); #typeLine is only the visible
                     characters. Without JS the first phrase renders as-is. --}}
                <span class="type-line" id="typeWrap" aria-hidden="true"><span class="type-out grad" id="typeLine">{{ __('landing.hero.typed.0') }}</span></span>
            </h1>

            <p class="lede" data-reveal data-i18n="landing.hero.sub">{{ __('landing.hero.sub') }}</p>

            <div class="hero-actions" data-reveal>
                <a class="btn btn-o" href="{{ route('register', ['locale' => $__locale]) }}">
                    <span data-i18n="landing.hero.cta_primary">{{ __('landing.hero.cta_primary') }}</span>
                    <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a class="btn btn-ghost" href="#product">
                    <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m10 8.5 6 3.5-6 3.5Z"/></svg>
                    <span data-i18n="landing.hero.cta_secondary">{{ __('landing.hero.cta_secondary') }}</span>
                </a>
            </div>
        </div>

        <div class="hero-rail" data-reveal>
            <ul>
                <li>
                    <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    <span data-i18n="landing.hero.meta_1">{{ __('landing.hero.meta_1') }}</span>
                </li>
                <li>
                    <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    <span data-i18n="landing.hero.meta_2">{{ __('landing.hero.meta_2') }}</span>
                </li>
                <li>
                    <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    <span data-i18n="landing.hero.meta_3">{{ __('landing.hero.meta_3') }}</span>
                </li>
            </ul>
        </div>
    </div>

    <!-- floating product frame -->
    <div class="stage" id="product">
        <h2 class="sr-only" data-i18n="landing.demo.heading">{{ __('landing.demo.heading') }}</h2>
        <div class="frame" id="frame">
            <div class="frame-bar" aria-hidden="true">
                <i></i><i></i><i></i>
                <span class="addr">Velora · Atlas Store · Telegram</span>
            </div>
            <div class="frame-body">
                <div class="chat">
                    <div class="chat-head">
                        <div class="avatar" aria-hidden="true">A</div>
                        <div>
                            <b data-i18n="landing.demo.agent">{{ __('landing.demo.agent') }}</b>
                            <small data-i18n="landing.demo.status">{{ __('landing.demo.status') }}</small>
                        </div>
                    </div>
                    {{-- #msgsGhost is filled at boot with the whole finished
                         conversation to hold the panel's height. --}}
                    <div class="msgs-frame">
                        <div class="msgs msgs-ghost" id="msgsGhost" aria-hidden="true"></div>
                        <div class="msgs" id="msgs" lang="tg" role="img"
                             data-i18n-al="landing.demo.aria" aria-label="{{ __('landing.demo.aria') }}"></div>
                    </div>
                    <div class="composer" aria-hidden="true">
                        <span data-i18n="landing.demo.composer">{{ __('landing.demo.composer') }}</span>
                        <span class="send">
                            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </span>
                    </div>
                </div>

                <aside class="side">
                    <span class="side-label" data-i18n="landing.demo.side_label">{{ __('landing.demo.side_label') }}</span>
                    <div class="lead-card" id="leadCard">
                        <p class="lead-title" data-i18n="landing.demo.lead_title">{{ __('landing.demo.lead_title') }}</p>
                        <div class="stamp">
                            <svg class="ico" style="width:12px;height:12px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span data-i18n="landing.demo.lead_stamp">{{ __('landing.demo.lead_stamp') }}</span>
                        </div>
                        <div class="lead-row"><span data-i18n="landing.demo.row_name">{{ __('landing.demo.row_name') }}</span><span lang="tg">Дилшод</span></div>
                        <div class="lead-row"><span data-i18n="landing.demo.row_phone">{{ __('landing.demo.row_phone') }}</span><span>+992 •• ••• 41 20</span></div>
                        <div class="lead-row"><span data-i18n="landing.demo.row_wants">{{ __('landing.demo.row_wants') }}</span><span>ASUS Vivobook 16″</span></div>
                        <div class="lead-row"><span data-i18n="landing.demo.row_budget">{{ __('landing.demo.row_budget') }}</span><span>4 000–4 500</span></div>
                    </div>
                    <div class="mini">
                        <span class="ic" aria-hidden="true"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V6a2 2 0 0 1 2-2h9l5 5v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/><path d="M14 4v5h5"/></svg></span>
                        <span data-i18n="landing.demo.grounded">{{ __('landing.demo.grounded') }}</span>
                    </div>
                    <div class="mini">
                        <span class="ic" aria-hidden="true"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4.5 13H11l-1 9 8.5-11H12l1-9Z"/></svg></span>
                        <span data-i18n="landing.demo.speed">{{ __('landing.demo.speed') }}</span>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ BENTO ══════════════ -->
<section class="band" aria-labelledby="why-title">
    <div class="shell">
        <div class="band-head" data-reveal>
            <p class="eyebrow" data-i18n="landing.why.eyebrow">{{ __('landing.why.eyebrow') }}</p>
            <h2 class="title" id="why-title" data-i18n="landing.why.title">{{ __('landing.why.title') }}</h2>
            <p class="lede" data-i18n="landing.why.sub">{{ __('landing.why.sub') }}</p>
        </div>

        <div class="bento">
            <article class="cell c-hero" data-reveal>
                <span class="glyph"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path data-draw style="--len:64" d="M4 6.5A2.5 2.5 0 0 1 6.5 4H20v16H6.5A2.5 2.5 0 0 1 4 17.5Z"/><path class="nudge" d="M9 9h7M9 13h5"/></svg></span>
                <h3 data-i18n="landing.why.items.0.title">{{ __('landing.why.items.0.title') }}</h3>
                <p data-i18n="landing.why.items.0.line">{{ __('landing.why.items.0.line') }}</p>
            </article>

            <article class="cell c-tall" data-reveal>
                <span class="glyph"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path data-draw style="--len:70" d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/><circle class="blip" cx="8" cy="10" r="1"/><circle class="blip" cx="12" cy="10" r="1"/><circle class="blip" cx="16" cy="10" r="1"/></svg></span>
                <h3 data-i18n="landing.why.items.2.title">{{ __('landing.why.items.2.title') }}</h3>
                <p data-i18n="landing.why.items.2.line">{{ __('landing.why.items.2.line') }}</p>
                <div class="chips"><i>Telegram</i><i>WhatsApp</i><i>Instagram</i></div>
            </article>

            <article class="cell c-wide" data-reveal>
                <span class="glyph"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path data-draw style="--len:60" d="M20 13V7a2 2 0 0 0-2-2h-3l-3-2-3 2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7"/><path class="nudge" d="m16 18 2 2 4-4"/></svg></span>
                <h3 data-i18n="landing.why.items.1.title">{{ __('landing.why.items.1.title') }}</h3>
                <p data-i18n="landing.why.items.1.line">{{ __('landing.why.items.1.line') }}</p>
            </article>

            <article class="cell c-small" data-reveal>
                <span class="glyph"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path data-draw style="--len:40" d="M4 20v-5M12 20V8M20 20v-9"/><circle class="blip" cx="4" cy="11" r="2"/><circle class="blip" cx="12" cy="5" r="2"/><circle class="blip" cx="20" cy="8" r="2"/></svg></span>
                <h3 data-i18n="landing.why.items.3.title">{{ __('landing.why.items.3.title') }}</h3>
                <p data-i18n="landing.why.items.3.line">{{ __('landing.why.items.3.line') }}</p>
                <div class="dial" aria-hidden="true"><b>Off</b><b>Subtle</b><b class="on">Active</b></div>
            </article>

            <article class="cell c-mid" data-reveal>
                <span class="glyph"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path data-draw style="--len:52" d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1"/><path class="nudge" d="M22 22l-5-10-5 10M14 18h6"/></svg></span>
                <h3 data-i18n="landing.why.items.4.title">{{ __('landing.why.items.4.title') }}</h3>
                <p data-i18n="landing.why.items.4.line">{{ __('landing.why.items.4.line') }}</p>
            </article>

            <article class="cell c-mid" data-reveal>
                <span class="glyph"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path data-draw style="--len:58" d="M12 3 4 6v6c0 4.5 3.2 8.3 8 9 4.8-.7 8-4.5 8-9V6Z"/><path class="nudge" d="m9 12 2 2 4-4"/></svg></span>
                <h3 data-i18n="landing.why.items.5.title">{{ __('landing.why.items.5.title') }}</h3>
                <p data-i18n="landing.why.items.5.line">{{ __('landing.why.items.5.line') }}</p>
            </article>
        </div>
    </div>
</section>

<!-- ══════════════ SOURCES (replaces the marquee) ══════════════ -->
<section class="band" aria-labelledby="sources-title">
    <div class="shell">
        <div class="band-head" data-reveal>
            <p class="eyebrow" data-i18n="landing.sources.eyebrow">{{ __('landing.sources.eyebrow') }}</p>
            <h2 class="title" id="sources-title" data-i18n="landing.sources.title">{{ __('landing.sources.title') }}</h2>
            <p class="lede" data-i18n="landing.sources.sub">{{ __('landing.sources.sub') }}</p>
        </div>

        <div class="split">
            <div class="split-col" data-reveal>
                <p class="split-label" data-i18n="landing.sources.inputs_label">{{ __('landing.sources.inputs_label') }}</p>
                <ul class="rail">
                    @foreach (__('landing.sources.inputs') as $i => $row)
                        <li>
                            <span class="ic" aria-hidden="true">
                                <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V6a2 2 0 0 1 2-2h9l5 5v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/><path d="M14 4v5h5"/></svg>
                            </span>
                            <span>
                                <b data-i18n="landing.sources.inputs.{{ $i }}.name">{{ $row['name'] }}</b>
                                <span data-i18n="landing.sources.inputs.{{ $i }}.note">{{ $row['note'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="split-col" data-reveal>
                <p class="split-label" data-i18n="landing.sources.channels_label">{{ __('landing.sources.channels_label') }}</p>
                <ul class="rail">
                    @foreach (__('landing.sources.channels') as $i => $row)
                        <li>
                            <span class="ic" aria-hidden="true">
                                <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/></svg>
                            </span>
                            <span>
                                <b data-i18n="landing.sources.channels.{{ $i }}.name">{{ $row['name'] }}</b>
                                <span data-i18n="landing.sources.channels.{{ $i }}.note">{{ $row['note'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════ FLOW ══════════════ -->
<section class="band" id="how" aria-labelledby="how-title">
    <div class="shell">
        <div class="band-head" data-reveal>
            <p class="eyebrow" data-i18n="landing.how.eyebrow">{{ __('landing.how.eyebrow') }}</p>
            <h2 class="title" id="how-title" data-i18n="landing.how.title">{{ __('landing.how.title') }}</h2>
        </div>

        <div class="flow">
            @foreach (__('landing.how.steps') as $i => $step)
                <div class="flow-node" data-reveal>
                    <span class="glyph">
                        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            @if ($i === 0)
                                <path data-draw style="--len:56" d="M12 16V4M8 8l4-4 4 4"/><path class="nudge" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
                            @elseif ($i === 1)
                                <path data-draw style="--len:56" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path class="spin" d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H1a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 2.6 7"/>
                            @else
                                <path data-draw style="--len:48" d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path class="nudge" d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12 19"/>
                            @endif
                        </svg>
                    </span>
                    <div>
                        <span class="step-n">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 data-i18n="landing.how.steps.{{ $i }}.title">{{ $step['title'] }}</h3>
                        <p data-i18n="landing.how.steps.{{ $i }}.line">{{ $step['line'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<!-- ══════════════ STATS ══════════════ -->
<section class="band" aria-label="Velora">
    <div class="shell">
        <div class="stats" data-reveal>
            @foreach (__('landing.stats.items') as $i => $s)
                <div class="stat">
                    <div class="n" data-i18n="landing.stats.items.{{ $i }}.value">{{ $s['value'] }}</div>
                    <div class="l" data-i18n="landing.stats.items.{{ $i }}.label">{{ $s['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<!-- ══════════════ MANIFESTO (full-bleed, inverted) ══════════════ -->
<section class="manifesto">
    <div class="shell" data-reveal>
        {{-- A rule rather than a quotation glyph: the mark differs by locale
             (“ ” vs « »), a hairline doesn't. --}}
        <span class="quote-rule" aria-hidden="true"></span>
        <blockquote>
            <span data-i18n="landing.manifesto.lead">{{ __('landing.manifesto.lead') }}</span>
            <span class="grad" data-i18n="landing.manifesto.emphasis">{{ __('landing.manifesto.emphasis') }}</span>
        </blockquote>
        <p class="sig" data-i18n="landing.manifesto.sig">{{ __('landing.manifesto.sig') }}</p>
    </div>
</section>

<!-- ══════════════ FAQ ══════════════ -->
<section class="band" id="faq" aria-labelledby="faq-title" style="padding-top: var(--section-y);">
    <div class="shell faq-grid">
        <div data-reveal>
            <p class="eyebrow" data-i18n="landing.faq.eyebrow">{{ __('landing.faq.eyebrow') }}</p>
            <h2 class="title" id="faq-title" style="margin-top:.7rem" data-i18n="landing.faq.title">{{ __('landing.faq.title') }}</h2>
        </div>
        <div class="faq" data-reveal>
            @foreach (__('landing.faq.items') as $i => $item)
                <details @if ($i === 0) open @endif>
                    <summary>
                        <span data-i18n="landing.faq.items.{{ $i }}.q">{{ $item['q'] }}</span>
                        <span class="plus" aria-hidden="true"></span>
                    </summary>
                    <div class="answer" data-i18n="landing.faq.items.{{ $i }}.a">{{ $item['a'] }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>

<!-- ══════════════ CTA ══════════════ -->
<section class="cta" aria-labelledby="cta-title">
    <div class="cta-in shell">
        <h2 class="display" id="cta-title" data-reveal>
            <span data-i18n="landing.cta.title">{{ __('landing.cta.title') }}</span>
            <span class="grad" data-i18n="landing.cta.emphasis">{{ __('landing.cta.emphasis') }}</span>
        </h2>
        <p class="lede" data-reveal data-i18n="landing.cta.sub">{{ __('landing.cta.sub') }}</p>
        <div class="cta-actions" data-reveal>
            <a class="btn btn-o" href="{{ route('register', ['locale' => $__locale]) }}">
                <span data-i18n="landing.cta.primary">{{ __('landing.cta.primary') }}</span>
                <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
            <a class="btn btn-ghost" href="{{ route('login', ['locale' => $__locale]) }}" data-i18n="landing.cta.secondary">{{ __('landing.cta.secondary') }}</a>
        </div>
    </div>
</section>

</main>

<!-- ══════════════ FOOTER ══════════════ -->
<footer>
    <div class="shell">
        <div class="foot-grid">
            <div class="foot-brand">
                <a href="#top" data-i18n-al="landing.nav.home" aria-label="{{ __('landing.nav.home') }}">
                    <img src="{{ asset('images/brand/gen/mark.webp') }}" alt="" width="30" height="30" loading="lazy" />
                </a>
                <p data-i18n="landing.footer.tagline">{{ __('landing.footer.tagline') }}</p>
            </div>
            <div class="foot-col">
                <h2 data-i18n="landing.footer.product">{{ __('landing.footer.product') }}</h2>
                <ul>
                    <li><a href="#product" data-i18n="landing.footer.overview">{{ __('landing.footer.overview') }}</a></li>
                    <li><a href="#how" data-i18n="landing.nav.how">{{ __('landing.nav.how') }}</a></li>
                    <li><a href="#faq" data-i18n="landing.nav.faq">{{ __('landing.nav.faq') }}</a></li>
                </ul>
            </div>
            <div class="foot-col">
                <h2 data-i18n="landing.footer.channels">{{ __('landing.footer.channels') }}</h2>
                <ul>
                    <li><a href="#product">Telegram</a></li>
                    <li><a href="#product">WhatsApp</a></li>
                    <li><a href="#product">Instagram</a></li>
                </ul>
            </div>
            <div class="foot-col">
                <h2 data-i18n="landing.footer.language">{{ __('landing.footer.language') }}</h2>
                <ul>
                    @foreach (['ru', 'tg', 'en'] as $loc)
                        <li><a href="/{{ $loc }}" lang="{{ $loc }}" hreflang="{{ $loc }}" data-locale-link data-locale="{{ $loc }}">{{ __('nav.locales.'.$loc) }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div class="foot-col">
                <h2 data-i18n="landing.footer.account">{{ __('landing.footer.account') }}</h2>
                <ul>
                    <li><a href="{{ route('login', ['locale' => $__locale]) }}" data-i18n="landing.footer.login">{{ __('landing.footer.login') }}</a></li>
                    <li><a href="{{ route('register', ['locale' => $__locale]) }}" data-i18n="landing.footer.register">{{ __('landing.footer.register') }}</a></li>
                </ul>
            </div>
        </div>
        <div class="foot-bottom">
            <span>© {{ date('Y') }} Velora Agents</span>
            <span data-i18n="landing.footer.built">{{ __('landing.footer.built') }}</span>
        </div>
    </div>
</footer>

@include('partials.i18n-bundle', ['i18nNamespaces' => ['landing', 'nav', 'common'], 'i18nReload' => false])

<script>
(function () {
    'use strict';
    var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fine = window.matchMedia('(hover: hover)').matches;
    var root = document.documentElement;

    /* ── theme ───────────────────────────────────────────────────── */
    var darkMq = window.matchMedia('(prefers-color-scheme: dark)');
    var toggle = document.getElementById('themeToggle');
    var currentTheme = function () {
        if (root.classList.contains('dark')) return 'dark';
        if (root.classList.contains('light')) return 'light';
        return darkMq.matches ? 'dark' : 'light';
    };
    var syncLabel = function () {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        toggle.setAttribute('aria-label', 'Switch to ' + next + ' theme');
        toggle.setAttribute('title', 'Switch to ' + next + ' theme');
    };
    syncLabel();
    toggle.addEventListener('click', function () {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.classList.remove('light', 'dark');
        root.classList.add(next);
        try { localStorage.setItem('theme', next); } catch (e) {}
        syncLabel();
    });
    if (darkMq.addEventListener) darkMq.addEventListener('change', syncLabel);

    /* ── language dropdown ────────────────────────────────────────
       The i18n engine intercepts [data-locale] clicks and swaps in place;
       all this has to do is keep the summary label current and behave like
       a real menu (click-outside, Escape). */
    var lang = document.getElementById('lang');
    var langSummary = lang.querySelector('summary');
    var langMenu = lang.querySelector('.lang-menu');
    var langLabel = lang.querySelector('[data-locale-label]');

    /* <details> snaps shut with no state to animate, so the close is driven
       here: play `unroll`, then drop the attribute on the way out. */
    var closeLang = function (focusBack) {
        if (!lang.hasAttribute('open')) return;
        if (focusBack) langSummary.focus();
        if (calm) { lang.removeAttribute('open'); return; }
        if (langMenu.classList.contains('is-closing')) return;
        langMenu.classList.add('is-closing');
        langMenu.addEventListener('animationend', function () {
            langMenu.classList.remove('is-closing');
            lang.removeAttribute('open');
        }, { once: true });
    };
    /* Clicking the summary of an open <details> would close it instantly and
       skip the animation, so that path is taken over. */
    langSummary.addEventListener('click', function (ev) {
        if (!lang.hasAttribute('open')) return;
        ev.preventDefault();
        closeLang(false);
    });
    document.addEventListener('click', function (ev) {
        if (!lang.contains(ev.target)) closeLang(false);
        /* The same gesture closes the drawer. The test is against the whole
           header, which contains the burger — so the click that opens the
           drawer cannot immediately undo it. */
        if (nav.classList.contains('is-open') && !nav.contains(ev.target)) setMenu(false);
    });
    lang.addEventListener('click', function (ev) {
        var link = ev.target.closest('[data-locale]');
        if (!link) return;
        /* The engine already swapped the page; mirror the choice in the
           summary, whose text is not a [data-i18n] target. */
        var name = link.textContent.trim();
        if (name) langLabel.textContent = name;
        closeLang(false);
    });

    /* ── mobile drawer ───────────────────────────────────────────── */
    var nav = document.getElementById('nav');
    var burger = document.getElementById('burger');
    var drawer = document.getElementById('drawer');
    var setMenu = function (open) {
        nav.classList.toggle('is-open', open);
        burger.setAttribute('aria-expanded', String(open));
    };
    burger.addEventListener('click', function () {
        setMenu(!nav.classList.contains('is-open'));
    });
    drawer.addEventListener('click', function (ev) {
        if (ev.target.closest('a')) setMenu(false);
    });
    /* Resizing past the breakpoint hides the burger; the drawer must not be
       left open behind it. */
    var wide = window.matchMedia('(min-width: 821px)');
    var onWide = function (ev) { if (ev.matches) setMenu(false); };
    if (wide.addEventListener) wide.addEventListener('change', onWide);

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (lang.hasAttribute('open')) closeLang(true);
        if (nav.classList.contains('is-open')) { setMenu(false); burger.focus(); }
    });

    /* ── sticky nav ──────────────────────────────────────────────── */
    var onScroll = function () { nav.classList.toggle('is-stuck', window.scrollY > 24); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    /* ── reveal ──────────────────────────────────────────────────── */
    var targets = document.querySelectorAll('[data-reveal]');
    if (calm || !('IntersectionObserver' in window)) {
        targets.forEach(function (el) { el.classList.add('is-in'); });
    } else {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) return;
                var sibs = Array.prototype.slice.call(e.target.parentNode.children)
                    .filter(function (n) { return n.hasAttribute && n.hasAttribute('data-reveal'); });
                e.target.style.transitionDelay = (Math.min(sibs.indexOf(e.target), 6) * 70) + 'ms';
                e.target.classList.add('is-in');
                io.unobserve(e.target);
            });
        }, { rootMargin: '0px 0px -12% 0px', threshold: .12 });
        targets.forEach(function (el) { io.observe(el); });
    }

    /* ── frame flattens on entry ─────────────────────────────────── */
    var frame = document.getElementById('frame');
    if (calm) frame.classList.add('is-flat');
    else if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (es) {
            es.forEach(function (e) { frame.classList.toggle('is-flat', e.isIntersecting); });
        }, { threshold: .2 }).observe(frame);
    }

    /* ── pointer sheen ───────────────────────────────────────────── */
    if (fine) {
        document.addEventListener('pointermove', function (ev) {
            var host = ev.target.closest ? ev.target.closest('.cell, .btn-o') : null;
            if (!host) return;
            var r = host.getBoundingClientRect();
            host.style.setProperty('--mx', (ev.clientX - r.left) + 'px');
            host.style.setProperty('--my', (ev.clientY - r.top) + 'px');
        }, { passive: true });
    }

    /* ── typewriter: brand line, then the rotating explanation ───── */
    var typeWrap = document.getElementById('typeWrap');
    var typeLine = document.getElementById('typeLine');
    // Captured before the first keystroke: once typing starts textContent is a
    // partial word, so it can't be used as the fallback phrase list any more.
    var typeFallback = [typeLine.textContent];
    var phrases = function () {
        var m = window.__i18n && window.__i18n.messages[window.__i18n.current];
        var t = m && m.landing && m.landing.hero && m.landing.hero.typed;
        return (t && t.length) ? t : typeFallback;
    };

    /* Reserve the full box before a single character is typed: one hidden ghost
       per phrase, all sharing the grid cell with the visible line. The wrapper
       is then permanently as tall as the phrase that wraps to the most lines at
       the current width, so the type/erase cycle can't move anything below it
       by a pixel — including on a phone, where phrases wrap. Re-run on a swap
       because the phrase list — and its longest member — changes with it. */
    var sizeTypeLine = function () {
        var ghosts = typeWrap.querySelectorAll('.type-ghost');
        for (var i = 0; i < ghosts.length; i++) typeWrap.removeChild(ghosts[i]);
        phrases().forEach(function (phrase) {
            var ghost = document.createElement('span');
            ghost.className = 'type-ghost';
            ghost.textContent = phrase;
            typeWrap.insertBefore(ghost, typeLine);
        });
    };
    sizeTypeLine();
    document.addEventListener('i18n:changed', sizeTypeLine);

    if (!calm) {
        var pi = 0, ci = 0, erasing = false, caret = document.createElement('span');
        caret.className = 'type-caret';
        var tick = function () {
            var list = phrases();
            var word = list[pi % list.length];
            ci += erasing ? -1 : 1;
            typeLine.textContent = word.slice(0, ci);
            typeLine.appendChild(caret);
            var wait = erasing ? 34 : 58;
            if (!erasing && ci >= word.length) { erasing = true; wait = 2100; }
            else if (erasing && ci <= 0) { erasing = false; pi++; wait = 320; }
            setTimeout(tick, wait);
        };
        typeLine.textContent = '';
        typeLine.appendChild(caret);
        setTimeout(tick, 700);
    }

    /* ── chat demo — runs only while on screen ───────────────────── */
    var script = [
        { who: 'them', text: 'Салом! Ноутбук барои кор доред? To 4500 сомонӣ.' },
        { who: 'us',   text: 'Салом! Ҳа — ASUS Vivobook 16″, Ryzen 5, 16 ГБ RAM, 512 SSD — 4 390 c. Дар анбор ҳаст.', wait: 1200 },
        { who: 'them', text: 'А гарантия?' },
        { who: 'us',   text: '24 моҳ гарантияи расмӣ. Мехоҳед онро барои шумо нигоҳ дорам?', wait: 900 },
        { who: 'them', text: 'Ҳа, лутфан. Дилшод, +992 •• ••• 41 20' },
        { who: 'us',   text: 'Сабт шуд, Дилшод. Менеҷер то 10 дақиқа занг мезанад. ✨', wait: 1100, lead: true }
    ];
    var box = document.getElementById('msgs');
    var ghostBox = document.getElementById('msgsGhost');
    var leadCard = document.getElementById('leadCard');
    var onScreen = false, timer = null, pending = null, step = 0;

    var bubble = function (who, text, host) {
        var el = document.createElement('div');
        el.className = 'msg ' + who;
        el.textContent = text;
        (host || box).appendChild(el);
        return el;
    };

    // Height reservation — the full transcript, hidden, behind the live one.
    // Every state of the loop is a subset of it, so the panel is sized once and
    // never resizes again, at any viewport width.
    script.forEach(function (line) { bubble(line.who, line.text, ghostBox); });
    var after = function (ms, fn) {
        timer = setTimeout(function () {
            timer = null;
            if (onScreen) fn(); else pending = fn;
        }, ms);
    };
    var advance = function () {
        if (step >= script.length) {
            after(4200, function () {
                step = 0; box.innerHTML = ''; leadCard.classList.remove('is-in'); advance();
            });
            return;
        }
        var line = script[step++];
        if (line.who === 'them') {
            after(700, function () { bubble('them', line.text); after(600, advance); });
            return;
        }
        var el = bubble('us', '');
        el.classList.add('is-typing');
        el.innerHTML = '<span class="typing"><i></i><i></i><i></i></span>';
        after(line.wait || 900, function () {
            el.classList.remove('is-typing');
            el.textContent = line.text;
            if (line.lead) setTimeout(function () { leadCard.classList.add('is-in'); }, 450);
            after(1100, advance);
        });
    };
    if (calm) {
        script.forEach(function (l) { bubble(l.who, l.text); });
        leadCard.classList.add('is-in');
    } else if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (es) {
            es.forEach(function (e) {
                onScreen = e.isIntersecting;
                if (!onScreen) return;
                if (pending) { var fn = pending; pending = null; fn(); }
                else if (step === 0 && !timer) advance();
            });
        }, { threshold: .3 }).observe(box);
    } else { onScreen = true; advance(); }

    /* ── stats count-up ──────────────────────────────────────────────
       The figures run up once, the first time the band is seen. Values are
       not pure numbers, so each is split into a leading count and a tail that
       never animates: "24/7" → 24 + "/7", "5 мин" → 5 + " мин", "3" → 3 + "".
       The tile can't reflow while the digits change — .n is a full-width block
       set in tabular figures — so this costs no layout shift. */
    var statBand = document.querySelector('.stats');
    if (statBand && !calm) {
        var figures = Array.prototype.slice.call(statBand.querySelectorAll('.n'));
        var read = function (el) {
            var m = /^(\d+)([\s\S]*)$/.exec(el.textContent.trim());
            return m ? { el: el, target: parseInt(m[1], 10), tail: m[2] } : null;
        };
        var specs = [], countFrames = [], counted = false;

        /* Targets are captured BEFORE the tiles are zeroed and kept — reading
           the target back out of an already-zeroed tile is what made every
           counter run 0 → 0 and sit there. */
        var arm = function () {
            specs = figures.map(read).filter(Boolean);
            specs.forEach(function (s) { s.el.textContent = '0' + s.tail; });
        };
        var runOne = function (s, i) {
            var t0 = null, dur = 900, delay = i * 90;
            var frame = function (now) {
                if (t0 === null) t0 = now;
                var t = (now - t0 - delay) / dur;
                if (t >= 1) { s.el.textContent = s.target + s.tail; return; }
                if (t > 0) {
                    var eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
                    s.el.textContent = Math.round(s.target * eased) + s.tail;
                }
                countFrames[i] = requestAnimationFrame(frame);
            };
            countFrames[i] = requestAnimationFrame(frame);
        };
        var run = function () {
            if (counted) return;
            counted = true;      // one-shot: never re-runs on a later scroll
            specs.forEach(runOne);
        };

        arm();

        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (es, obs) {
                es.forEach(function (e) {
                    if (!e.isIntersecting) return;
                    obs.disconnect();
                    run();
                });
            }, { threshold: .2 }).observe(statBand);
        } else {
            run();
        }

        // Failsafe: the band must never be left sitting at zero. If the
        // observer hasn't fired while the band is already on screen, run.
        setTimeout(function () {
            if (counted) return;
            var r = statBand.getBoundingClientRect();
            if (r.top < (window.innerHeight || 0) && r.bottom > 0) run();
        }, 2000);

        // A locale swap has already written each tile's final value back in. An
        // in-flight counter would overwrite it with the old locale's suffix; a
        // band still waiting to be seen re-reads its targets and re-zeros.
        document.addEventListener('i18n:changed', function () {
            countFrames.forEach(function (id) { cancelAnimationFrame(id); });
            countFrames = [];
            if (!counted) arm();
        });
    }

    /* ── in-page anchors ─────────────────────────────────────────── */
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            var t = document.querySelector(a.getAttribute('href'));
            if (!t) return;
            ev.preventDefault();
            t.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
            t.setAttribute('tabindex', '-1');
            t.focus({ preventScroll: true });
        });
    });
})();
</script>

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
    }
</script>
</body>
</html>
