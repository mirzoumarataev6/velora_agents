/**
 * Per-glyph coverage check for the self-hosted faces in public/fonts.
 *
 * WHY THIS EXISTS
 * ---------------
 * A font's declared `unicode-range` is a promise about which subset file to
 * fetch, not a guarantee that the glyph is inside it. Several faces this
 * project auditioned advertise "cyrillic-ext" and then render Tajik as tofu.
 * The only trustworthy check is opening the actual woff2 that ships and asking
 * its cmap, which is what this does.
 *
 * It is also the specific check that settled a wrong call in this repo. Onest
 * was rejected here once, on evidence that it had no Tajik glyphs at all. That
 * evidence was real but was measured against BUNNY'S subsets of Onest — the
 * upstream release at github.com/simpals/onest carries all six pairs in every
 * weight. Hence the rule the project now follows: Onest is self-hosted from the
 * release and never pulled from a CDN, and this script is how that is re-proved
 * rather than re-assumed.
 *
 * Tajik is a first-class locale here, so a face missing any of the six pairs is
 * disqualified no matter how it looks.
 *
 * Usage: node scripts/verify-font-coverage.mjs
 * Needs no build step — public/fonts holds the shipped files directly.
 * Exits non-zero if anything required is missing, which makes it usable in CI.
 */
import { existsSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as fontkit from 'fontkit';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const fontsDir = resolve(root, 'public/fonts');

/* The six Tajik pairs, plus a Russian and a Latin sentinel so a file that
   somehow contains no Cyrillic at all is caught by the same run. */
const CYRILLIC_REQUIRED = {
    'Latin A': 0x0041,
    'Cyrillic А': 0x0410,
    Ғ: 0x0492,
    ғ: 0x0493,
    Қ: 0x049a,
    қ: 0x049b,
    Ҳ: 0x04b2,
    ҳ: 0x04b3,
    Ҷ: 0x04b6,
    ҷ: 0x04b7,
    Ӣ: 0x04e2,
    ӣ: 0x04e3,
    Ӯ: 0x04ee,
    ӯ: 0x04ef,
};

/* Inter ships here as a numerals-only subset, so it is checked against what it
   is actually for. Asking it for Cyrillic would fail by design. */
const NUMERAL_REQUIRED = Object.fromEntries(
    [...'0123456789.,:%+-/()'].map((character) => [character, character.codePointAt(0)])
);

const EXPECTED = [
    {
        file: 'onest-var-2.001.woff2',
        role: 'UI, body and headings',
        required: CYRILLIC_REQUIRED,
        // A variable file has to actually carry the axis, or the design system's
        // 400/500/600/700/800 all collapse onto one weight.
        axis: 'wght',
    },
    {
        file: 'inter-numerals-4.1.woff2',
        role: 'numerals only (KPIs, tables, money)',
        required: NUMERAL_REQUIRED,
        axis: 'wght',
        // Without `tnum` a column of figures re-flows as its values change.
        feature: 'tnum',
    },
];

if (!existsSync(fontsDir)) {
    console.error(`No ${fontsDir}. The self-hosted faces are missing.`);
    process.exit(1);
}

let failed = false;

for (const { file, role, required, axis, feature } of EXPECTED) {
    const path = resolve(fontsDir, file);

    if (!existsSync(path)) {
        console.error(`✗ ${file} — MISSING (${role})`);
        failed = true;
        continue;
    }

    let font = fontkit.openSync(path);
    if (font.fonts) font = font.fonts[0];

    const missing = Object.entries(required)
        .filter(([, codePoint]) => !font.hasGlyphForCodePoint(codePoint))
        .map(([name]) => name);

    const problems = [];
    if (missing.length > 0) problems.push(`missing glyphs: ${missing.join(' ')}`);
    if (axis && !font.variationAxes?.[axis]) problems.push(`no "${axis}" variation axis`);
    if (feature && !(font.availableFeatures ?? []).includes(feature)) {
        problems.push(`no "${feature}" feature`);
    }

    if (problems.length > 0) {
        console.error(`✗ ${file} (${font.familyName}) — ${problems.join('; ')}`);
        failed = true;
        continue;
    }

    const weights = font.variationAxes[axis];
    console.log(
        `✓ ${file} — ${font.familyName} ${font.version ?? ''}`.trim() +
            `\n    ${role}; ${Object.keys(required).length} required codepoints present; ` +
            `weights ${weights.min}–${weights.max}`
    );
}

/* Anything else in the directory is either a licence or a face nobody declared.
   The second is worth saying out loud: an orphaned font file is dead weight in
   the deploy and usually the leftover of a face that was swapped out. */
const declared = new Set(EXPECTED.map((entry) => entry.file));
const orphans = readdirSync(fontsDir).filter((name) => name.endsWith('.woff2') && !declared.has(name));

if (orphans.length > 0) {
    console.warn(`! Undeclared font files in public/fonts: ${orphans.join(', ')}`);
}

if (failed) {
    console.error('\nFont coverage check FAILED.');
    process.exit(1);
}

console.log('\nFont coverage OK.');
