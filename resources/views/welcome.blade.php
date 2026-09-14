{{--
    Landing page — built to the measured design of the reference (aave.com/gho),
    with our palette and our copy.

    Values taken from the reference's own stylesheet rather than eyeballed:

        H1            72px / 48px mobile, weight 500, line-height 110%,
                      letter-spacing -3.6px (-0.05em), CENTRED, with one word
                      set in italic serif in the accent colour
        H2            40px, weight 500, line-height 1.2, letter-spacing -1.2px
        card title    16px, weight 500, line-height 1.36
        body          16px, weight 400, line-height 145%, letter-spacing -0.16px
        section pad   100px 48px desktop · 3rem 1.25rem mobile
        radii         16 / 24 / 32px, pills at 100px
        containers    768px for text, ~1080px for wide rows
        surfaces      LIGHT — white and a near-white tint, warm near-black text

    Note the page is light, not dark: the earlier attempt was built from a text
    summary that described it as dark, which is why it didn't match.

    Section order mirrors the reference exactly:
        hero → pillars(3) → setup(+metric,3 steps) → flow diagram → demo →
        numbers(3) → two paths → deeper(3) → start(3) → faq → footer

    Text: every visible string carries data-i18n so the locale switch can swap it
    in place. Strings rendered by JS (chat) read from window.__i18n directly.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8" />
<script>
    (function () {
        var stored = localStorage.getItem('theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', stored ? stored === 'dark' : prefersDark);
    })();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="color-scheme" content="light dark" />
@include('partials.seo-meta', [
    'seoTitle' => __('landing.meta.title'),
    'seoDescription' => __('landing.meta.description'),
])
@include('partials.fonts')
@vite(['resources/css/app.css'])
<style>
    /* ---------- palette aliases (values live in tailwind.config.js) -------- */
    :root {
        --bg: var(--color-cream-100);
        --bg-tint: var(--color-paper);
        --surface: #ffffff;
        --ink: var(--color-ink);
        --ink-2: color-mix(in srgb, var(--color-ink) 68%, transparent);
        --ink-3: color-mix(in srgb, var(--color-ink) 48%, transparent);
        --line: color-mix(in srgb, var(--color-ink) 10%, transparent);
        --line-2: color-mix(in srgb, var(--color-ink) 5%, transparent);
        --accent: var(--color-brand);
        --accent-text: var(--color-brand-strong);
        --grad-a: var(--color-brand-strong);
        --grad-b: var(--color-brand-deep);
        --glow: color-mix(in srgb, var(--color-grad-from) 30%, transparent);
        --tint: color-mix(in srgb, var(--color-brand) 10%, transparent);
        --shadow: 0 1px 2px rgba(43, 34, 26, .04), 0 12px 32px -20px rgba(43, 34, 26, .3);
        --shadow-lg: 0 30px 70px -40px rgba(43, 34, 26, .45);
        --ok: var(--color-success);
    }
    .dark {
        --bg: var(--color-night);
        --bg-tint: var(--color-night-soft);
        --surface: var(--color-night-card);
        --ink: var(--color-night-ink);
        --ink-2: color-mix(in srgb, var(--color-night-ink) 68%, transparent);
        --ink-3: color-mix(in srgb, var(--color-night-ink) 45%, transparent);
        --line: color-mix(in srgb, var(--color-night-ink) 12%, transparent);
        --line-2: color-mix(in srgb, var(--color-night-ink) 6%, transparent);
        --accent: var(--color-brand-light);
        --accent-text: var(--color-brand-light);
        --grad-a: var(--color-brand-strong);
        --grad-b: var(--color-brand-deep);
        --glow: color-mix(in srgb, var(--color-grad-to) 24%, transparent);
        --tint: color-mix(in srgb, var(--color-brand-light) 14%, transparent);
        --shadow: 0 1px 2px rgba(0,0,0,.5), 0 12px 32px -20px rgba(0,0,0,.8);
        --shadow-lg: 0 30px 70px -40px rgba(0,0,0,1);
        --ok: var(--color-success-light);
    }

    html { scroll-behavior: smooth; scroll-padding-top: 5.5rem; }

    body {
        font-family: var(--font-sans);
        background: var(--bg);
        color: var(--ink);
        font-size: 16px;
        font-weight: 400;
        line-height: 1.45;
        letter-spacing: -0.01em;
        -webkit-font-smoothing: antialiased;
        transition: background-color .5s ease, color .5s ease;
    }

    /* ---------- type scale (measured from the reference) ------------------ */
    .t-hero {
        margin: 0;
        font-size: 72px;
        font-weight: 500;
        line-height: 110%;
        letter-spacing: -0.05em;
        text-align: center;
        color: var(--ink);
    }
    @media (max-width: 768px) {
        .t-hero { font-size: 42px; letter-spacing: -0.03em; }
    }
    .t-h2 {
        margin: 0;
        font-size: 40px;
        font-weight: 500;
        line-height: 1.2;
        letter-spacing: -0.03em;
        color: var(--ink);
        text-wrap: balance;
    }
    @media (max-width: 768px) { .t-h2 { font-size: 30px; } }
    .t-card {
        margin: 0;
        font-size: 16px;
        font-weight: 500;
        line-height: 1.36;
        letter-spacing: -0.01em;
        color: var(--ink);
    }
    .t-sub { color: var(--ink-2); font-size: 16px; line-height: 145%; letter-spacing: -0.01em; }
    .t-small { color: var(--ink-3); font-size: 14px; line-height: 1.45; }

    /* The emphasised word in the hero. The italic serif it used to be is gone
       along with the second webfont: the system is one family now, so emphasis
       is weight and colour. Rebuilt properly in the landing pass. */
    .em-serif {
        font-weight: 700;
        color: var(--accent-text);
    }

    .eyebrow {
        display: block;
        font-size: 14px;
        font-weight: 500;
        letter-spacing: -0.01em;
        color: var(--ink-3);
    }

    /* ---------- layout --------------------------------------------------- */
    .section { padding: 100px 48px; }
    @media (max-width: 768px) { .section { padding: 3rem 1.25rem; } }
    .wrap { max-width: 1080px; margin: 0 auto; }
    .wrap-narrow { max-width: 768px; margin: 0 auto; }

    .panel {
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 24px;
        transition: background-color .5s ease, border-color .4s ease, transform .45s cubic-bezier(.22,1,.36,1), box-shadow .45s ease;
    }
    .panel-lift:hover { transform: translateY(-4px); box-shadow: var(--shadow); }
    .rule { height: 1px; background: var(--line); }

    /* ---------- buttons (pill, 100px radius as the reference) ------------- */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        border-radius: 100px;
        padding: 14px 28px;
        font-size: 15px;
        font-weight: 500;
        letter-spacing: -0.01em;
        white-space: nowrap;
        cursor: pointer;
        transition: transform .3s cubic-bezier(.22,1,.36,1), background-color .3s ease, border-color .3s ease, color .3s ease, box-shadow .3s ease;
    }
    .btn-primary {
        color: #fff;
        background: linear-gradient(100deg, var(--grad-a), var(--grad-b));
        box-shadow: 0 8px 22px -10px color-mix(in srgb, var(--color-brand) 60%, transparent);
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 14px 30px -12px color-mix(in srgb, var(--color-brand) 70%, transparent); }
    .btn-ghost {
        color: var(--ink);
        background: var(--surface);
        border: 1px solid var(--line);
    }
    .btn-ghost:hover { transform: translateY(-2px); border-color: var(--accent); color: var(--accent-text); }

    .link-arrow {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-size: 15px;
        font-weight: 500;
        color: var(--accent-text);
        transition: gap .3s cubic-bezier(.22,1,.36,1);
    }
    .link-arrow:hover { gap: .7rem; }

    .icon-plate {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 16px;
        color: var(--accent-text);
        background: var(--tint);
    }

    /* ---------- header ---------------------------------------------------- */
    #hdr { transition: background-color .4s ease, border-color .4s ease, backdrop-filter .4s ease; border-bottom: 1px solid transparent; }
    #hdr.stuck {
        background: color-mix(in srgb, var(--bg) 82%, transparent);
        backdrop-filter: blur(18px) saturate(180%);
        -webkit-backdrop-filter: blur(18px) saturate(180%);
        border-bottom-color: var(--line);
    }
    .nav-link { font-size: 15px; font-weight: 500; color: var(--ink-2); transition: color .25s ease; }
    .nav-link:hover { color: var(--ink); }

    #mnav { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .4s cubic-bezier(.22,1,.36,1); }
    #mnav.open { grid-template-rows: 1fr; }
    #mnav > div { overflow: hidden; }

    .switcher-wrap .locale-switcher summary { background: var(--surface); border-color: var(--line); color: var(--ink); }
    .switcher-wrap .locale-switcher summary:hover { border-color: var(--accent); }
    .switcher-wrap .locale-switcher > div { background: var(--surface); border-color: var(--line); box-shadow: var(--shadow); }
    .switcher-wrap .locale-switcher a { color: var(--ink); }
    .switcher-wrap .locale-switcher a:hover { background: var(--bg-tint); }
    .switcher-wrap .locale-switcher a.text-brand { color: var(--accent-text); }

    /* ---------- hero word reveal ------------------------------------------
       The reference animates the headline one WORD at a time (each word is its
       own inline-block with its own opacity/transform). Same here. */
    .word {
        display: inline-block;
        opacity: 0;
        transform: translateY(0.35em);
        transition: opacity .6s cubic-bezier(.16,1,.3,1), transform .6s cubic-bezier(.16,1,.3,1);
    }
    .word.in { opacity: 1; transform: none; }

    /* ---------- big numerals ---------------------------------------------- */
    .numeral {
        font-size: 64px;
        font-weight: 500;
        line-height: 1;
        letter-spacing: -0.04em;
        font-variant-numeric: tabular-nums;
        color: var(--ink);
    }
    @media (max-width: 768px) { .numeral { font-size: 44px; } }

    /* ---------- flow diagram ----------------------------------------------
       Four nodes on one axis; a highlight walks the chain and each connector
       fills behind it. Horizontal ≥900px, vertical below — the connectors
       switch axis in a media query rather than being duplicated in markup. */
    .flow { display: grid; gap: 2.25rem 1rem; grid-template-columns: 1fr; }
    @media (min-width: 900px) { .flow { grid-template-columns: repeat(4, 1fr); } }

    .fnode {
        position: relative;
        padding: 24px;
        border-radius: 24px;
        background: var(--surface);
        border: 1px solid var(--line);
        transition: transform .5s cubic-bezier(.22,1,.36,1), border-color .5s ease, box-shadow .5s ease;
    }
    .fnode.on { transform: translateY(-5px); border-color: color-mix(in srgb, var(--color-brand) 35%, transparent); box-shadow: var(--shadow); }

    .fnum {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; border-radius: 100px;
        font-size: 13px; font-weight: 500;
        color: var(--ink-3); background: var(--bg-tint); border: 1px solid var(--line);
        transition: color .5s ease, background .5s ease, border-color .5s ease;
    }
    .fnode.on .fnum { color: #fff; background: linear-gradient(140deg, var(--grad-a), var(--grad-b)); border-color: transparent; }

    .ftrack, .flink { position: absolute; border-radius: 2px; pointer-events: none; }
    .ftrack { background: var(--line); }
    .flink { background: linear-gradient(90deg, var(--color-grad-from), var(--color-grad-to)); transition: transform .65s cubic-bezier(.4,0,.2,1); }
    @media (min-width: 900px) {
        .ftrack, .flink { top: 50%; left: 100%; width: 1rem; height: 2px; margin-top: -1px; }
        .flink { transform-origin: left center; transform: scaleX(0); }
        .fnode.done .flink { transform: scaleX(1); }
    }
    @media (max-width: 899px) {
        .ftrack, .flink { left: 50%; top: 100%; height: 2.25rem; width: 2px; margin-left: -1px; }
        .flink { transform-origin: center top; transform: scaleY(0); }
        .fnode.done .flink { transform: scaleY(1); }
    }

    /* ---------- chat demo -------------------------------------------------- */
    .device { width: 100%; max-width: 340px; border-radius: 32px; padding: 10px; background: var(--bg-tint); border: 1px solid var(--line); box-shadow: var(--shadow-lg); }
    .screen { display: flex; flex-direction: column; height: 520px; border-radius: 24px; overflow: hidden; background: var(--surface); border: 1px solid var(--line-2); }
    .chat { flex: 1; display: flex; flex-direction: column; gap: 8px; padding: 16px; overflow-y: auto; scrollbar-width: none; }
    .chat::-webkit-scrollbar { display: none; }
    .bub {
        max-width: 84%; padding: 10px 14px; font-size: 14px; line-height: 1.45;
        opacity: 0; transform: translateY(8px);
        transition: opacity .35s ease, transform .35s cubic-bezier(.22,1,.36,1);
    }
    .bub.in { opacity: 1; transform: none; }
    .bub-u { align-self: flex-end; border-radius: 16px 16px 4px 16px; color: #fff; background: linear-gradient(100deg, var(--grad-a), var(--grad-b)); }
    .bub-a { align-self: flex-start; border-radius: 16px 16px 16px 4px; color: var(--ink); background: var(--bg-tint); border: 1px solid var(--line); }
    .dots { display: inline-flex; gap: 4px; align-items: center; height: 18px; }
    .dots span { width: 6px; height: 6px; border-radius: 100px; background: var(--ink-3); animation: bnc 1.15s ease-in-out infinite; }
    .dots span:nth-child(2) { animation-delay: .14s; }
    .dots span:nth-child(3) { animation-delay: .28s; }
    @keyframes bnc { 0%,60%,100% { transform: translateY(0); opacity: .45 } 30% { transform: translateY(-5px); opacity: 1 } }

    /* ---------- faq --------------------------------------------------------- */
    .faq-row { border-bottom: 1px solid var(--line); }
    .faq-body { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .42s cubic-bezier(.22,1,.36,1); }
    .faq-row.open .faq-body { grid-template-rows: 1fr; }
    .faq-body > div { overflow: hidden; }
    .faq-mark { transition: transform .38s cubic-bezier(.22,1,.36,1), color .3s ease; }
    .faq-row.open .faq-mark { transform: rotate(135deg); color: var(--accent-text); }

    /* ---------- reveal ------------------------------------------------------ */
    .rv { opacity: 0; transform: translateY(22px); transition: opacity .55s cubic-bezier(.16,1,.3,1), transform .55s cubic-bezier(.16,1,.3,1); }
    .rv.in { opacity: 1; transform: none; }
    .rv-group > * { transition-delay: calc(var(--i, 0) * 80ms); }

    .glow-blob { position: absolute; border-radius: 100px; filter: blur(90px); pointer-events: none; z-index: 0; }

    .skip-link { position: fixed; top: -3rem; left: 1rem; z-index: 120; transition: top .2s ease; }
    .skip-link:focus { top: 1rem; }

    @media (prefers-reduced-motion: reduce) {
        html { scroll-behavior: auto; }
        .rv, .word { opacity: 1; transform: none; transition: none; }
        .dots span { animation: none; }
        .btn:hover, .panel-lift:hover, .fnode.on { transform: none; }
        .flink { transition: none; }
    }
</style>
</head>
<body>

<a href="#main" class="skip-link btn btn-primary" data-i18n="common.skip_to_content">{{ __('common.skip_to_content') }}</a>

{{-- ═══════════ HEADER — icon only, no wordmark ═══════════ --}}
<header id="hdr" class="fixed inset-x-0 top-0 z-50">
    <div class="mx-auto flex max-w-[1080px] items-center justify-between gap-4 px-6 py-4">
        <a href="{{ route('welcome') }}" class="flex shrink-0 items-center" aria-label="{{ __('common.brand') }}">
            <img src="{{ asset('images/brand/gen/mark.webp') }}" width="32" height="32" alt="{{ __('common.brand') }}"
                class="h-8 w-8 rounded-lg" fetchpriority="high" decoding="async" />
        </a>

        <nav class="hidden items-center gap-8 lg:flex">
            <a href="#demo" class="nav-link" data-i18n="landing.nav.product">{{ __('landing.nav.product') }}</a>
            <a href="#how" class="nav-link" data-i18n="landing.nav.how">{{ __('landing.nav.how') }}</a>
            <a href="#faq" class="nav-link" data-i18n="landing.nav.faq">{{ __('landing.nav.faq') }}</a>
        </nav>

        <div class="flex items-center gap-2">
            <div class="switcher-wrap hidden sm:block">@include('partials.locale-switcher')</div>

            <button id="theme-toggle" type="button" aria-pressed="false"
                aria-label="{{ __('common.toggle_theme') }}" data-i18n-al="common.toggle_theme"
                class="btn btn-ghost h-9! w-9! p-0!">
                <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
            </button>

            @auth
                <a href="{{ route('home') }}" class="btn btn-primary hidden px-5! py-2.5! sm:inline-flex">
                    <span data-i18n="common.back_home">{{ __('common.back_home') }}</span>
                </a>
            @else
                <a href="{{ route('login') }}" class="nav-link hidden px-2 md:inline-flex" data-i18n="nav.login">{{ __('nav.login') }}</a>
                <a href="{{ route('register') }}" class="btn btn-primary hidden px-5! py-2.5! sm:inline-flex">
                    <span data-i18n="nav.start_free">{{ __('nav.start_free') }}</span>
                </a>
            @endauth

            <button id="burger" type="button" aria-expanded="false" aria-controls="mnav"
                aria-label="{{ __('nav.open_menu') }}" class="btn btn-ghost h-9! w-9! p-0! lg:hidden">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </div>
    </div>

    <div id="mnav" class="mx-auto max-w-[1080px] px-6 lg:hidden">
        <div>
            <div class="panel mb-3 p-4">
                <nav class="flex flex-col">
                    <a href="#demo" data-close class="nav-link py-2.5" data-i18n="landing.nav.product">{{ __('landing.nav.product') }}</a>
                    <a href="#how" data-close class="nav-link py-2.5" data-i18n="landing.nav.how">{{ __('landing.nav.how') }}</a>
                    <a href="#faq" data-close class="nav-link py-2.5" data-i18n="landing.nav.faq">{{ __('landing.nav.faq') }}</a>
                </nav>
                <div class="rule my-3"></div>
                <div class="switcher-wrap mb-3 sm:hidden">@include('partials.locale-switcher')</div>
                @guest
                    <div class="flex flex-col gap-2">
                        <a href="{{ route('login') }}" class="btn btn-ghost w-full" data-i18n="nav.login">{{ __('nav.login') }}</a>
                        <a href="{{ route('register') }}" class="btn btn-primary w-full"><span data-i18n="nav.start_free">{{ __('nav.start_free') }}</span></a>
                    </div>
                @else
                    <a href="{{ route('home') }}" class="btn btn-primary w-full"><span data-i18n="common.back_home">{{ __('common.back_home') }}</span></a>
                @endguest
            </div>
        </div>
    </div>
</header>

<main id="main">

{{-- ═══════════ 1. HERO — centred, word-by-word reveal ═══════════ --}}
<section class="section relative overflow-hidden pt-[168px]! pb-[80px]!">
    <div class="glow-blob" style="width:620px;height:620px;top:-320px;left:50%;transform:translateX(-50%);background:var(--glow);opacity:.55"></div>

    <div class="wrap-narrow relative z-10 text-center">
        {{-- The <h1> carries the finished sentence as its accessible name; the
             visible words are aria-hidden and revealed one at a time. --}}
        <h1 class="t-hero" aria-label="{{ __('landing.hero.title_static') }}" data-i18n-al="landing.hero.title_static">
            <span aria-hidden="true" id="hero-words">
                <span class="word" data-i18n="landing.hero.lead">{{ __('landing.hero.lead') }}</span>
                <span class="word em-serif" data-i18n="landing.hero.emphasis">{{ __('landing.hero.emphasis') }}</span>
                <span class="word" data-i18n="landing.hero.tail">{{ __('landing.hero.tail') }}</span>
            </span>
        </h1>

        <p class="t-sub rv mx-auto mt-7 max-w-[560px]" style="transition-delay:.35s" data-i18n="landing.hero.sub">{{ __('landing.hero.sub') }}</p>

        <div class="rv mt-9 flex flex-wrap items-center justify-center gap-3" style="transition-delay:.45s">
            <a href="{{ route('register') }}" class="btn btn-primary">
                <span data-i18n="landing.hero.cta_primary">{{ __('landing.hero.cta_primary') }}</span>
            </a>
            <a href="#how" class="btn btn-ghost" data-i18n="landing.hero.cta_secondary">{{ __('landing.hero.cta_secondary') }}</a>
        </div>
    </div>
</section>

{{-- ═══════════ 2. PILLARS — headline + one line + three cards ═══════════ --}}
<section class="section pt-0!">
    <div class="wrap">
        <div class="wrap-narrow text-center">
            <h2 class="t-h2 rv" data-i18n="landing.pillars.title">{{ __('landing.pillars.title') }}</h2>
            <p class="t-sub rv mx-auto mt-5 max-w-[600px]" style="transition-delay:.06s" data-i18n="landing.pillars.sub">{{ __('landing.pillars.sub') }}</p>
        </div>

        @php
            $pillarIcons = [
                '<path stroke-linecap="round" stroke-linejoin="round" d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>',
                '<path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 016.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>',
                '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-6 4h6"/>',
            ];
        @endphp

        <div class="rv-group mt-14 grid gap-4 md:grid-cols-3">
            @foreach (__('landing.pillars.items') as $i => $item)
                <div class="panel panel-lift rv p-7" style="--i:{{ $i }}">
                    <span class="icon-plate">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">{!! $pillarIcons[$i] ?? $pillarIcons[0] !!}</svg>
                    </span>
                    <h3 class="t-card mt-5" data-i18n="landing.pillars.items.{{ $i }}.title">{{ $item['title'] }}</h3>
                    <p class="t-small mt-2" data-i18n="landing.pillars.items.{{ $i }}.line">{{ $item['line'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 3. SETUP — eyebrow, headline, CTA, big metric, 3 steps ═══════════ --}}
<section class="section" style="background: var(--bg-tint)">
    <div class="wrap">
        <div class="grid gap-12 lg:grid-cols-[1fr_auto] lg:items-end">
            <div class="max-w-[640px]">
                <span class="eyebrow rv" data-i18n="landing.setup.eyebrow">{{ __('landing.setup.eyebrow') }}</span>
                <h2 class="t-h2 rv mt-4" style="transition-delay:.05s" data-i18n="landing.setup.title">{{ __('landing.setup.title') }}</h2>
                <p class="t-sub rv mt-5" style="transition-delay:.1s" data-i18n="landing.setup.sub">{{ __('landing.setup.sub') }}</p>
                <div class="rv mt-8" style="transition-delay:.15s">
                    <a href="{{ route('register') }}" class="btn btn-primary"><span data-i18n="landing.setup.cta">{{ __('landing.setup.cta') }}</span></a>
                </div>
            </div>

            {{-- The reference pairs the pitch with one oversized live number. --}}
            <div class="rv" style="transition-delay:.2s">
                <p class="numeral" data-counter="{{ __('landing.setup.metric_value') }}" data-suffix="{{ __('landing.setup.metric_suffix') }}">
                    <span>{{ __('landing.setup.metric_value').__('landing.setup.metric_suffix') }}</span>
                </p>
                <p class="t-small mt-3 max-w-[200px]" data-i18n="landing.setup.metric_label">{{ __('landing.setup.metric_label') }}</p>
            </div>
        </div>

        <div class="rv-group mt-16 grid gap-4 md:grid-cols-3">
            @foreach (__('landing.setup.steps') as $i => $step)
                <div class="panel rv p-6" style="--i:{{ $i }}">
                    <span class="fnum">{{ $i + 1 }}</span>
                    <h3 class="t-card mt-4" data-i18n="landing.setup.steps.{{ $i }}.title">{{ $step['title'] }}</h3>
                    <p class="t-small mt-2" data-i18n="landing.setup.steps.{{ $i }}.line">{{ $step['line'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 4. FLOW — the mechanism diagram ═══════════ --}}
<section id="how" class="section">
    <div class="wrap">
        <div class="wrap-narrow text-center">
            <span class="eyebrow rv" data-i18n="landing.flow.eyebrow">{{ __('landing.flow.eyebrow') }}</span>
            <h2 class="t-h2 rv mt-4" style="transition-delay:.05s" data-i18n="landing.flow.title">{{ __('landing.flow.title') }}</h2>
            <p class="t-sub rv mx-auto mt-5 max-w-[620px]" style="transition-delay:.1s" data-i18n="landing.flow.sub">{{ __('landing.flow.sub') }}</p>
        </div>

        @php
            $flowIcons = [
                '<path stroke-linecap="round" stroke-linejoin="round" d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
                '<circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/>',
                '<path stroke-linecap="round" stroke-linejoin="round" d="M4 19.5A2.5 2.5 0 016.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>',
                '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-6 4h6"/>',
            ];
        @endphp

        <div id="flow" class="flow mt-16" role="list">
            @foreach (__('landing.flow.steps') as $i => $step)
                <div class="fnode" role="listitem" data-fnode="{{ $i }}">
                    @if (! $loop->last)
                        <span class="ftrack" aria-hidden="true"></span>
                        <span class="flink" aria-hidden="true"></span>
                    @endif
                    <div class="flex items-center justify-between">
                        <span class="fnum">{{ $i + 1 }}</span>
                        <span class="icon-plate">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">{!! $flowIcons[$i] ?? $flowIcons[0] !!}</svg>
                        </span>
                    </div>
                    <h3 class="t-card mt-5" data-i18n="landing.flow.steps.{{ $i }}.title">{{ $step['title'] }}</h3>
                    <p class="t-small mt-2" data-i18n="landing.flow.steps.{{ $i }}.line">{{ $step['line'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 5. DEMO — the product running ═══════════ --}}
<section id="demo" class="section" style="background: var(--bg-tint)">
    <div class="wrap grid items-center gap-14 lg:grid-cols-[1fr_0.9fr]">
        <div>
            <span class="eyebrow rv" data-i18n="landing.demo.eyebrow">{{ __('landing.demo.eyebrow') }}</span>
            <h2 class="t-h2 rv mt-4" style="transition-delay:.05s" data-i18n="landing.demo.title">{{ __('landing.demo.title') }}</h2>
            <p class="t-sub rv mt-5 max-w-[460px]" style="transition-delay:.1s" data-i18n="landing.demo.sub">{{ __('landing.demo.sub') }}</p>

            <div class="rv mt-8 inline-flex items-center gap-2.5 rounded-full px-4 py-2.5"
                style="transition-delay:.16s; background: color-mix(in srgb, var(--ok) 10%, transparent); color: var(--ok)">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/></svg>
                <span class="text-sm font-medium" data-i18n="landing.demo.chip">{{ __('landing.demo.chip') }}</span>
            </div>
        </div>

        <div class="rv flex justify-center lg:justify-end" style="transition-delay:.12s">
            <div class="device">
                <div class="screen">
                    <div class="flex items-center gap-3 border-b px-4 py-3" style="border-color: var(--line)">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-medium text-white" style="background: linear-gradient(140deg, var(--grad-a), var(--grad-b))">V</span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium" data-i18n="landing.demo.agent_name">{{ __('landing.demo.agent_name') }}</span>
                            <span class="block truncate text-xs" style="color: var(--ink-3)" data-i18n="landing.demo.agent_status">{{ __('landing.demo.agent_status') }}</span>
                        </span>
                    </div>
                    <div id="chat" class="chat" role="log" aria-live="off"></div>
                    <div class="flex items-center gap-2 border-t px-3 py-3" style="border-color: var(--line)">
                        <span class="flex-1 truncate rounded-full px-3.5 py-2 text-xs" style="background: var(--bg-tint); color: var(--ink-3)" data-i18n="landing.demo.input_placeholder">{{ __('landing.demo.input_placeholder') }}</span>
                        <span class="flex h-8 w-8 items-center justify-center rounded-full text-white" style="background: linear-gradient(140deg, var(--grad-a), var(--grad-b))">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12l16-8-6 16-2.5-6L4 12z"/></svg>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 6. NUMBERS — three metrics in a row ═══════════ --}}
<section class="section">
    <div class="wrap">
        <div class="wrap-narrow text-center">
            <h2 class="t-h2 rv" data-i18n="landing.numbers.title">{{ __('landing.numbers.title') }}</h2>
            <p class="t-sub rv mx-auto mt-5 max-w-[560px]" style="transition-delay:.06s" data-i18n="landing.numbers.sub">{{ __('landing.numbers.sub') }}</p>
        </div>

        <div class="rv-group mt-14 grid gap-10 sm:grid-cols-3">
            @foreach (__('landing.numbers.items') as $i => $metric)
                <div class="rv text-center" style="--i:{{ $i }}">
                    <p class="numeral" data-metric-index="{{ $i }}" data-counter="{{ $metric['value'] }}"
                        data-prefix="{{ $metric['prefix'] ?? '' }}" data-suffix="{{ $metric['suffix'] ?? '' }}">
                        <span>{{ ($metric['prefix'] ?? '').$metric['value'].($metric['suffix'] ?? '') }}</span>
                    </p>
                    <p class="t-small mx-auto mt-3 max-w-[180px]" data-i18n="landing.numbers.items.{{ $i }}.label">{{ $metric['label'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 7. TWO PATHS — two blocks, each with a metric + CTA ═══════════ --}}
<section class="section" style="background: var(--bg-tint)">
    <div class="wrap">
        <div class="wrap-narrow text-center">
            <h2 class="t-h2 rv" data-i18n="landing.paths.title">{{ __('landing.paths.title') }}</h2>
            <p class="t-sub rv mx-auto mt-5 max-w-[600px]" style="transition-delay:.06s" data-i18n="landing.paths.sub">{{ __('landing.paths.sub') }}</p>
        </div>

        <div class="rv-group mt-14 grid gap-4 lg:grid-cols-2">
            @foreach (__('landing.paths.items') as $i => $path)
                <article class="panel panel-lift rv flex flex-col p-8 lg:p-10" style="--i:{{ $i }}">
                    <h3 class="t-h2 text-[28px]!" data-i18n="landing.paths.items.{{ $i }}.title">{{ $path['title'] }}</h3>
                    <p class="t-sub mt-4 max-w-[420px]" data-i18n="landing.paths.items.{{ $i }}.line">{{ $path['line'] }}</p>

                    <div class="mt-8 flex items-end justify-between gap-6 border-t pt-6" style="border-color: var(--line)">
                        <div>
                            <p class="text-[28px] font-medium leading-none tracking-tight" data-i18n="landing.paths.items.{{ $i }}.metric">{{ $path['metric'] }}</p>
                            <p class="t-small mt-2" data-i18n="landing.paths.items.{{ $i }}.metric_label">{{ $path['metric_label'] }}</p>
                        </div>
                        <a href="{{ route('register') }}" class="link-arrow">
                            <span data-i18n="landing.paths.items.{{ $i }}.cta">{{ $path['cta'] }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 8. DEEPER — three cards, "for the curious" ═══════════ --}}
<section class="section">
    <div class="wrap">
        <div class="wrap-narrow text-center">
            <h2 class="t-h2 rv" data-i18n="landing.deeper.title">{{ __('landing.deeper.title') }}</h2>
            <p class="t-sub rv mx-auto mt-5 max-w-[560px]" style="transition-delay:.06s" data-i18n="landing.deeper.sub">{{ __('landing.deeper.sub') }}</p>
        </div>

        <div class="rv-group mt-14 grid gap-4 md:grid-cols-3">
            @foreach (__('landing.deeper.items') as $i => $item)
                <div class="panel panel-lift rv p-7" style="--i:{{ $i }}">
                    <h3 class="t-card" data-i18n="landing.deeper.items.{{ $i }}.title">{{ $item['title'] }}</h3>
                    <p class="t-small mt-3" data-i18n="landing.deeper.items.{{ $i }}.line">{{ $item['line'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ═══════════ 9. START — headline + three actions + CTA ═══════════ --}}
<section class="section" style="background: var(--bg-tint)">
    <div class="wrap">
        <div class="wrap-narrow text-center">
            <h2 class="t-h2 rv" data-i18n="landing.start.title">{{ __('landing.start.title') }}</h2>
            <p class="t-sub rv mx-auto mt-5 max-w-[520px]" style="transition-delay:.06s" data-i18n="landing.start.sub">{{ __('landing.start.sub') }}</p>
        </div>

        <div class="rv-group mt-14 grid gap-4 md:grid-cols-3">
            @foreach (__('landing.start.items') as $i => $item)
                <div class="panel rv flex items-start gap-4 p-6" style="--i:{{ $i }}">
                    <span class="fnum shrink-0">{{ $i + 1 }}</span>
                    <span>
                        <span class="t-card block" data-i18n="landing.start.items.{{ $i }}.title">{{ $item['title'] }}</span>
                        <span class="t-small mt-2 block" data-i18n="landing.start.items.{{ $i }}.line">{{ $item['line'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        <div class="rv mt-12 text-center">
            <a href="{{ route('register') }}" class="btn btn-primary px-8! py-4!"><span data-i18n="landing.start.cta">{{ __('landing.start.cta') }}</span></a>
            <p class="t-small mt-4" data-i18n="landing.start.hint">{{ __('landing.start.hint') }}</p>
        </div>
    </div>
</section>

{{-- ═══════════ 10. FAQ ═══════════ --}}
<section id="faq" class="section">
    <div class="wrap-narrow">
        <h2 class="t-h2 rv text-center" data-i18n="landing.faq.title">{{ __('landing.faq.title') }}</h2>

        <div class="rv mt-12">
            @foreach (__('landing.faq.items') as $i => $item)
                <div class="faq-row">
                    <button type="button" class="flex w-full items-start justify-between gap-5 py-5 text-left" aria-expanded="false" aria-controls="faq-{{ $i }}">
                        <span class="t-card" data-i18n="landing.faq.items.{{ $i }}.q">{{ $item['q'] }}</span>
                        <span class="faq-mark shrink-0 text-xl font-light leading-none" style="color: var(--ink-3)" aria-hidden="true">+</span>
                    </button>
                    <div id="faq-{{ $i }}" class="faq-body">
                        <div><p class="t-sub pb-5 pr-8" data-i18n="landing.faq.items.{{ $i }}.a">{{ $item['a'] }}</p></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

</main>

{{-- ═══════════ FOOTER ═══════════ --}}
<footer class="border-t px-6 py-14" style="border-color: var(--line); background: var(--bg-tint)">
    <div class="mx-auto max-w-[1080px]">
        <div class="flex flex-col justify-between gap-10 lg:flex-row">
            <div class="max-w-xs">
                <img src="{{ asset('images/brand/gen/mark.webp') }}" width="28" height="28" alt="{{ __('common.brand') }}" class="h-7 w-7 rounded-lg" loading="lazy" decoding="async" />
                <p class="t-small mt-4" data-i18n="landing.footer.tagline">{{ __('landing.footer.tagline') }}</p>
            </div>
            <div class="flex flex-wrap gap-x-14 gap-y-8">
                <nav class="flex flex-col gap-3">
                    <a href="#demo" class="nav-link" data-i18n="landing.nav.product">{{ __('landing.nav.product') }}</a>
                    <a href="#how" class="nav-link" data-i18n="landing.nav.how">{{ __('landing.nav.how') }}</a>
                    <a href="#faq" class="nav-link" data-i18n="landing.nav.faq">{{ __('landing.nav.faq') }}</a>
                </nav>
                <nav class="flex flex-col gap-3">
                    <a href="{{ route('login') }}" class="nav-link" data-i18n="nav.login">{{ __('nav.login') }}</a>
                    <a href="{{ route('register') }}" class="nav-link" data-i18n="nav.start_free">{{ __('nav.start_free') }}</a>
                    <a href="mailto:{{ __('landing.footer.email') }}" class="nav-link">{{ __('landing.footer.email') }}</a>
                </nav>
            </div>
        </div>
        <div class="rule my-9"></div>
        <div class="flex flex-col-reverse items-center justify-between gap-4 sm:flex-row">
            <p class="t-small" data-i18n="landing.footer.copyright">{{ __('landing.footer.copyright') }}</p>
            <div class="flex gap-6">
                <span class="t-small" data-i18n="landing.footer.privacy">{{ __('landing.footer.privacy') }}</span>
                <span class="t-small" data-i18n="landing.footer.terms">{{ __('landing.footer.terms') }}</span>
            </div>
        </div>
    </div>
</footer>

<noscript>
    <div class="fixed inset-x-0 bottom-0 z-[200] bg-danger px-4 py-3 text-center text-sm font-medium text-white">
        {{ __('common.js_required') }}
    </div>
</noscript>

@include('partials.i18n-bundle', ['i18nNamespaces' => ['landing', 'nav', 'common']])

<script>
(function () {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const i18n = window.__i18n;

    const t = (path) => {
        let node = i18n.messages[i18n.current];
        for (const part of String(path).split('.')) {
            if (node == null || typeof node !== 'object') return null;
            node = node[part];
        }
        return node ?? null;
    };

    /* theme */
    const themeBtn = document.getElementById('theme-toggle');
    themeBtn?.setAttribute('aria-pressed', String(document.documentElement.classList.contains('dark')));
    themeBtn?.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        themeBtn.setAttribute('aria-pressed', String(isDark));
    });

    /* header */
    const hdr = document.getElementById('hdr');
    let ticking = false;
    const onScroll = () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            hdr.classList.toggle('stuck', window.scrollY > 12);
            ticking = false;
        });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* mobile menu */
    const burger = document.getElementById('burger');
    const mnav = document.getElementById('mnav');
    burger?.addEventListener('click', () => {
        const open = mnav.classList.toggle('open');
        burger.setAttribute('aria-expanded', String(open));
        burger.setAttribute('aria-label', open ? t('nav.close_menu') : t('nav.open_menu'));
    });
    mnav?.querySelectorAll('[data-close]').forEach((el) =>
        el.addEventListener('click', () => {
            mnav.classList.remove('open');
            burger?.setAttribute('aria-expanded', 'false');
        })
    );

    /* scroll reveal */
    const rv = document.querySelectorAll('.rv');
    if (reduceMotion) {
        rv.forEach((el) => el.classList.add('in'));
    } else {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('in');
                io.unobserve(entry.target);
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -60px 0px' });
        rv.forEach((el) => io.observe(el));
    }

    /* hero: reveal the headline one word at a time */
    const heroWords = [...document.querySelectorAll('#hero-words .word')];
    const revealWords = () => {
        heroWords.forEach((w, i) => setTimeout(() => w.classList.add('in'), 120 + i * 110));
    };
    if (reduceMotion) {
        heroWords.forEach((w) => w.classList.add('in'));
    } else {
        revealWords();
    }

    /* counters */
    const counterIO = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            counterIO.unobserve(entry.target);
            const el = entry.target;
            const raw = el.dataset.counter || '';
            const inner = el.querySelector('span');
            // Non-numeric values ("24/7") have nothing to count to.
            if (reduceMotion || !/^\d+$/.test(raw)) return;

            const target = Number(raw);
            const prefix = el.dataset.prefix || '';
            const suffix = el.dataset.suffix || '';
            const started = performance.now();
            const step = (now) => {
                const p = Math.min((now - started) / 1100, 1);
                inner.textContent = prefix + Math.round(target * (1 - Math.pow(1 - p, 3))) + suffix;
                if (p < 1) requestAnimationFrame(step);
            };
            inner.textContent = prefix + '0' + suffix;
            requestAnimationFrame(step);
        });
    }, { threshold: 0.5 });
    document.querySelectorAll('[data-counter]').forEach((el) => counterIO.observe(el));

    /* faq */
    document.querySelectorAll('.faq-row').forEach((row) => {
        const btn = row.querySelector('button');
        btn.addEventListener('click', () => {
            const willOpen = !row.classList.contains('open');
            document.querySelectorAll('.faq-row.open').forEach((other) => {
                other.classList.remove('open');
                other.querySelector('button').setAttribute('aria-expanded', 'false');
            });
            row.classList.toggle('open', willOpen);
            btn.setAttribute('aria-expanded', String(willOpen));
        });
    });

    /* flow diagram: a highlight walks the chain, each connector fills behind it */
    (function flow() {
        const root = document.getElementById('flow');
        if (!root) return;
        const nodes = [...root.querySelectorAll('[data-fnode]')];
        if (!nodes.length) return;

        if (reduceMotion) {
            nodes.forEach((n) => n.classList.add('on', 'done'));
            return;
        }

        let timer = null;
        let index = -1;
        const stop = () => { clearTimeout(timer); timer = null; };

        const advance = () => {
            index += 1;
            if (index >= nodes.length) {
                timer = setTimeout(() => {
                    nodes.forEach((n) => n.classList.remove('on', 'done'));
                    index = -1;
                    timer = setTimeout(advance, 700);
                }, 2600);
                return;
            }
            nodes[index].classList.add('on');
            timer = setTimeout(() => {
                nodes[index].classList.add('done');
                timer = setTimeout(advance, 600);
            }, 380);
        };

        new IntersectionObserver((entries) => {
            entries.forEach((e) => (e.isIntersecting ? (timer || advance()) : stop()));
        }, { threshold: 0.3 }).observe(root);
    })();

    /* chat demo — text comes from the bundle so a locale switch restarts it */
    const chat = document.getElementById('chat');
    let chatRun = 0;

    const addBubble = (m) => {
        const el = document.createElement('div');
        el.className = 'bub ' + (m.role === 'user' ? 'bub-u' : 'bub-a');
        el.textContent = m.text;
        chat.appendChild(el);
        requestAnimationFrame(() => el.classList.add('in'));
        chat.scrollTop = chat.scrollHeight;
    };
    const addTyping = () => {
        const el = document.createElement('div');
        el.className = 'bub bub-a in';
        el.innerHTML = '<span class="dots"><span></span><span></span><span></span></span>';
        chat.appendChild(el);
        chat.scrollTop = chat.scrollHeight;
        return el;
    };
    const wait = (ms, run) =>
        new Promise((res, rej) => setTimeout(() => (run === chatRun ? res() : rej(new Error('stale'))), ms));

    async function playChat() {
        const run = ++chatRun;
        try {
            for (;;) {
                chat.innerHTML = '';
                for (const m of t('landing.demo.messages') || []) {
                    await wait(m.role === 'user' ? 850 : 450, run);
                    if (m.role === 'agent') {
                        const d = addTyping();
                        await wait(1100, run);
                        d.remove();
                    }
                    addBubble(m);
                }
                await wait(4800, run);
            }
        } catch (e) { /* superseded by a newer run */ }
    }

    if (chat) {
        if (reduceMotion) {
            (t('landing.demo.messages') || []).forEach(addBubble);
        } else {
            new IntersectionObserver((entries, obs) => {
                entries.forEach((e) => {
                    if (!e.isIntersecting) return;
                    obs.unobserve(e.target);
                    playChat();
                });
            }, { threshold: 0.3 }).observe(chat);
        }
    }

    /* locale switch: catch the content that lives in JS, not in [data-i18n] */
    const baseSwitch = window.switchLocale;
    window.switchLocale = (locale) => {
        const changed = baseSwitch(locale);
        if (!changed) return changed;

        document.title = t('landing.meta.title') || document.title;
        document.querySelector('meta[name="description"]')?.setAttribute('content', t('landing.meta.description') || '');

        // Metric units are localised even though the digits are not.
        document.querySelectorAll('[data-metric-index]').forEach((el) => {
            const item = t('landing.numbers.items.' + el.dataset.metricIndex);
            if (!item) return;
            el.dataset.prefix = item.prefix || '';
            el.dataset.suffix = item.suffix || '';
            const inner = el.querySelector('span');
            if (inner) inner.textContent = (item.prefix || '') + item.value + (item.suffix || '');
        });

        if (chat && !reduceMotion) playChat();

        // Re-run the word reveal so the new headline animates in too.
        if (!reduceMotion) {
            heroWords.forEach((w) => w.classList.remove('in'));
            revealWords();
        }

        return changed;
    };
})();
</script>

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
    }
</script>
</body>
</html>
