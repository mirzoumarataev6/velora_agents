{{--
    The Inertia root template — the only document shell a Vue page ever gets.

    It is deliberately the same shell as layouts/dashboard in
    every respect that happens BEFORE Vue boots: the theme and stage-mode
    pre-paint scripts, the fonts, the favicons. Those cannot move into Vue,
    because all three decide how the very first frame is painted and Vue does
    not exist yet when that frame is drawn.

    Everything after that point is Vue's. There is no @vite('app.js') here —
    the Alpine bundle belongs to the Blade pages, which are still live during
    the migration. Inertia pages load resources/js/inertia.js instead, so
    neither side pays for the other's framework.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
{{-- Declares that this page has its own dark theme. Without it Samsung
     Internet's (and Chrome's auto) dark mode force-darkens the light theme. --}}
<meta name="color-scheme" content="light dark" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<script>
    // Runs before first paint. The account's saved theme wins when there is one
    // (so the choice follows the owner between devices); localStorage is the
    // local cache that keeps this synchronous, and the OS setting is the
    // fallback that "system" deliberately falls back to.
    //
    // A null column means "no account-level override" — which is also what a
    // brand-new account has. It must NOT clear the cached choice: the theme
    // toggle lives on the auth pages too, where a guest has no account to write
    // to and localStorage is the only record of what they picked.
    (function () {
        var saved = @json(auth()->user()?->theme);
        if (saved) {
            try { localStorage.setItem('theme', saved); } catch (e) {}
        }
        var stored = saved;
        try { stored = stored || localStorage.getItem('theme'); } catch (e) {}
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', stored ? stored === 'dark' : prefersDark);

        // Same trick for the sidebar. Vue boots after first paint, so without
        // this a rail the owner collapsed would flash open on every navigation
        // before AppSidebar mounts. The class sizes the rail in CSS until Vue
        // takes the state over, and AppSidebar reads its initial value back out
        // of it rather than from storage — one source of truth, no chance of
        // the two disagreeing on the first frame.
        try {
            if (localStorage.getItem('sidebar:collapsed') === '1') {
                document.documentElement.classList.add('rail-collapsed');
            }
        } catch (e) {}
    })();
</script>
@include('partials.stage-mode')

<title inertia>{{ config('app.name', 'Velora') }}</title>
@include('partials.favicons')
@include('partials.fonts')
@vite(['resources/css/app.css', 'resources/js/inertia.js'])
@inertiaHead
</head>
<body class="min-h-screen bg-canvas text-text antialiased">
@inertia
</body>
</html>
