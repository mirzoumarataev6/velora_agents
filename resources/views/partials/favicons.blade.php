{{--
    Canonical favicon / app-icon set, generated from the Velora V mark by
    scripts/generate-brand-assets.php. Included in every layout head so the
    brand icon shows on the browser tab across the whole app.

    ?v= IS NOT OPTIONAL. A favicon is the most aggressively cached asset a site
    serves — browsers hold /favicon.ico across sessions and often ignore
    revalidation entirely — and these files keep their filenames when the mark
    is regenerated. Without a version query, an owner who saw the old orange
    mark keeps seeing it indefinitely, on a URL that has served blue for weeks.
    That is exactly what happened during this re-skin, and it cost a round of
    "the logo is still orange" against a codebase with no orange left in it.

    Bump config('agent.brand_mark_version') whenever
    scripts/generate-brand-assets.php changes the artwork — that config value
    is now the ONE place this number lives, shared with every in-app <img>
    that renders the mark itself (see layouts/dashboard.blade.php and
    layouts/auth.blade.php), so the favicon and the sidebar logo can no longer
    go stale independently of each other the way they just did.
--}}
@php $__iconVersion = config('agent.brand_mark_version'); @endphp
<link rel="icon" href="/favicon.ico?v={{ $__iconVersion }}" sizes="48x48" />
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/gen/favicon-32.png') }}?v={{ $__iconVersion }}" />
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/brand/gen/favicon-16.png') }}?v={{ $__iconVersion }}" />
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/gen/apple-touch-icon.png') }}?v={{ $__iconVersion }}" />
<link rel="manifest" href="/site.webmanifest?v={{ $__iconVersion }}" />
{{-- Browser UI tint — what paints the address bar on a phone.

     The meta tag is read before any stylesheet, so a var() would resolve to
     nothing and these must be literal hexes. Rather than type two, they come
     from config/palette.php, which scripts/build-palette.mjs generates from the
     same tailwind.config.js as every other token — so the address bar cannot
     drift away from the canvas the way it silently did under the last two
     re-skins.

     Left keyed to `prefers-color-scheme` on purpose, unlike the page's own
     "nothing stored means light" default: the page itself (the `dark` class
     the boot script sets) is the source of truth for what actually renders,
     and these two tags only tint chrome outside that page — the worst a
     mismatch costs is an address bar one shade off from the content under it
     on a dark OS, never a flash of the wrong theme in the content itself. --}}
<meta name="theme-color" content="{{ config('palette.paper') }}" media="(prefers-color-scheme: light)" />
<meta name="theme-color" content="{{ config('palette.night') }}" media="(prefers-color-scheme: dark)" />
