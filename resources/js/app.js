import '@hotwired/turbo';
import { visit } from '@hotwired/turbo';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Gridstack, loaded only by the page that needs it.
 *
 * A dynamic import rather than a top-level one, so Vite splits it into its own
 * chunk: the library and its stylesheet are about 70KB, and every other page in
 * this product — the inbox, the CRM board, the agent wizard — would otherwise
 * pay for a drag-and-drop grid it never renders.
 *
 * The CSS is imported here beside the JS rather than in app.css for the same
 * reason: `@import` in the stylesheet would inline it into the main bundle and
 * undo the split.
 *
 * Resolved once and memoized — a board that re-initializes (after a widget is
 * added, say) must not fetch the chunk again. This module-level cache now also
 * outlives Turbo navigations (the whole point of Turbo is that this script
 * never re-runs from scratch), which only makes the memoization more valuable:
 * returning to the BI section a second time in one session costs nothing.
 *
 * DEFINED BEFORE `Alpine.start()`, AND THAT IS LOAD-BEARING. Alpine initializes
 * every `x-data` component synchronously inside start(), so a component whose
 * `x-init` calls this would run before the assignment and get
 * "loadGridStack is not a function" — which is exactly what happened the first
 * time, and it fails silently as an Alpine expression warning rather than as
 * anything a page looks broken enough to notice. Anything on `window` that a
 * component touches during init belongs above that call.
 */
let gridStackModule = null;

window.loadGridStack = async function loadGridStack() {
    gridStackModule ??= (async () => {
        const [{ GridStack }] = await Promise.all([
            import('gridstack'),
            import('gridstack/dist/gridstack.min.css'),
        ]);

        return GridStack;
    })();

    return gridStackModule;
};

// Only analysis pages request ApexCharts. Keeping this import behind the board
// component means the inbox and agent setup never download a charting runtime.
// Kept as its own promise (rather than folded into loadBiCharts) so the Turbo
// cleanup hook below can reach `destroyAllCharts` without re-importing.
let biChartModulePromise = null;
function biCharts() {
    biChartModulePromise ??= import('./bi-charts.js');
    return biChartModulePromise;
}
window.loadBiCharts = async function loadBiCharts() {
    return (await biCharts()).mountBiCharts;
};

/**
 * Shared chrome state: the mobile nav toggle, the sidebar collapse, and the
 * in-app lead notification bell (badge count + recent list).
 *
 * An Alpine STORE rather than an `x-data` component on the shell wrapper —
 * deliberately. A store is a plain JS singleton that lives for the whole
 * session, not tied to any element's presence in the DOM; previously this was
 * `x-data="dashboardShell(...)"` on a wrapping div, which worked only because
 * every full-page navigation threw the whole JS realm away and started fresh.
 * Turbo keeps the JS realm alive across navigations, and if this had stayed
 * an `x-data` component on an element Turbo recreates on every visit, each
 * visit would call `initBell()` again — stacking one more concurrent
 * `setInterval` polling loop every time, forever. A store sidesteps the
 * question of "which element owns this" entirely: `init()` below guards
 * itself so it runs its setup exactly once no matter how many times (once per
 * Turbo navigation, by design — see the `turbo:load` listener further down)
 * the bootstrap script that calls it re-executes.
 */
Alpine.store('shell', {
    mobileNavOpen: false,
    // The rail has two inputs: the owner's own toggle (railPinned, persisted)
    // and the inbox asking for space while a conversation is open.
    // railCollapsed is the effective state of the two. Desktop only — on
    // mobile the sidebar is an off-canvas drawer.
    railCollapsed: false,
    railPinned: false,
    bellOpen: false,
    count: 0,
    recent: [],
    escalations: [],
    pollUrl: '',
    seenUrl: '',
    leadsUrl: '',
    needsHumanUrl: '',
    labels: { signals: {} },
    _started: false,
    _pollTimer: null,

    /**
     * Called once, synchronously, before `Alpine.start()` below — with the
     * (static, session-wide) config the server rendered; see
     * layouts/dashboard.blade.php. Guarded so a defensive extra call is a
     * no-op: the URLs and labels never change between pages, so re-applying
     * them would be harmless but pointless, and re-starting the poll loop
     * would not be harmless at all.
     */
    init(config) {
        if (this._started || !config) return;
        this._started = true;

        Object.assign(this, config);

        // The head script (see layouts/dashboard.blade.php) has already
        // applied the saved state as a class to avoid a flash, so read it
        // back from there rather than from localStorage again — one source
        // of truth, no chance of the two disagreeing on the first frame.
        this.railPinned = document.documentElement.classList.contains('rail-collapsed');
        this.railCollapsed = this.railPinned;

        this.poll();
        this._pollTimer = setInterval(() => this.poll(), 25000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.poll();
        });
    },

    toggleRail() {
        this.railPinned = !this.railPinned;
        // A manual toggle wins immediately, even over the inbox: the owner
        // just said what they want.
        this.railCollapsed = this.railPinned;
        document.documentElement.classList.toggle('rail-collapsed', this.railPinned);

        try {
            localStorage.setItem('sidebar:collapsed', this.railPinned ? '1' : '0');
        } catch (e) { /* private mode — the choice just won't outlive the tab */ }
    },

    async poll() {
        if (!this.pollUrl) return; // not yet configured — see init()

        try {
            const res = await fetch(this.pollUrl, { headers: { Accept: 'application/json' } });
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
            const res = await fetch(this.seenUrl, {
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
});

/**
 * Synced BEFORE `Alpine.start()`, not from the `turbo:load` listener below —
 * load-bearing in the same way the comment above `loadGridStack` is. The
 * previous `x-init="initRail()"` ran synchronously as part of Alpine's own
 * component boot, so the very first reactive render already had the right
 * `railCollapsed` value. Reading it only on `turbo:load` would run AFTER
 * Alpine's first render pass, letting that first paint briefly use the
 * store's default (`false`) and then flip — reintroducing the exact flash
 * the original ordering existed to avoid. `window.__shellConfig` is set by
 * an inline script earlier in the document (see layouts/dashboard.blade.php),
 * so it is already available here, before this deferred module runs.
 */
Alpine.store('shell').init(window.__shellConfig);

Alpine.start();

/**
 * Filter controls that navigate via a `<select>` onchange rather than a
 * submit button (the CRM task list's status/sort filters). A plain Turbo
 * `visit()` — the same engine every link and form submission on the page now
 * goes through — rather than a hand-rolled fetch+DOM-swap: see the retired
 * `navigateCrm()` this replaced, which solved exactly the problem Turbo now
 * solves for the whole app, for this one section only.
 */
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-crm-filter-form] select');
    if (!select) return;
    const form = select.form;
    const url = new URL(form.action, window.location.href);
    new FormData(form).forEach((value, key) => { if (value) url.searchParams.set(key, value); });
    visit(url.href);
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-task-toggle]');
    if (!form) return;
    event.preventDefault();
    const input = form.querySelector('[name="done"]');
    const button = form.querySelector('button');
    const label = form.parentElement?.querySelector('[data-task-label]');
    const nextDone = input.value === '1';
    const before = label?.classList.contains('line-through') ?? false;
    label?.classList.toggle('line-through', nextDone);
    button.disabled = true;
    try {
        const response = await fetch(form.action, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ done: nextDone }),
        });
        if (!response.ok) throw new Error('Task update failed');
        input.value = nextDone ? '0' : '1';
        window.showToast?.(form.dataset.success, 'success');
    } catch (error) {
        label?.classList.toggle('line-through', before);
        window.showToast?.(form.dataset.failure, 'error');
    } finally { button.disabled = false; }
});

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Delegated, registered once at module load rather than re-bound on every
 * navigation — unlike the page content inside `<main>`, this button lives in
 * the persistent shell (see layouts/dashboard.blade.php's `data-turbo-permanent`
 * sidebar/header) and never disappears, so a listener re-attached on every
 * `turbo:load` would stack one more copy on the same, still-alive button.
 */
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('#theme-toggle');
    if (!toggle) return;

    const isDark = document.documentElement.classList.toggle('dark');
    const theme = isDark ? 'dark' : 'light';
    localStorage.setItem('theme', theme);

    // Signed-in dashboards expose this endpoint so the choice follows the
    // account rather than the browser. Fire-and-forget: localStorage has
    // already made the change stick locally, so a failure here costs
    // nothing this session.
    const themeUrl = document.querySelector('meta[name="theme-url"]')?.content;
    if (!themeUrl) return;

    fetch(themeUrl, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ theme }),
        keepalive: true,
    }).catch(() => {});

    document.dispatchEvent(new CustomEvent('theme:changed', { detail: { theme } }));
});

/**
 * Also delegated and registered once: these links live inside page content
 * (`<main>`), which Turbo replaces on every visit, so a per-element listener
 * would need re-binding every `turbo:load` for no benefit — delegation reaches
 * new instances of these links for free.
 */
document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-transition]');
    if (!link || reduceMotion) return;
    const href = link.getAttribute('href');
    if (!href || link.target === '_blank') return;
    event.preventDefault();
    document.body.classList.add('page-exit');
    setTimeout(() => { window.location.href = href; }, 200);
});

/**
 * Dashboard summary figures counting up from zero on first paint.
 *
 * The one piece of dashboard motion that cannot be done in CSS — there is no
 * way to interpolate a text node's numeric content. The element already
 * contains its final value, server-rendered. This only ever REPLACES that
 * text for the duration of the animation and puts the exact original string
 * back at the end, so the number a reader ends up looking at is always the
 * one the server sent, not one reconstructed from a float. A page with JS
 * disabled, or reduced motion asked for, simply shows it immediately.
 */
function countUpNumbers() {
    const targets = document.querySelectorAll('[data-countup]');

    if (reduceMotion || targets.length === 0) return;

    targets.forEach((el) => {
        const final = Number(el.dataset.countup);
        const suffix = el.dataset.countupSuffix ?? '';
        const settled = el.textContent;

        // Nothing to watch climb, and nothing worth animating for: a zero, a
        // one, or a value that was not a number after all.
        if (!Number.isFinite(final) || final <= 1) return;

        // Whole numbers count in whole numbers; a percentage keeps its decimal
        // so it does not visibly snap at the end.
        const decimals = Number.isInteger(final) ? 0 : 1;
        const duration = 700;
        const started = performance.now();

        el.classList.add('counting');

        const step = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            // Ease-out cubic: fast at the start, settling gently, which is what
            // makes it read as a number arriving rather than a slot machine.
            const eased = 1 - Math.pow(1 - progress, 3);

            if (progress < 1) {
                el.textContent = (final * eased).toFixed(decimals) + suffix;
                requestAnimationFrame(step);
                return;
            }

            el.textContent = settled;
            el.classList.remove('counting');
        };

        el.textContent = (0).toFixed(decimals) + suffix;
        requestAnimationFrame(step);
    });
}

/**
 * Keeps the sidebar/header's "current page" highlight correct once the
 * shell is `data-turbo-permanent` (see layouts/dashboard.blade.php): Turbo
 * preserves that exact DOM node across navigations, so the `nav-item-active`
 * class and `aria-current` attribute the SERVER baked in at first render are
 * frozen at whatever page the shell happened to be born on. This recomputes
 * them from the real URL on every navigation instead.
 *
 * Prefix matching, not exact matching — mirrors the `request()->routeIs()`
 * calls the Blade template used for the same job on first render, which key
 * off route-name prefixes that correspond 1:1 with these path prefixes.
 */
function updateActiveNav() {
    const path = window.location.pathname;
    const active = {
        dashboard: path === '/dashboard',
        agents: path.startsWith('/agents'),
        conversations: path.startsWith('/conversations'),
        crm: path.startsWith('/crm'),
        bi: path.startsWith('/bi'),
        leads: path.startsWith('/leads'),
        settings: path.startsWith('/settings'),
    };

    document.querySelectorAll('[data-nav]').forEach((link) => {
        const isActive = !!active[link.dataset.nav];
        link.classList.toggle('nav-item-active', isActive);
        if (isActive) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
    });
}

/**
 * Everything here used to run once, on `DOMContentLoaded`. Under Turbo that
 * event fires only for the very first page of the session — every later
 * navigation swaps `<body>` without a real page load, so anything that needs
 * to happen on EVERY page (a freshly rendered flash toast, this page's own
 * `.reveal` elements, this page's own count-up figures, which page is
 * "active" in the nav) has to be redone each time. `turbo:load` is Turbo's
 * equivalent: it fires once for the first page and again after every visit.
 */
document.addEventListener('turbo:load', () => {
    requestAnimationFrame(() => document.body.classList.add('ready'));

    document.querySelectorAll('[data-flash-toast]').forEach((toast) => {
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 4000);
    });

    if (reduceMotion) {
        document.querySelectorAll('.reveal').forEach((el) => el.classList.add('is-visible'));
    } else {
        const revealObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
        );
        document.querySelectorAll('.reveal').forEach((el) => revealObserver.observe(el));
    }

    countUpNumbers();
    updateActiveNav();
});

/**
 * ApexCharts instances are keyed in bi-charts.js by the DOM element they were
 * mounted into. Turbo replaces `<main>` wholesale on every navigation, which
 * would otherwise leave that map holding chart instances and callbacks
 * pointing at detached nodes forever — a leak that a full page reload used to
 * clear for free. Fired before Turbo caches/discards the current page, so
 * cleanup always runs before the elements it is cleaning up are gone.
 *
 * Only touches the module if a BI page actually loaded it this session —
 * `biChartModulePromise` stays null for every visitor who never opens one.
 */
document.addEventListener('turbo:before-cache', () => {
    biChartModulePromise?.then((mod) => mod.destroyAllCharts?.());
});

window.getCookie = function getCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
};

window.showToast = function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const colors = {
        // Success uses the dedicated success token, not the brand green — on a
        // green-branded UI an ordinary primary surface would otherwise read as
        // a confirmation.
        success: 'bg-success text-white',
        error: 'bg-danger text-white',
        info: 'bg-ink text-white dark:bg-night-ink dark:text-night',
    };
    const toast = document.createElement('div');
    toast.className = `toast mt-2 rounded-2xl px-4 py-3 text-sm font-medium shadow-xl ${colors[type] || colors.info}`;
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 4000);
};
