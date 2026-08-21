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
    (function () {
        var saved = @json(auth()->user()?->theme);
        if (saved) {
            localStorage.setItem('theme', saved);
        } else if (@json((bool) auth()->check())) {
            localStorage.removeItem('theme');
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
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>@yield('title', __('common.brand'))</title>
@include('partials.favicons')
{{-- Self-hosted Inter. Previously the dashboard linked no webfont at all, so it
     silently rendered in the system UI face while auth and the landing used a
     downloaded one — the app looked like two different products. --}}
@include('partials.fonts')
@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('head')
</head>
<body class="page-enter min-h-screen bg-paper text-ink antialiased transition-colors duration-300 dark:bg-night dark:text-night-ink">

<div id="toast-container" class="pointer-events-none fixed left-1/2 top-4 z-[100] flex w-[calc(100%_-_2rem)] max-w-sm -translate-x-1/2 flex-col px-2" role="status" aria-live="polite" aria-atomic="true"></div>

{{-- Raised off the account itself rather than off a redirect flash, so it
     reaches a Google sign-up too — that path never touches register(). The
     partial stamps the account as welcomed while rendering; see its header. --}}
@if (auth()->user()->awaitsWelcome())
    @include('partials.welcome-modal')
@endif

<div class="flex min-h-screen" x-data="dashboardShell({
        pollUrl: '{{ route('leads.poll') }}',
        seenUrl: '{{ route('leads.seen') }}',
        leadsUrl: '{{ route('leads.index') }}',
        needsHumanUrl: '{{ route('conversations.index', ['status' => 'needs_human']) }}',
        labels: {{ Js::from([
            'needsHuman' => __('conversations.filters.needs_human'),
            'signals' => __('conversations.escalation.signals'),
        ]) }},
    })" x-init="initBell(); initRail()"
    {{-- The inbox asks for the rail when a conversation is open, so the
         transcript gets the horizontal space instead of the nav labels. Closing
         it returns to the owner's OWN preference rather than forcing it open —
         otherwise the inbox would quietly undo their choice. --}}
    @inbox-focus.window="railCollapsed = $event.detail.collapsed || railPinned">
    {{-- Sidebar --}}
    <aside data-sidebar :class="[
            mobileNavOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            railCollapsed ? 'lg:w-[4.75rem]' : 'lg:w-64',
        ]"
        class="fixed inset-y-0 left-0 z-40 flex h-screen w-64 flex-col border-r border-black/10 bg-white/80 backdrop-blur-xl transition-[transform,width] duration-300 ease-out dark:border-white/10 dark:bg-night/95 lg:sticky lg:top-0 lg:translate-x-0">
        {{-- Icon only, no wordmark — see the landing header for the rationale. --}}
        <div class="flex h-16 shrink-0 items-center px-5" :class="railCollapsed && 'lg:justify-center lg:px-0'">
            <a href="{{ route('agents.index') }}" aria-label="{{ __('common.brand') }}" class="flex items-center">
                <img src="{{ asset('images/brand/gen/mark.webp') }}" width="32" height="32"
                    alt="{{ __('common.brand') }}" class="h-8 w-8" decoding="async" />
            </a>
            {{-- No room for the bell beside the mark at rail width; the Заявки
                 nav item keeps its own unread dot, so nothing is lost. --}}
            <div class="ml-auto" :class="railCollapsed && 'lg:hidden'">
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
            <a href="{{ route('agents.index') }}" aria-label="{{ __('agents.nav.agents') }}" data-i18n-al="agents.nav.agents"
                :class="railCollapsed && 'lg:justify-center'"
                class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors {{ request()->routeIs('agents.*') ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 0c-4.4 0-8 2.24-8 5v2h16v-2c0-2.76-3.6-5-8-5z"/></svg>
                <span data-i18n="agents.nav.agents" :class="railCollapsed && 'lg:hidden'">{{ __('agents.nav.agents') }}</span>
            </a>

            <a href="{{ route('conversations.index') }}" aria-label="{{ __('agents.nav.conversations') }}" data-i18n-al="agents.nav.conversations"
                :class="railCollapsed && 'lg:justify-center'"
                class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors {{ request()->routeIs('conversations.*') ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <span data-i18n="agents.nav.conversations" :class="railCollapsed && 'lg:hidden'">{{ __('agents.nav.conversations') }}</span>
            </a>

            <a href="{{ route('leads.index') }}" aria-label="{{ __('agents.nav.leads') }}" data-i18n-al="agents.nav.leads"
                :class="railCollapsed && 'lg:justify-center'"
                class="relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors {{ request()->routeIs('leads.*') ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5' }}">
                <span class="relative shrink-0">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                    {{-- At rail width the pill has nowhere to sit, so the count
                         degrades to a dot on the icon rather than vanishing. --}}
                    <span x-show="count > 0 && railCollapsed" x-cloak
                        class="absolute -right-1 -top-0.5 hidden h-2.5 w-2.5 rounded-full bg-brand ring-2 ring-white dark:ring-night lg:block"></span>
                </span>
                <span data-i18n="agents.nav.leads" :class="railCollapsed && 'lg:hidden'">{{ __('agents.nav.leads') }}</span>
                <span x-show="count > 0" x-cloak x-text="count > 99 ? '99+' : count"
                    :class="railCollapsed && 'lg:hidden'"
                    class="ml-auto min-w-[20px] rounded-full bg-brand px-1.5 py-0.5 text-center text-[10px] font-bold text-on-accent"></span>
            </a>

            <a href="{{ route('settings.index') }}" aria-label="{{ __('agents.nav.settings') }}" data-i18n-al="agents.nav.settings"
                :class="railCollapsed && 'lg:justify-center'"
                class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors {{ request()->routeIs('settings.*') ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span data-i18n="agents.nav.settings" :class="railCollapsed && 'lg:hidden'">{{ __('agents.nav.settings') }}</span>
            </a>
        </nav>

        <div class="shrink-0 border-t border-black/10 p-3 dark:border-white/10">
            <div class="flex items-center gap-2 rounded-xl px-2 py-2" :class="railCollapsed && 'lg:flex-col lg:gap-1 lg:px-0'">
                <a href="{{ route('settings.index') }}" aria-label="{{ auth()->user()->name }}"
                    :class="railCollapsed && 'lg:flex-none'"
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-lg transition-opacity hover:opacity-80">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-xs font-semibold text-brand">
                        {{ auth()->user()->initials() }}
                    </div>
                    <div class="min-w-0 flex-1" :class="railCollapsed && 'lg:hidden'">
                        <p class="truncate text-sm font-medium">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-muted dark:text-night-muted">{{ auth()->user()->email }}</p>
                    </div>
                </a>
                {{-- The language menu opens sideways and has no room at rail
                     width; it also lives in Settings → Preferences now. --}}
                <div :class="railCollapsed && 'lg:hidden'" class="contents">
                    @include('partials.dashboard-locale-switcher')
                </div>
                <button id="theme-toggle" type="button" aria-label="{{ __('agents.nav.toggle_theme') }}" data-i18n-al="agents.nav.toggle_theme"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted transition-colors hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/10">
                    <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                    <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                </button>

                {{-- Collapse/expand the rail. Desktop only: below lg the sidebar
                     is a drawer that opens and closes instead. Sits in the
                     footer so it lands correctly in both states — the footer
                     already stacks vertically when collapsed. --}}
                <button type="button" @click="toggleRail()"
                    :aria-expanded="(! railCollapsed).toString()"
                    :aria-label="railCollapsed ? @js(__('agents.nav.expand_sidebar')) : @js(__('agents.nav.collapse_sidebar'))"
                    :title="railCollapsed ? @js(__('agents.nav.expand_sidebar')) : @js(__('agents.nav.collapse_sidebar'))"
                    class="hidden h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted transition-colors hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/10 lg:flex">
                    <svg class="h-4 w-4 transition-transform duration-300" :class="railCollapsed && 'rotate-180'"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <button type="submit" aria-label="{{ __('agents.nav.logout') }}" data-i18n-al="agents.nav.logout"
                    :class="railCollapsed && 'lg:justify-center'"
                    class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-sm font-medium text-muted transition-colors hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    <span data-i18n="agents.nav.logout" :class="railCollapsed && 'lg:hidden'">{{ __('agents.nav.logout') }}</span>
                </button>
            </form>
        </div>
    </aside>

    <div x-show="mobileNavOpen" x-cloak @click="mobileNavOpen = false" class="fixed inset-0 z-30 bg-black/30 lg:hidden"></div>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center gap-3 border-b border-black/10 bg-white/70 px-4 backdrop-blur-xl dark:border-white/10 dark:bg-night/80 lg:hidden">
            <button @click="mobileNavOpen = true" type="button" aria-label="{{ __('agents.nav.open_menu') }}" data-i18n-al="agents.nav.open_menu" class="flex h-9 w-9 items-center justify-center rounded-lg text-ink dark:text-night-ink">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <span data-i18n="common.brand" class="text-sm font-semibold">{{ __('common.brand') }}</span>
            <div class="ml-auto">
                @include('partials.lead-bell')
            </div>
        </header>

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
            @if (session('status') && ! ($statusAsToast ?? false))
                <div class="reveal is-visible mb-6 rounded-2xl border border-brand/20 bg-brand/5 px-4 py-3 text-sm font-medium text-brand" role="status">
                    {{ session('status') }}
                </div>
            @endif

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
    // Shared shell state for the dashboard chrome: the mobile nav toggle plus the
    // in-app lead notification bell (badge count + recent list), which polls a
    // lightweight JSON endpoint so new leads surface without a page reload.
    window.dashboardShell = function dashboardShell({ pollUrl, seenUrl, leadsUrl, needsHumanUrl, labels }) {
        return {
            mobileNavOpen: false,
            // The rail has two inputs: the owner's own toggle (railPinned,
            // persisted) and the inbox asking for space while a conversation is
            // open. railCollapsed is the effective state of the two.
            // Desktop only — on mobile the sidebar is an off-canvas drawer.
            railCollapsed: false,
            railPinned: false,
            bellOpen: false,
            count: 0,
            recent: [],
            escalations: [],
            leadsUrl,
            needsHumanUrl,
            labels,
            /**
             * The head script has already applied the saved state as a class to
             * avoid a flash, so read it back from there rather than from
             * localStorage again — one source of truth, no chance of the two
             * disagreeing on the first frame.
             */
            initRail() {
                this.railPinned = document.documentElement.classList.contains('rail-collapsed');
                this.railCollapsed = this.railPinned;
            },

            toggleRail() {
                this.railPinned = ! this.railPinned;
                // A manual toggle wins immediately, even over the inbox: the
                // owner just said what they want.
                this.railCollapsed = this.railPinned;
                document.documentElement.classList.toggle('rail-collapsed', this.railPinned);

                try {
                    localStorage.setItem('sidebar:collapsed', this.railPinned ? '1' : '0');
                } catch (e) { /* private mode — the choice just won't outlive the tab */ }
            },

            initBell() {
                this.poll();
                setInterval(() => this.poll(), 25000);
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') this.poll();
                });
            },
            async poll() {
                try {
                    const res = await fetch(pollUrl, { headers: { Accept: 'application/json' } });
                    if (!res.ok) return;
                    const data = await res.json();
                    this.count = data.count ?? 0;
                    this.recent = data.recent ?? [];
                    this.escalations = data.escalations ?? [];
                } catch (e) { /* transient — retried on the next tick */ }
            },
            toggleBell() {
                this.bellOpen = !this.bellOpen;
            },
            async markSeen() {
                try {
                    const res = await fetch(seenUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                    });
                    if (res.ok) {
                        this.count = 0;
                        this.recent = this.recent.map((lead) => ({ ...lead, seen: true }));
                    }
                } catch (e) { /* ignore */ }
            },
        };
    };
</script>
@if (session('welcome_back'))
    {{-- A greeting, not an announcement: it reuses the existing toast, which
         already fades itself out after four seconds. Set only when signing in
         to an EXISTING account — a fresh sign-up gets the welcome modal
         instead, so the two never both appear.

         Deferred to DOMContentLoaded because app.js (which defines showToast)
         is a module and therefore runs after this inline script is parsed. --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Just behind the page's own fade-in, so it reads as arriving
            // rather than as already having been there.
            {{-- JSON_UNESCAPED_UNICODE because the default would emit the
                 Russian and Tajik greetings as \uXXXX escapes — correct, but
                 unreadable in view source and in the page's own tests. --}}
            setTimeout(() => window.showToast?.(@json(__('onboarding.back', ['name' => auth()->user()->name]), JSON_UNESCAPED_UNICODE), 'info'), 350);
        });
    </script>
@endif
@stack('scripts')
</body>
</html>
