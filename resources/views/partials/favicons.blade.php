{{--
    Canonical favicon / app-icon set, generated from the Velora V mark by
    scripts/generate-brand-assets.php. Included in every layout head so the
    brand icon shows on the browser tab across the whole app.
--}}
<link rel="icon" href="/favicon.ico" sizes="48x48" />
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/gen/favicon-32.png') }}" />
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/brand/gen/favicon-16.png') }}" />
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/gen/apple-touch-icon.png') }}" />
<link rel="manifest" href="/site.webmanifest" />
{{-- Browser UI tint. These have to be literal hexes — the meta tag is read
     before any stylesheet, so a var() would resolve to nothing. They mirror
     the canvas and dark canvas from resources/css/tokens.css, and are asserted against the
     retired-colour list in BrandConsistencyTest, which is what catches them
     drifting out of sync with the palette. --}}
<meta name="theme-color" content="#f4f6fb" media="(prefers-color-scheme: light)" />
<meta name="theme-color" content="#0a1226" media="(prefers-color-scheme: dark)" />
