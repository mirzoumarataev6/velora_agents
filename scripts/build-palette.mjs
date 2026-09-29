/**
 * Compiles the palette authored in tailwind.config.js into a Tailwind v4
 * `@theme` block.
 *
 * Why: v4 generates utilities AND `--color-*` custom properties from `@theme`,
 * but a JS config loaded via `@config` only generates the utilities. The app
 * needs both — a lot of the landing sets colour through inline `var()` — so
 * rather than keep the palette in two files, tailwind.config.js stays the single
 * authored source and this emits the stylesheet form of it.
 *
 * Runs automatically before `npm run dev` and `npm run build`. The output is
 * committed so PHP-only workflows (artisan test, a deploy that skips npm) never
 * depend on Node having run.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');
const configPath = resolve(root, 'tailwind.config.js');
const outPath = resolve(root, 'resources/css/palette.generated.css');
/* The landing (resources/views/showcase.blade.php) is deliberately outside the
   Tailwind bundle — it ships its own inline stylesheet and no preflight — so it
   cannot `@import` the file above. It gets the same tokens as a <style> partial
   instead, which is why the palette can stay in exactly one authored place
   without the landing having to re-declare a single hex. */
const bladePath = resolve(root, 'resources/views/partials/palette-vars.blade.php');

const { default: config } = await import(`file://${configPath}`);

const extend = config?.theme?.extend ?? {};
const colors = extend.colors ?? {};
const fonts = extend.fontFamily ?? {};
const fontSize = extend.fontSize ?? {};
const radius = extend.borderRadius ?? {};
const shadow = extend.boxShadow ?? {};
const easing = extend.transitionTimingFunction ?? {};
const duration = extend.transitionDuration ?? {};

const lines = [];
/* Same colours, collected a second time for the landing partial: the hex, plus
   a bare `r, g, b` triplet. Hand-written CSS needs the triplet because there is
   no way to get an alpha ramp out of a hex custom property — `rgba(var(--x),
   .3)` is the only form that works, and deriving it here is what stops the
   landing from hard-coding "238, 108, 77" next to the token it came from. */
const colorLines = [];
const rgbLines = [];

const quote = (f) => (f.includes(' ') ? `'${f}'` : f);

const toRgb = (hex) => {
    const m = /^#([0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) return null;
    const n = parseInt(m[1], 16);

    return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`;
};

const collect = (name, value) => {
    lines.push(`    --color-${name}: ${value};`);
    colorLines.push(`    --color-${name}: ${value};`);
    const rgb = toRgb(value);
    if (rgb) rgbLines.push(`    --rgb-${name}: ${rgb};`);
};

for (const [group, value] of Object.entries(colors)) {
    if (typeof value === 'string') {
        collect(group, value);
        continue;
    }

    for (const [step, hex] of Object.entries(value)) {
        // `DEFAULT` is Tailwind's convention for the bare utility name
        // (bg-ok), so it maps to the bare variable rather than `--color-ok-DEFAULT`.
        collect(step === 'DEFAULT' ? group : `${group}-${step}`, hex);
    }
    lines.push('');
}

for (const [name, stack] of Object.entries(fonts)) {
    const value = Array.isArray(stack) ? stack.map(quote).join(', ') : stack;
    lines.push(`    --font-${name}: ${value};`);
}

/* The rest of the system — type scale, radii, elevation, motion — travels the
   same route as the palette so that a token is usable as a utility (`text-h2`,
   `rounded-xl`, `shadow-e3`) AND as a `var()` inside hand-written CSS. Tailwind
   v4's `--text-*--line-height` convention carries the per-size metadata. */
lines.push('');
for (const [name, value] of Object.entries(fontSize)) {
    if (typeof value === 'string') {
        lines.push(`    --text-${name}: ${value};`);
        continue;
    }
    const [size, meta = {}] = value;
    lines.push(`    --text-${name}: ${size};`);
    if (meta.lineHeight) lines.push(`    --text-${name}--line-height: ${meta.lineHeight};`);
    if (meta.letterSpacing) lines.push(`    --text-${name}--letter-spacing: ${meta.letterSpacing};`);
    if (meta.fontWeight) lines.push(`    --text-${name}--font-weight: ${meta.fontWeight};`);
}

lines.push('');
for (const [name, value] of Object.entries(radius)) {
    lines.push(`    --radius-${name}: ${value};`);
}

lines.push('');
for (const [name, value] of Object.entries(shadow)) {
    lines.push(`    --shadow-${name}: ${value};`);
}

lines.push('');
for (const [name, value] of Object.entries(easing)) {
    lines.push(`    --ease-${name}: ${value};`);
}

lines.push('');
for (const [name, value] of Object.entries(duration)) {
    lines.push(`    --duration-${name}: ${value};`);
}

const banner = `/*
 * GENERATED FILE — DO NOT EDIT.
 *
 * Written by scripts/build-palette.mjs from tailwind.config.js, which is the
 * single place any design token in this product is authored — colour, type
 * scale, radii, elevation and motion. Editing this file will work until the
 * next \`npm run build\`, and then silently revert.
 *
 * To change a token: edit tailwind.config.js and run \`npm run palette\`
 * (or just \`npm run build\`, which does it for you).
 */`;

const css = `${banner}\n\n@theme {\n${lines.join('\n')}\n}\n`;

// Only rewrite when the content actually changed, so the file's mtime doesn't
// churn and trigger pointless HMR reloads on every dev-server start.
let previous = '';
try {
    previous = readFileSync(outPath, 'utf8');
} catch {
    // First run — no existing file.
}

if (previous !== css) {
    mkdirSync(dirname(outPath), { recursive: true });
    writeFileSync(outPath, css, 'utf8');
    console.log(`palette: wrote ${outPath.replace(root, '.')}`);
} else {
    console.log('palette: up to date');
}

/* --- The landing's copy of the same tokens -------------------------------- */
const blade = `{{--
    GENERATED FILE — DO NOT EDIT.

    Written by scripts/build-palette.mjs from tailwind.config.js. The landing
    ships its own inline stylesheet rather than the Tailwind bundle, so this is
    how the same authored palette reaches it: every colour as \`--color-*\`, and
    every colour again as \`--rgb-*\` (a bare "r, g, b" triplet) for the alpha
    ramps hand-written CSS needs.

    To change a token: edit tailwind.config.js and run \`npm run palette\`.
--}}
<style>
:root {
${colorLines.join('\n')}

${rgbLines.join('\n')}
}
</style>
`;

let previousBlade = '';
try {
    previousBlade = readFileSync(bladePath, 'utf8');
} catch {
    // First run — no existing file.
}

if (previousBlade !== blade) {
    mkdirSync(dirname(bladePath), { recursive: true });
    writeFileSync(bladePath, blade, 'utf8');
    console.log(`palette: wrote ${bladePath.replace(root, '.')}`);
} else {
    console.log('palette: landing partial up to date');
}

/* --- The same tokens, reachable from PHP ---------------------------------
   A CSS custom property cannot reach everywhere a colour is needed. `<meta
   name="theme-color">` is the case that forces this: the browser paints the
   phone's address bar from it, it takes a literal hex, and `var()` is not
   resolved there. Left to itself that is a hardcoded colour in a Blade file,
   which is exactly what this build step exists to prevent — and it is the kind
   that rots silently, because nothing renders wrong on a desktop when it drifts.

   So the palette is emitted a third time as a PHP config, and views ask for
   `config('palette.paper')` instead of typing a hex. Same single source, same
   regeneration, and tests/Feature/BrandConsistencyTest.php can assert the two
   agree. */
const phpPath = resolve(root, 'config/palette.php');

const phpEntries = colorLines
    .map((line) => {
        const m = /^\s*--color-([a-z0-9-]+):\s*(.+);$/.exec(line);
        if (!m) return null;

        return `    '${m[1]}' => '${m[2]}',`;
    })
    .filter(Boolean);

const php = `<?php

/*
 * GENERATED FILE — DO NOT EDIT.
 *
 * Written by scripts/build-palette.mjs from tailwind.config.js, which is the
 * single place any design token in this product is authored.
 *
 * This exists for the handful of places a CSS custom property cannot reach —
 * \`<meta name="theme-color">\` above all, which takes a literal hex and is what
 * paints the address bar on a phone. Read a colour with \`config('palette.paper')\`
 * rather than typing it.
 *
 * To change a token: edit tailwind.config.js and run \`npm run palette\`.
 */

return [
${phpEntries.join('\n')}
];
`;

let previousPhp = '';
try {
    previousPhp = readFileSync(phpPath, 'utf8');
} catch {
    // First run — no existing file.
}

if (previousPhp !== php) {
    mkdirSync(dirname(phpPath), { recursive: true });
    writeFileSync(phpPath, php, 'utf8');
    console.log(`palette: wrote ${phpPath.replace(root, '.')}`);
} else {
    console.log('palette: php config up to date');
}
