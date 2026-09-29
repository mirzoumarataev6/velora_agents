/**
 * Per-glyph coverage check for every self-hosted face.
 *
 * Why this exists: a font's declared `unicode-range` is a promise about which
 * subset file to fetch, not a guarantee that the glyph is inside it. Several
 * faces this project auditioned advertise "cyrillic-ext" and then render Tajik
 * as tofu — and the current face, Onest, is one of them AS GOOGLE AND BUNNY
 * DISTRIBUTE IT. That is what got Onest wrongly rejected on an earlier pass.
 * The foundry's own release is complete; the redistributor's cut is not. The
 * only trustworthy check is opening the actual woff2 that ships and asking the
 * cmap, which is what this does.
 *
 * Tajik is a first-class locale here, so a face missing any of the six pairs is
 * disqualified no matter how it looks.
 *
 * The faces are served straight from public/fonts/ (not bundled through Vite),
 * so this needs no build first and can run in CI on a clean checkout.
 *
 * Usage: node scripts/verify-font-coverage.mjs   (or `npm run fonts:verify`)
 * Exits non-zero if any required codepoint is missing from a shipped face.
 */
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as fontkit from 'fontkit';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const fontsDir = resolve(root, 'public/fonts');

/* The six Tajik pairs, plus a Russian and a Latin sentinel so a file that
   somehow contains no Cyrillic at all is caught by the same run, plus the
   punctuation the product actually sets: a missing № or em dash is a visible
   hole in a Russian interface. */
const REQUIRED = {
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
    '№': 0x2116,
    '—': 0x2014,
    '«': 0x00ab,
    '…': 0x2026,
};

if (!existsSync(fontsDir)) {
    console.error(`No ${fontsDir} — run \`bash scripts/vendor-onest.sh\` first.`);
    process.exit(1);
}

/* One directory per family, one file per weight. Flat and boring on purpose:
   the previous layout was seven unicode-range subsets per weight per family,
   and "is this glyph shipped?" needed a union across all of them. */
const families = readdirSync(fontsDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort();

if (families.length === 0) {
    console.error(`No font families under ${fontsDir}.`);
    process.exit(1);
}

let failed = false;

for (const family of families) {
    const dir = resolve(fontsDir, family);
    const files = readdirSync(dir)
        .filter((f) => f.endsWith('.woff2'))
        .sort();

    if (files.length === 0) {
        console.error(`✗ ${family}: no .woff2 shipped.`);
        failed = true;
        continue;
    }

    /* Every weight is checked independently. A family whose 400 has Tajik and
       whose 600 does not is broken in exactly the way that is hardest to spot:
       the body text renders and the headings go to tofu. */
    for (const file of files) {
        let font;
        try {
            font = fontkit.create(readFileSync(resolve(dir, file)));
        } catch (error) {
            console.error(`  ! could not parse ${family}/${file}: ${error.message}`);
            failed = true;
            continue;
        }

        // hasGlyphForCodePoint is the honest question; characterSet can
        // include codepoints mapped to .notdef on some builds.
        const missing = Object.entries(REQUIRED)
            .filter(([, cp]) => !font.hasGlyphForCodePoint(cp))
            .map(([name]) => name);

        if (missing.length > 0) {
            console.error(`✗ ${family}/${file} (${font.postscriptName}) is missing: ${missing.join(' ')}`);
            failed = true;
        } else {
            console.log(`✓ ${family}/${file} (${font.postscriptName}) — ${font.numGlyphs} glyphs, all required codepoints present`);
        }
    }
}

if (failed) {
    console.error('\nFont coverage check FAILED.');
    process.exit(1);
}

console.log('\nAll shipped faces cover Latin, Russian and Tajik.');
