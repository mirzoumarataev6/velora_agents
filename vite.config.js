import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // TWO JS entries, for as long as the migration runs.
            //
            //   app.js      Alpine — every Blade page not yet migrated
            //   inertia.js  Vue + Inertia — every migrated page
            //
            // A Blade page therefore never downloads Vue, and an Inertia page
            // never downloads Alpine. When the last Blade view goes, so does
            // app.js and the first of these lines.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/inertia.js'],
            refresh: true,

            // NOTE: there is no `fonts:` block here any more, and that is
            // deliberate rather than an omission.
            //
            // The product used to pull Geist and Source Serif 4 through the
            // plugin's `bunny()` helper. The type system is now Onest (UI and
            // headings) plus a numerals-only cut of Inter, and BOTH are served
            // from public/fonts/ with @font-face declared in
            // resources/views/partials/fonts.blade.php. Two reasons it cannot
            // go back through this plugin:
            //
            //   1. Bunny's Onest subsets contain no Tajik glyphs. The upstream
            //      release at github.com/simpals/onest does — verified per
            //      glyph against the shipped file's cmap — so the font has to
            //      come from the release, not from a CDN mirror of it.
            //      scripts/verify-font-coverage.mjs re-proves this on demand.
            //
            //   2. The landing (showcase.blade.php) ships its own inline
            //      stylesheet and never loads this bundle, so a face reaching
            //      the page only through Vite would be missing on the one
            //      screen a jury sees first. A Blade partial reaches every
            //      surface; a Vite input does not.
            //
            // Both faces are variable and versioned in their filename, so they
            // are immutable and cacheable without a manifest: 84 KB of Onest
            // covers weights 100–900, and 12 KB of Inter covers every figure.
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Inertia's <Link> takes a URL in `href`, and Vue's asset
                    // handling would otherwise try to resolve it at build time.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],

    resolve: {
        // The ARRAY form, not the object form: an object alias matches by
        // prefix, so aliasing "apexcharts" also rewrote the library's own
        // internal `apexcharts/core` import into a path inside a file. These
        // are exact-match regexes.
        alias: [
            {
                // Ziggy's JavaScript half comes from the Composer package
                // rather than from a separate npm install of `ziggy-js`. One
                // fewer dependency, and — the actual reason — the route helper
                // can never drift out of step with the PHP that generates the
                // route list, because there is only one version of Ziggy in
                // the project.
                find: /^ziggy-js$/,
                replacement: new URL('vendor/tightenco/ziggy/dist/index.esm.js', import.meta.url).pathname,
            },
            {
                // Pin ApexCharts to its BROWSER build.
                //
                // Its package exports map `node` → apexcharts.ssr.esm.js and
                // `browser` → apexcharts.esm.js. Vite resolved our import and
                // vue3-apexcharts' through different conditions and shipped
                // BOTH: 136 KB + 272 KB gzipped of the same library, on the one
                // page a demo is judged on. Exact match only, so the library's
                // own `apexcharts/core` and `apexcharts/area` subpaths still
                // resolve normally.
                find: /^apexcharts$/,
                replacement: new URL('node_modules/apexcharts/dist/apexcharts.esm.js', import.meta.url).pathname,
            },
        ],
    },

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
