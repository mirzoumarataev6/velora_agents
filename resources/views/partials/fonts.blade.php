{{--
    THE PRODUCT TYPEFACE — Onest 2.001, self-hosted. Declared and preloaded
    here, in one partial, included by every layout.

    WHY IT IS ALL IN ONE PLACE. The @font-face blocks started out in
    resources/css/app.css, which is correct for the dashboard and the auth
    pages and silently wrong for the landing: showcase.blade.php ships its own
    self-contained stylesheet and no Tailwind, so it never loads app.css. It
    therefore preloaded three woff2 files that no @font-face ever claimed, and
    rendered the entire page in whatever the browser picked — the hero came out
    in Times. Faces and preloads have to travel together or they drift, and
    this partial is the one thing every layout already includes.

    WHY NOT THE VITE FONTS PLUGIN. It fetches from fonts.bunny.net, and Bunny
    mirrors Google's subsetting of Onest, whose cyrillic-ext cut contains not
    one Tajik letter. TG is a first-class locale here, which is why an earlier
    pass recorded "Onest: no Tajik at all" and rejected the face outright. The
    fault was the distributor, not the typeface — the foundry's own release
    carries all six pairs (Ғғ Ққ Ҳҳ Ҷҷ Ӣӣ Ӯӯ), verified against the shipped
    woff2's cmap by scripts/verify-font-coverage.mjs. Vendored and re-cut to
    Latin + Cyrillic by scripts/vendor-onest.sh; ~26KB per weight.

    THREE WEIGHTS, AND NO MORE. 400 body · 500 labels, buttons, nav · 600
    headings and KPI figures. The scheme before this ran to 700 and 800 and
    read heavy and dated. No 700 file is shipped, so a stray `font-bold` gets
    a synthesised smear rather than silently reinstating the old weight —
    and app.css maps the bold utilities onto 600 so it does not even do that.

    WHY PRELOAD when the faces are declared right here: a browser does not
    fetch a @font-face's src until something on the page actually matches it,
    which is after layout. The preloads start all three fetches immediately,
    in parallel with the stylesheet. All three are always right — every locale
    this product ships (en/ru/tg) needs Latin and Cyrillic on the same page.

    ?v= must match in both halves of this file, and is bumped when
    scripts/vendor-onest.sh produces different bytes.
--}}
@php $__fontVersion = '2.001'; @endphp

@foreach ([400, 500, 600] as $__weight)
    <link rel="preload" as="font" type="font/woff2"
        href="{{ asset('fonts/onest/onest-'.$__weight.'.woff2') }}?v={{ $__fontVersion }}"
        crossorigin="anonymous" />
@endforeach

{{-- Inline rather than a stylesheet link: three @font-face blocks are under a
     kilobyte, and a separate request here would sit on the critical path
     ahead of the fonts it declares — the exact delay the preloads above exist
     to remove. --}}
<style>
    @font-face {
        font-family: 'Onest';
        font-style: normal;
        font-weight: 400;
        font-display: swap;
        src: url('{{ asset('fonts/onest/onest-400.woff2') }}?v={{ $__fontVersion }}') format('woff2');
    }
    @font-face {
        font-family: 'Onest';
        font-style: normal;
        font-weight: 500;
        font-display: swap;
        src: url('{{ asset('fonts/onest/onest-500.woff2') }}?v={{ $__fontVersion }}') format('woff2');
    }
    @font-face {
        font-family: 'Onest';
        font-style: normal;
        font-weight: 600;
        font-display: swap;
        src: url('{{ asset('fonts/onest/onest-600.woff2') }}?v={{ $__fontVersion }}') format('woff2');
    }
</style>
