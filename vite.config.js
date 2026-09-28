import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // ONE typeface for the entire product — landing, auth and
                // dashboard — self-hosted so there is no third-party font
                // origin and no render-blocking call to someone else's CDN.
                //
                // Geist (Vercel). Lighter and more refined on the page than
                // Inter: narrower, more open counters and a less mechanical
                // rhythm, which is what the landing needed — the previous face
                // read as chunky at heading sizes. Shipping 300–600 only, so
                // there is no 700 available to make a headline heavy by accident.
                //
                // Coverage is the hard constraint, not taste: TG is a first
                // class locale here, so a face without the Tajik pairs is
                // disqualified no matter how it looks. Verified per glyph
                // against the actual woff2 subsets bunny serves — not the
                // declared unicode-range, which is exactly what makes a font
                // look covered when it is not. Full Latin, full Russian, and
                // all six Tajik pairs:
                //   Ғғ U+0492/3 · Ққ U+049A/B · Ҳҳ U+04B2/3
                //   Ҷҷ U+04B6/7 · Ӣӣ U+04E2/3 · Ӯӯ U+04EE/F
                //
                // Failing that same test, re-confirmed across passes:
                //   Onest, Manrope, Jost, Unbounded, Wix Madefor — no Tajik at all
                //   Rubik                                        — missing Ҳҳ alone
                //   Mona Sans                                    — no Cyrillic whatsoever
                //
                // 300 quiet UI · 400 body · 500 headings · 600 reserved.
                //
                // Verified this pass against the shipped subsets, same method:
                //   Geist              full Latin + Russian + all six Tajik pairs
                //   Manrope            Russian only — no Tajik pairs at all
                //   Plus Jakarta Sans  no Cyrillic whatsoever
                //   General Sans       Fontshare-only, not self-hostable here
                bunny('Geist', {
                    weights: [300, 400, 500, 600],
                }),

                // The display face — headings, the hero, big numbers. Body and
                // UI stay on Geist above; a serif in a table or a form label
                // costs readability at 13px and buys nothing.
                //
                // Source Serif 4 (Adobe). Chosen on coverage first: of the three
                // candidates proposed for this job, Fraunces and Newsreader ship
                // NO Cyrillic at all — verified against Bunny's own CSS API, both
                // return only latin/latin-ext/vietnamese — which disqualifies
                // them outright for a product whose primary locales are Russian
                // and Tajik. Source Serif 4 carries cyrillic AND cyrillic-ext,
                // and its per-glyph coverage is checked (not assumed) by
                // scripts/verify-font-coverage.mjs, which opens the shipped
                // woff2 files and asks the cmap for all six Tajik pairs.
                //
                // On looks it is also the closest of the three to the reference:
                // a Times-descended text serif with low contrast, open counters
                // and a warm, slightly humanist axis — a book serif rather than
                // a fashion serif. Fraunces is a soft-serif display face with a
                // wobble to it, which reads playful, not composed.
                //
                // 400 for headings, 600 where a heading needs weight. No 300:
                // a serif that light goes fragile at display sizes.
                bunny('Source Serif 4', {
                    weights: [400, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
