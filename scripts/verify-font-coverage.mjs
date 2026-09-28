/**
 * Per-glyph coverage check for every self-hosted face.
 *
 * Why this exists: a font's declared `unicode-range` is a promise about which
 * subset file to fetch, not a guarantee that the glyph is inside it. Several
 * faces this project auditioned advertise "cyrillic-ext" and then render Tajik
 * as tofu. The only trustworthy check is opening the actual woff2 that ships in
 * public/build and asking the cmap.
 *
 * Tajik is a first-class locale here, so a face missing any of the six pairs is
 * disqualified no matter how it looks.
 *
 * Usage: node scripts/verify-font-coverage.mjs   (after `npm run build`)
 * Exits non-zero if any required codepoint is missing from every subset of a
 * family, which is what makes it usable in CI.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { resolve, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as fontkit from 'fontkit';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const assets = resolve(root, 'public/build/assets');

/* The six Tajik pairs, plus a Russian and a Latin sentinel so a file that
   somehow contains no Cyrillic at all is caught by the same run. */
const REQUIRED = {
    'Latin A': 0x0041,
    'Cyrillic А': 0x0410,
    'Ғ': 0x0492,
    'ғ': 0x0493,
    'Қ': 0x049a,
    'қ': 0x049b,
    'Ҳ': 0x04b2,
    'ҳ': 0x04b3,
    'Ҷ': 0x04b6,
    'ҷ': 0x04b7,
    'Ӣ': 0x04e2,
    'ӣ': 0x04e3,
    'Ӯ': 0x04ee,
    'ӯ': 0x04ef,
};

const files = readdirSync(assets).filter((f) => f.endsWith('.woff2'));
if (files.length === 0) {
    console.error('No .woff2 in public/build/assets — run `npm run build` first.');
    process.exit(1);
}

/* Parse the generated stylesheet so routing can be checked as well as
   ownership: each @font-face gives a family, a woff2 and a unicode-range. */
const sheet = readdirSync(assets).find((f) => /^fonts-.*\.css$/.test(f));
const fontFaces = [];
if (sheet) {
    const css = readFileSync(resolve(assets, sheet), 'utf8');
    for (const block of css.match(/@font-face\s*\{[^}]*\}/g) ?? []) {
        const family = /font-family:\s*"([^"]+)"/.exec(block)?.[1];
        const url = /url\("?([^")]+\.woff2)"?\)/.exec(block)?.[1];
        const range = /unicode-range:\s*([^;]+);/.exec(block)?.[1];
        if (family && url && range) {
            // Slug the family the same way the asset filenames are slugged.
            const slug = family.toLowerCase().replace(/\s+/g, '-');
            fontFaces.push({ family: slug, file: basename(url), range });
        }
    }
}

const covers = (range, cp) =>
    range.split(',').some((part) => {
        const clean = part.trim().replace(/^[Uu]\+/, '');
        if (clean.includes('-')) {
            const [from, to] = clean.split('-');
            return cp >= parseInt(from, 16) && cp <= parseInt(to, 16);
        }

        return parseInt(clean, 16) === cp;
    });

/* Files are named like `source-serif-4-400-normal-<hash>.woff2`; the family is
   everything before the weight. */
const families = new Map();
for (const file of files) {
    const family = file.replace(/-\d{3}-(normal|italic)-[^.]*\.woff2$/, '');
    if (!families.has(family)) families.set(family, []);
    families.get(family).push(file);
}

let failed = false;

for (const [family, subsetFiles] of [...families].sort()) {
    /* A family's glyphs are split across subset files, so coverage is the union:
       a codepoint counts as present if ANY subset of that family has it. */
    const covered = new Set();

    for (const file of subsetFiles) {
        let font;
        try {
            font = fontkit.create(readFileSync(resolve(assets, file)));
        } catch (error) {
            console.error(`  ! could not parse ${file}: ${error.message}`);
            failed = true;
            continue;
        }

        for (const [, cp] of Object.entries(REQUIRED)) {
            // hasGlyphForCodePoint is the honest question; characterSet can
            // include codepoints mapped to .notdef on some builds.
            if (font.hasGlyphForCodePoint(cp)) covered.add(cp);
        }
    }

    const missing = Object.entries(REQUIRED).filter(([, cp]) => !covered.has(cp));
    const label = `${family} (${subsetFiles.length} subsets)`;

    if (missing.length === 0) {
        console.log(`PASS  ${label} — full Latin + Russian + all six Tajik pairs`);
    } else {
        failed = true;
        console.log(`FAIL  ${label} — missing ${missing.map(([name]) => name).join(' ')}`);
    }

    /* Owning the glyph is only half of it. A browser downloads a subset file
       ONLY if some @font-face declares a unicode-range covering the character —
       so a family can ship the glyph in a file nothing ever asks for, and the
       page still renders tofu. Check the generated stylesheet routes each
       codepoint to a file that actually has it. */
    const unrouted = Object.entries(REQUIRED).filter(([, cp]) => {
        const faces = fontFaces.filter(
            (face) => face.family === family && covers(face.range, cp)
        );

        return !faces.some((face) => {
            const file = resolve(assets, face.file);
            try {
                return fontkit.create(readFileSync(file)).hasGlyphForCodePoint(cp);
            } catch {
                return false;
            }
        });
    });

    if (unrouted.length > 0) {
        failed = true;
        console.log(
            `FAIL  ${label} — no @font-face routes ${unrouted.map(([n]) => n).join(' ')} ` +
                'to a file containing it (the glyph exists but will never be fetched)'
        );
    } else {
        console.log(`      ${family} — every required codepoint is routed by a declared unicode-range`);
    }
}

process.exit(failed ? 1 : 0);
