<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<meta name="theme-url" content="{{ route('settings.theme') }}" />
<script>
    // Runs before first paint. The account's saved theme wins when there is one
    // (so the choice follows the owner between devices); localStorage is the
    // local cache that keeps this synchronous, and the OS setting is the
    // fallback that "system" deliberately falls back to.
    //
    // A null column means "no account-level override" — which is also what a
    // brand-new account has. It must NOT clear the cached choice: the theme
    // toggle lives on the login and sign-up pages too, where a guest has no
    // account to write to and localStorage is the only record of what they
    // picked. Wiping it here is what made a light-theme owner land on dark
    // (or the reverse) on the first page after signing in. Picking "system"
    // in settings clears the cache at the point of choosing instead.
    (function () {
        var saved = @json(auth()->user()?->theme);
        if (saved) {
            localStorage.setItem('theme', saved);
        }
        var stored = saved || localStorage.getItem('theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var isDark = stored ? stored === 'dark' : prefersDark;
        document.documentElement.classList.toggle('dark', isDark);

        // Same trick for the sidebar: Alpine boots after first paint, so without
        // this a collapsed rail would flash open on every navigation. The class
        // sizes the rail in CSS until Alpine takes the state over, and Alpine
        // reads its initial value back out of it.
        if (localStorage.getItem('sidebar:collapsed') === '1') {
            document.documentElement.classList.add('rail-collapsed');
        }
    })();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1" />
{{-- See layouts/auth: stops mobile browsers force-darkening the palette. --}}
<meta name="color-scheme" content="light dark" />
{{-- Opts every Turbo navigation into the native View Transitions API where the
     browser has one (Chrome/Edge, and Safari from 18) — Turbo wraps its page
     swap in document.startViewTransition() automatically once this is present.
     Browsers without it (Firefox, as of this writing) simply ignore the tag and
     fall back to the .page-enter CSS transition in app.css, which is scoped to
     apply only where this API is absent. --}}
<meta name="view-transition" content="same-origin" />
<title>@yield('title', __('common.brand'))</title>
@include('partials.favicons')
{{-- Self-hosted Golos Text. Previously the dashboard linked no webfont at all,
     so it silently rendered in the system UI face while auth and the landing
     used a downloaded one — the app looked like two different products. --}}
@include('partials.fonts')
@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('head')
</head>
<body class="page-enter min-h-screen bg-paper text-ink antialiased transition-colors duration-300 dark:bg-night dark:text-night-ink"
    {{-- The inbox asks for the rail when a conversation is open, so the
         transcript gets the horizontal space instead of the nav labels.
         Closing it returns to the owner's OWN preference rather than forcing
         it open — otherwise the inbox would quietly undo their choice.
         Bound on <body> rather than on the sidebar itself: <body> is the one
         element Turbo never recreates (it only ever replaces what's inside
         it), so this listener needs attaching exactly once regardless of how
         the permanent/non-permanent elements around it come and go. --}}
    @inbox-focus.window="$store.shell.railCollapsed = $event.detail.collapsed || $store.shell.railPinned">

<div id="toast-container" class="pointer-events-none fixed left-1/2 top-4 z-[100] flex w-[calc(100%_-_2rem)] max-w-sm -translate-x-1/2 flex-col px-2" role="status" aria-live="polite" aria-atomic="true">
    @if (session('status'))
        <div data-flash-toast class="toast mt-2 rounded-2xl bg-ink px-4 py-3 text-sm font-medium text-white shadow-xl dark:bg-night-ink dark:text-night">{{ session('status') }}</div>
    @endif
</div>

{{-- Raised off the account itself rather than off a redirect flash, so it
     reaches a Google sign-up too — that path never touches register(). The
     partial stamps the account as welcomed while rendering; see its header. --}}
@if (auth()->user()->awaitsWelcome())
    @include('partials.welcome-modal')
@endif

<div class="flex min-h-screen">
    {{-- Sidebar. `data-turbo-permanent` + a stable id tells Turbo to carry
         this exact DOM node across every navigation instead of discarding it
         with the rest of the old page — the sidebar never flashes, never
         re-animates its collapse transition, and its Alpine-bound state
         (via the `shell` store, not a local x-data) never gets torn down and
         re-initialized. See resources/js/app.js for why that matters: this
         is also what keeps the lead-bell's poll loop to exactly one. --}}
    <aside id="app-sidebar" data-turbo-permanent data-sidebar :class="[
            $store.shell.mobileNavOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            $store.shell.railCollapsed ? 'lg:w-[4.75rem]' : 'lg:w-64',
        ]"
        {{-- Flat, and deliberately white rather than translucent: the re-skin's
             surfaces separate by fill, not by stroke, so the rail is simply the
             surface colour against the grey canvas the content sits on and the
             old right-hand hairline is gone with every other border. --}}
        class="fixed inset-y-0 left-0 z-40 flex h-screen w-64 flex-col bg-surface transition-[transform,width] duration-300 ease-out dark:bg-night-card lg:sticky lg:top-0 lg:translate-x-0">
        {{-- Icon only, no wordmark — see the landing header for the rationale. --}}
        <div class="flex h-16 shrink-0 items-center px-5" :class="$store.shell.railCollapsed && 'lg:justify-center lg:px-0'">
            <a href="{{ route('agents.index') }}" aria-label="{{ __('common.brand') }}" class="flex items-center">
                <img src="{{ asset('images/brand/gen/mark.webp') }}?v={{ config('agent.brand_mark_version') }}" width="32" height="32"
                    alt="{{ __('common.brand') }}" class="h-8 w-8" decoding="async" />
            </a>
            {{-- No room for the bell beside the mark at rail width; the Заявки
                 nav item keeps its own unread dot, so nothing is lost. --}}
            <div class="ml-auto" :class="$store.shell.railCollapsed && 'lg:hidden'">
                @include('partials.lead-bell')
            </div>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2">
            {{-- Labels are hidden with a class rather than x-show so they stay in
                 the DOM for the instant locale swap, which walks [data-i18n].

                 aria-label, not title: the collapsed rail hides the label text,
                 so each item still needs an accessible name — but `title` also
                 renders a native tooltip, which in the expanded rail just
                 repeated the label sitting right next to the cursor. aria-label
                 gives screen readers the name with nothing shown on hover, in
                 either state. It carries data-i18n-al so the instant locale
                 swap updates it too. --}}
            @can('analytics.view')
            <a href="{{ route('dashboard') }}" aria-label="{{ __('agents.nav.dashboard') }}" data-i18n-al="agents.nav.dashboard"
                data-nav="dashboard"
                @if (request()->routeIs('dashboard')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('dashboard') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>
                <span data-i18n="agents.nav.dashboard" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.dashboard') }}</span>
            </a>
            @endcan

            @can('agents.view')
            <a href="{{ route('agents.index') }}" aria-label="{{ __('agents.nav.agents') }}" data-i18n-al="agents.nav.agents"
                data-nav="agents"
                @if (request()->routeIs('agents.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('agents.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 0c-4.4 0-8 2.24-8 5v2h16v-2c0-2.76-3.6-5-8-5z"/></svg>
                <span data-i18n="agents.nav.agents" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.agents') }}</span>
            </a>
            @endcan

            @can('conversations.view')
            <a href="{{ route('conversations.index') }}" aria-label="{{ __('agents.nav.conversations') }}" data-i18n-al="agents.nav.conversations"
                data-nav="conversations"
                @if (request()->routeIs('conversations.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('conversations.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <span data-i18n="agents.nav.conversations" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.conversations') }}</span>
            </a>
            @endcan

            {{-- CRM sits between the inbox and Заявки on purpose: it is what
                 the conversations on the left turn INTO, and it is the section
                 the owner works in rather than reads. Kept distinct from
                 Аналитика at the top, which stays a pure report. Its own
                 crm.view, not analytics.view — Stage B's whole point is that
                 CRM access is grantable without BI riding along. --}}
            @can('crm.view')
            <a href="{{ route('crm.board') }}" aria-label="{{ __('agents.nav.crm') }}" data-i18n-al="agents.nav.crm"
                data-nav="crm"
                @if (request()->routeIs('crm.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('crm.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5h5v14H4zM10 5h5v9h-5zM16 5h4v6h-4z"/></svg>
                <span data-i18n="agents.nav.crm" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.crm') }}</span>
            </a>
            @endcan

            @can('projects.view')
            <a href="{{ route('projects.index') }}" aria-label="{{ __('agents.nav.projects') }}" data-i18n-al="agents.nav.projects"
                data-nav="projects"
                @if (request()->routeIs('projects.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('projects.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M9 4v16M13 9h5M13 13h5M5.5 9h1M5.5 13h1"/></svg>
                <span data-i18n="agents.nav.projects" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.projects') }}</span>
            </a>
            @endcan

            {{-- Данные sits after CRM and before Заявки: the analytics page at the
                 top reports on the ASSISTANTS, this section answers questions about
                 whatever the owner uploads. Both are reading rather than working,
                 so they bracket the two sections where work happens. --}}
            @can('analytics.view')
            <a href="{{ route('bi.dashboards.index') }}" aria-label="{{ __('agents.nav.bi') }}" data-i18n-al="agents.nav.bi"
                data-nav="bi"
                @if (request()->routeIs('bi.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('bi.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7c0-1.657 3.582-3 8-3s8 1.343 8 3-3.582 3-8 3-8-1.343-8-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5c0 1.657-3.582 3-8 3s-8-1.343-8-3V7M20 12v5c0 1.657-3.582 3-8 3s-8-1.343-8-3v-5"/></svg>
                <span data-i18n="agents.nav.bi" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.bi') }}</span>
            </a>
            @endcan

            {{-- Заявки feed straight into a deal (deals.lead_id) and are
                 worked the same way a deal is — approve/decline a price,
                 change status, delete — so this is crm.view/crm.manage
                 territory, not analytics.*. See LeadPolicy's own docblock. --}}
            @can('crm.view')
            <a href="{{ route('leads.index') }}" aria-label="{{ __('agents.nav.leads') }}" data-i18n-al="agents.nav.leads"
                data-nav="leads"
                @if (request()->routeIs('leads.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item relative {{ request()->routeIs('leads.*') ? 'nav-item-active' : '' }}">
                <span class="relative shrink-0">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    {{-- At rail width the pill has nowhere to sit, so the count
                         degrades to a dot on the icon rather than vanishing. --}}
                    <span x-show="$store.shell.count > 0 && $store.shell.railCollapsed" x-cloak
                        class="absolute -right-1 -top-0.5 hidden h-2.5 w-2.5 rounded-full bg-brand ring-2 ring-white dark:ring-night lg:block"></span>
                </span>
                <span data-i18n="agents.nav.leads" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.leads') }}</span>
                <span x-show="$store.shell.count > 0" x-cloak x-text="$store.shell.count > 99 ? '99+' : $store.shell.count"
                    :class="$store.shell.railCollapsed && 'lg:hidden'"
                    class="nav-unread-count ml-auto" aria-live="polite"></span>
            </a>
            @endcan

            <a href="{{ route('teams.index') }}" aria-label="{{ __('agents.nav.team_section') }}" data-i18n-al="agents.nav.team_section"
                data-nav="teams"
                @if (request()->routeIs('teams.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('teams.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 19v-2a6 6 0 0 1 12 0v2H3zM17 6a3 3 0 0 1 0 6m1 2a5 5 0 0 1 3 5"/></svg>
                <span data-i18n="agents.nav.team_section" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.team_section') }}</span>
            </a>

            <a href="{{ route('settings.index') }}" aria-label="{{ __('agents.nav.settings') }}" data-i18n-al="agents.nav.settings"
                data-nav="settings"
                @if (request()->routeIs('settings.*')) aria-current="page" @endif
                :class="$store.shell.railCollapsed && 'lg:justify-center'"
                class="nav-item {{ request()->routeIs('settings.*') ? 'nav-item-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span data-i18n="agents.nav.settings" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.settings') }}</span>
            </a>
        </nav>

        <div class="rule shrink-0 p-3">
            {{-- Stage 3a: which team the rest of the shell (CRM, BI, agents)
                 currently reads/writes against — above the user's own
                 identity row, not merged into it, since "who I am" and
                 "which team I'm looking at" are two different questions
                 once an account has more than one team. Hidden at rail
                 width rather than given its own icon-only state: a second
                 affordance squeezed next to the avatar would be harder to
                 read than simply not showing it collapsed, same trade-off
                 the language menu below already makes. --}}
            <div class="mb-1" :class="$store.shell.railCollapsed && 'lg:hidden'">
                @include('partials.team-switcher')
            </div>
            <div class="flex items-center gap-2 rounded-xl px-2 py-2" :class="$store.shell.railCollapsed && 'lg:flex-col lg:gap-1 lg:px-0'">
                <a href="{{ route('settings.index') }}" aria-label="{{ auth()->user()->name }}"
                    :class="$store.shell.railCollapsed && 'lg:flex-none'"
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-lg transition-opacity hover:opacity-80">
                    <div class="icon-circle icon-circle-sm shrink-0 text-xs font-semibold">
                        {{ auth()->user()->initials() }}
                    </div>
                    <div class="min-w-0 flex-1" :class="$store.shell.railCollapsed && 'lg:hidden'">
                        <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-muted dark:text-night-muted">{{ auth()->user()->email }}</p>
                    </div>
                </a>
                {{-- The language menu opens sideways and has no room at rail
                     width; it also lives in Settings → Preferences now. --}}
                <div :class="$store.shell.railCollapsed && 'lg:hidden'" class="contents">
                    @include('partials.dashboard-locale-switcher')
                </div>
                <button id="theme-toggle" type="button" aria-label="{{ __('agents.nav.toggle_theme') }}" data-i18n-al="agents.nav.toggle_theme"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted transition-colors hover:bg-sunk hover:text-ink dark:text-night-muted dark:hover:bg-night-raised dark:hover:text-night-ink">
                    <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                    <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                </button>

                {{-- Collapse/expand the rail. Desktop only: below lg the sidebar
                     is a drawer that opens and closes instead. Sits in the
                     footer so it lands correctly in both states — the footer
                     already stacks vertically when collapsed. --}}
                <button type="button" @click="$store.shell.toggleRail()"
                    :aria-expanded="(! $store.shell.railCollapsed).toString()"
                    :aria-label="$store.shell.railCollapsed ? @js(__('agents.nav.expand_sidebar')) : @js(__('agents.nav.collapse_sidebar'))"
                    :title="$store.shell.railCollapsed ? @js(__('agents.nav.expand_sidebar')) : @js(__('agents.nav.collapse_sidebar'))"
                    class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted transition-colors hover:bg-sunk hover:text-ink dark:text-night-muted dark:hover:bg-night-raised dark:hover:text-night-ink lg:flex">
                    <svg class="h-4 w-4 transition-transform duration-300" :class="$store.shell.railCollapsed && 'rotate-180'"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
            </div>
            {{-- data-turbo="false": logging out must be a true full reload, not
                 a Turbo-managed visit — it tears down the session, and every bit
                 of client state (the shell store, its poll loop, any open modal)
                 needs to go with it rather than survive into whatever renders
                 next. --}}
            <form method="POST" action="{{ route('logout') }}" data-turbo="false" class="mt-1">
                @csrf
                <button type="submit" aria-label="{{ __('agents.nav.logout') }}" data-i18n-al="agents.nav.logout"
                    :class="$store.shell.railCollapsed && 'lg:justify-center'"
                    class="nav-item w-full">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    <span data-i18n="agents.nav.logout" :class="$store.shell.railCollapsed && 'lg:hidden'">{{ __('agents.nav.logout') }}</span>
                </button>
            </form>
        </div>
    </aside>

    <div x-show="$store.shell.mobileNavOpen" x-cloak @click="$store.shell.mobileNavOpen = false" class="modal-scrim fixed inset-0 z-30 lg:hidden"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Also `data-turbo-permanent`: it shares the sidebar's `mobileNavOpen`
             and bell state through the `shell` store, not through a wrapping
             x-data, so nothing requires it to persist for correctness — but
             persisting it anyway avoids an identical bar flashing/re-rendering
             on every navigation on mobile. --}}
        <header id="app-mobile-header" data-turbo-permanent class="flex h-16 items-center gap-3 bg-surface px-4 dark:bg-night-card lg:hidden">
            <button @click="$store.shell.mobileNavOpen = true" type="button" aria-label="{{ __('agents.nav.open_menu') }}" data-i18n-al="agents.nav.open_menu" class="flex h-10 w-10 items-center justify-center rounded-lg text-ink dark:text-night-ink">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <span data-i18n="common.brand" class="text-sm font-semibold">{{ __('common.brand') }}</span>
            <div class="ml-auto">
                @include('partials.lead-bell')
            </div>
        </header>

        {{-- THE CONTENT COLUMN, and the single biggest reason the last pass
             read as empty: every page set its own `max-w-6xl` (1152px) inside
             this, so on a 1920px screen roughly 600px to the right of the
             content was blank canvas — the dashboard looked like a phone
             layout that had been stretched.

             The cap lives here now, once, at 1600px: wide enough that a
             five-across KPI row and a two-thirds chart have real room, narrow
             enough that a line of body copy never runs an unreadable measure
             (individual blocks still set their own `measure` where it matters).
             Pages no longer set a width of their own.

             Deliberately NOT `data-turbo-permanent`: this is the one element
             every navigation exists to replace. --}}
        <main data-app-main class="mx-auto w-full max-w-[100rem] flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
            @yield('content')
        </main>
    </div>
</div>

{{-- Pages that add their own namespace (the inbox, settings) pass
     $i18nExtraNamespaces; the chrome's own namespaces are always present. --}}
@include('partials.i18n-bundle', [
    'i18nNamespaces' => array_merge(['nav', 'common', 'agents', 'onboarding'], $i18nExtraNamespaces ?? []),
    'i18nReload' => true,
])

<script>
    // The shell store's one-time config (see resources/js/app.js — the
    // `shell` Alpine store, read and consumed on the first `turbo:load`).
    // A plain global rather than a direct call into the store: this script
    // runs synchronously during HTML parsing, before the deferred module
    // script that defines `window.Alpine` and registers the store has had a
    // chance to execute, so there is nothing yet to call here.
    window.__shellConfig = {
        pollUrl: @js(auth()->user()->can('crm.view') ? route('leads.poll') : ''),
        seenUrl: '{{ route('leads.seen') }}',
        leadsUrl: '{{ route('leads.index') }}',
        needsHumanUrl: '{{ route('conversations.index', ['status' => 'needs_human']) }}',
        labels: {{ Js::from([
            'needsHuman' => __('conversations.filters.needs_human'),
            'signals' => __('conversations.escalation.signals'),
        ]) }},
    };
</script>
@php
    /**
     * The arrival greeting, spent here — by the request that renders it.
     *
     * By the time this runs the page content has already rendered, so where the
     * onboarding checklist is on the page it has claimed the sign-up greeting
     * for its banner and nothing is left here — one greeting, not two. The
     * toast is what greets everywhere else: every sign-in, and the sign-up
     * pages that carry no checklist.
     *
     * The expectsJson() guard is the load-bearing part. The auth forms POST
     * with fetch(), and fetch follows the redirect chain itself: the dashboard
     * is therefore rendered ONCE for the fetch (whose HTML is discarded) and
     * again for the navigation that follows. Both requests reach this line, and
     * only the second one is a page anybody sees — so the fetch's render, which
     * carries the fetch's `Accept: application/json`, must leave the greeting
     * untouched for the real one.
     */
    $__greeting = request()->expectsJson() ? null : session(\App\Http\Controllers\AuthController::GREETING_SESSION_KEY);

    if ($__greeting) {
        session()->forget(\App\Http\Controllers\AuthController::GREETING_SESSION_KEY);
    }

    $__greetingKey = match ($__greeting) {
        \App\Http\Controllers\AuthController::GREETING_WELCOME => 'onboarding.welcome_greeting',
        \App\Http\Controllers\AuthController::GREETING_BACK => 'onboarding.back',
        default => null,
    };
@endphp
@if ($__greetingKey)
    {{-- A greeting, not an announcement: it reuses the existing toast, which
         already fades itself out after four seconds. On a fresh sign-up it sits
         alongside the welcome modal.

         Deferred to DOMContentLoaded because app.js (which defines showToast)
         is a module and therefore runs after this inline script is parsed. --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Just behind the page's own fade-in, so it reads as arriving
            // rather than as already having been there.
            {{-- JSON_UNESCAPED_UNICODE because the default would emit the
                 Russian and Tajik greetings as \uXXXX escapes — correct, but
                 unreadable in view source and in the page's own tests. --}}
            setTimeout(() => window.showToast?.(@json(__($__greetingKey, ['name' => auth()->user()->name]), JSON_UNESCAPED_UNICODE), 'info'), 350);
        });
    </script>
@endif
@stack('scripts')
</body>
</html>
