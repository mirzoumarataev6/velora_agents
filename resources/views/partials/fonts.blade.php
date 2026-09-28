{{--
    THE TYPE SYSTEM — two self-hosted variable faces, 96 KB for the whole product.

    Why this is a Blade partial and not an @font-face block in app.css: the
    landing (showcase.blade.php) ships its own inline stylesheet and never loads
    the Vite bundle, so a @font-face living in app.css would reach the dashboard
    and auth but not the one page the jury sees first. Declaring the faces here
    means every surface — landing, auth, dashboard — is served by one source.

    ONEST 2.001 (SIL OFL) — UI, body and headings.
      Self-hosted from github.com/simpals/onest, NEVER from a CDN. This matters:
      Bunny's Onest subsets contain NO Tajik glyphs, which is what previously
      disqualified the face here. The upstream release does carry them — verified
      per glyph against this exact file's cmap, not against a declared
      unicode-range (a font advertises "cyrillic-ext" and then renders tofu; the
      only trustworthy check is asking the cmap). All six Tajik pairs are present
      in every weight:
        Ғғ U+0492/3 · Ққ U+049A/B · Ҳҳ U+04B2/3
        Ҷҷ U+04B6/7 · Ӣӣ U+04E2/3 · Ӯӯ U+04EE/F
      Re-run scripts/verify-font-coverage.mjs to re-prove it.

      One variable file (84 KB) covers weights 100–900. Four static cuts would
      have been 214 KB for fewer weights, and the design system uses 400/500/
      600/700/800 — all of them, from this one request.

    INTER 4.1 — numerals only (KPIs, tables, money).
      A numerals-only subset: 12 KB against 226 KB for the two static cuts it
      replaces, and still variable across 100–900. The unicode-range below is
      what routes digits to Inter while every letter stays on Onest, so
      "1 250,00 сомонӣ" sets its figures in Inter and its word in Onest.

      U+20 (space) is deliberately NOT in the range even though the file
      contains it: letting Inter serve every space in the product would change
      word spacing in body copy set in Onest.

    Both are `font-display: swap` and preloaded — they are on the critical path
    for first paint on a projector, where a font swap mid-presentation is far
    more visible than it is on a laptop.
--}}
@php
    // Versioned filenames, so these are immutable and can be cached forever
    // without a manifest or a cache-busting query string.
    $__onest = asset('fonts/onest-var-2.001.woff2');
    $__inter = asset('fonts/inter-numerals-4.1.woff2');
@endphp
<link rel="preload" as="font" type="font/woff2" href="{{ $__onest }}" crossorigin="anonymous" />
<link rel="preload" as="font" type="font/woff2" href="{{ $__inter }}" crossorigin="anonymous" />
<style>
@font-face {
    font-family: 'Onest';
    font-style: normal;
    font-weight: 100 900;
    font-display: swap;
    /* Plain `format('woff2')`, NOT the obsolete `woff2-variations`: the
       variable axis is declared by the weight RANGE above, and a browser that
       does not recognise the old format string discards that src entry — which
       also makes the preload above look unused, because the face it was
       preloaded for never resolved to it. */
    src: url('{{ $__onest }}') format('woff2');
}

/* Numerals only — see the note above. Declared AFTER Onest but listed BEFORE it
   in --font-sans, so the browser reaches for Inter first and falls through to
   Onest for everything outside this range. */
@font-face {
    font-family: 'InterNum';
    font-style: normal;
    font-weight: 100 900;
    font-display: swap;
    src: url('{{ $__inter }}') format('woff2');
    unicode-range: U+24-25, U+2b-2f, U+30-39, U+3a, U+a3, U+d7, U+2013-2014, U+20ac, U+20bd;
}
</style>
