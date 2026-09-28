/**
 * Compiles the landing's copy of the design tokens out of resources/css/tokens.css.
 *
 * WHY THIS STILL EXISTS
 * ---------------------
 * It used to compile tailwind.config.js into a `@theme` block, because Tailwind
 * v4 generates the `--color-*` custom properties from `@theme` and a v3-style JS
 * config loaded via `@config` only generates utilities. That round trip is gone:
 * tokens.css IS the `@theme` block now, authored directly, so nothing has to be
 * compiled for the app itself.
 *
 * One consumer remains. The landing (resources/views/showcase.blade.php) ships
 * its own inline stylesheet and never loads the Vite bundle, so it cannot
 * `@import` tokens.css. It gets the same tokens as a <style> partial instead,
 * which is what keeps the palette in exactly one authored place while the
 * landing is still Blade.
 *
 * THIS SCRIPT AND THE PARTIAL IT WRITES BOTH GO IN PHASE (f), when the landing
 * becomes a Vue page and joins the bundle like every other surface.
 *
 * What it emits, for every semantic token in tokens.css:
 *   --color-<name>  the resolved literal (var() chains followed to a real value)
 *   --rgb-<name>    a bare "r, g, b" triplet, for the alpha ramps hand-written
 *                   CSS needs — `rgba(var(--rgb-x), .3)` is the only form that
 *                   works, and deriving it here is what stops the landing from
 *                   hard-coding a triplet next to the token it came from.
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
const tokensPath = resolve(root, 'resources/css/tokens.css');
const bladePath = resolve(root, 'resources/views/partials/palette-vars.blade.php');

/* Comments are stripped before anything else rather than per-block: the token
   file documents nearly every value, and a `{` inside one of those comments
   would otherwise be counted by the brace walk below and end a block early. */
const css = readFileSync(tokensPath, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

/**
 * Pull `--name: value;` pairs out of one brace-delimited block.
 *
 * Deliberately a regex over a real CSS parser: the input is a file this repo
 * authors and whose shape is fixed, so a parser dependency would buy nothing.
 * Comments are stripped first so a commented-out token cannot leak into the
 * output.
 */
const declarationsIn = (source, opener) => {
    const start = source.indexOf(opener);
    if (start === -1) throw new Error(`build-palette: no "${opener}" block in tokens.css`);

    // Walk braces from the opener so a nested block cannot end the match early.
    let depth = 0;
    let end = -1;
    for (let i = source.indexOf('{', start); i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) {
            end = i;
            break;
        }
    }
    if (end === -1) throw new Error(`build-palette: unterminated "${opener}" block`);

    const body = source.slice(source.indexOf('{', start) + 1, end).replace(/\/\*[\s\S]*?\*\//g, '');
    const out = new Map();
    for (const [, name, value] of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
        out.set(name, value.trim());
    }

    return out;
};

const raw = declarationsIn(css, ':root {');
const semantic = declarationsIn(css, '@theme inline');

/**
 * Follow `var(--a)` chains down to a literal.
 *
 * The light theme only: this partial is a static stylesheet, and the landing
 * carries its own dark-mode block. A token that resolves to another var is
 * looked up in the raw :root map, which is where every swatch is declared.
 */
const resolveValue = (value, seen = new Set()) => {
    const match = /^var\(\s*(--[\w-]+)\s*\)$/.exec(value);
    if (!match) return value;

    const next = match[1];
    if (seen.has(next)) return value; // a cycle — emit the var() and move on
    seen.add(next);

    const target = raw.get(next) ?? semantic.get(next);

    return target === undefined ? value : resolveValue(target, seen);
};

const toRgb = (hex) => {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim());
    if (!m) return null;
    const full = m[1].length === 3 ? [...m[1]].map((c) => c + c).join('') : m[1];
    const n = parseInt(full, 16);

    return `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`;
};

const colorLines = [];
const rgbLines = [];

for (const [name, value] of semantic) {
    if (!name.startsWith('--color-')) continue;

    const resolved = resolveValue(value);
    colorLines.push(`    ${name}: ${resolved};`);

    const rgb = toRgb(resolved);
    if (rgb) rgbLines.push(`    --rgb-${name.slice('--color-'.length)}: ${rgb};`);
}

if (colorLines.length === 0) {
    throw new Error('build-palette: resolved no colours — tokens.css shape changed?');
}

const blade = `{{--
    GENERATED FILE — DO NOT EDIT.

    Written by scripts/build-palette.mjs from resources/css/tokens.css, which is
    the single place any design token in this product is authored. The landing
    ships its own inline stylesheet rather than the Tailwind bundle, so this is
    how the same authored palette reaches it: every colour as \`--color-*\`, and
    every colour again as \`--rgb-*\` (a bare "r, g, b" triplet) for the alpha
    ramps hand-written CSS needs.

    Light theme only — the landing carries its own dark-mode block.

    To change a token: edit resources/css/tokens.css and run \`npm run palette\`.
    This file and the script that writes it are both deleted in phase (f), when
    the landing becomes a Vue page and reads tokens.css directly.
--}}
<style>
:root {
${colorLines.join('\n')}

${rgbLines.join('\n')}
}
</style>
`;

let previous = '';
try {
    previous = readFileSync(bladePath, 'utf8');
} catch {
    // First run — no existing file.
}

if (previous !== blade) {
    mkdirSync(dirname(bladePath), { recursive: true });
    writeFileSync(bladePath, blade, 'utf8');
    console.log(`palette: wrote ${bladePath.replace(root, '.')} (${colorLines.length} tokens)`);
} else {
    console.log('palette: landing partial up to date');
}
