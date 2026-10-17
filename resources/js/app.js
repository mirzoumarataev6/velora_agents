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

    // Project task-assignment notifications (App\Notifications\TaskAssigned,
    // via GET/POST /notifications/tasks...) — a second, separate bell next
    // to the lead one (see partials/task-bell.blade.php for why it is
    // separate rather than a section merged into the lead bell's panel).
    // Shares this same store and the one poll timer below on purpose: two
    // setInterval loops would double the idle network chatter for no
    // benefit, and this is exactly the "which element owns this" problem
    // the store already exists to sidestep. The open/closed state of the
    // DROPDOWN itself is deliberately NOT here, unlike the lead bell's
    // bellOpen: it lives as local x-data on the taskBell() component
    // instead (below), one instance per bell button — see that component's
    // own docblock for why a single shared boolean broke with two bell
    // instances on the page at once.
    tasksUnread: 0,
    tasksItems: [],
    tasksLoading: false,
    tasksLoadError: false,
    tasksPollUrl: '',
    tasksReadAllUrl: '',
    // A template, not a built URL: route() needs the notification id at
    // request time, which this store never has in advance. '__ID__' is
    // substituted in markTaskRead() below.
    tasksReadUrlTemplate: '',
    // The signed-in user's currently active team — only needed to decide
    // whether an item's team name is redundant (see boardLine() below), not
    // for anything authorization-related; the server already filtered the
    // feed to rows this user may actually see.
    activeTeamId: null,
    // Mirrors window.__i18n.current, but as an Alpine-reactive property:
    // window.__i18n is a plain object the locale switcher mutates directly
    // (see partials/i18n-bundle.blade.php), which Alpine's reactivity system
    // cannot see. i18nText() below reads THIS property instead, so every
    // x-text/:aria-label expression that calls it re-evaluates when the
    // locale switch fires `i18n:changed` — the same event the switcher
    // already dispatches for exactly this kind of client-rendered string.
    locale: window.__i18n?.current || 'ru',

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
        this.pollTasks();
        // One timer for both polls, not two: see tasksPollUrl's own comment
        // above. Skipped entirely while the tab is hidden — nobody is
        // watching the badge, so there is nothing to refresh for — and the
        // visibilitychange listener right below already covers "catch up
        // the instant it becomes visible again" on top of this.
        this._pollTimer = setInterval(() => {
            if (document.hidden) return;
            this.poll();
            this.pollTasks();
        }, 25000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.poll();
                this.pollTasks();
            }
        });
        document.addEventListener('i18n:changed', (event) => {
            this.locale = event.detail.locale;
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

    /**
     * Mirrors poll() above — same not-yet-configured guard, same
     * swallow-and-retry-next-tick on failure — except a failure also sets
     * tasksLoadError so the open dropdown can show something rather than
     * silently stay stale. Never throws: a failed fetch here must not spam
     * the console once per tick, forever, for a signal nobody but the badge
     * cares about.
     */
    async pollTasks() {
        if (!this.tasksPollUrl) return; // not yet configured, or this user has no projects access — see init()

        this.tasksLoading = true;
        try {
            const res = await fetch(this.tasksPollUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) { this.tasksLoadError = true; return; }
            const data = await res.json();
            this.tasksUnread = data.unread ?? 0;
            this.tasksItems = data.items ?? [];
            this.tasksLoadError = false;
        } catch (e) {
            this.tasksLoadError = true;
        } finally {
            this.tasksLoading = false;
        }
    },

    /**
     * Optimistic: the badge and the item's own dot update instantly, before
     * the network round trip even starts, because the user just told us
     * this is read by clicking it — waiting for a response to confirm what
     * they already know would only make the UI feel laggy. The POST is
     * fire-and-forget; if it fails, the next poll (≤25s, or immediately on
     * next open) reconciles from the server's own idea of what is read.
     */
    async markTaskRead(id) {
        const item = this.tasksItems.find((candidate) => candidate.id === id);
        if (item && !item.read) {
            item.read = true;
            this.tasksUnread = Math.max(0, this.tasksUnread - 1);
        }

        try {
            await fetch(this.tasksReadUrlTemplate.replace('__ID__', encodeURIComponent(id)), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
        } catch (e) { /* best-effort — see markTaskRead()'s own docblock */ }
    },

    async markAllTasksRead() {
        if (this.tasksUnread === 0) return;

        this.tasksItems = this.tasksItems.map((item) => ({ ...item, read: true }));
        this.tasksUnread = 0;

        try {
            await fetch(this.tasksReadAllUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
        } catch (e) { /* best-effort — see markTaskRead()'s own docblock */ }
    },

    /**
     * board_name alone unless the item's team both differs from the one the
     * rest of the shell is currently working with AND isn't a personal
     * workspace — a personal team's own name is never shown elsewhere in
     * the product either (see team-switcher.blade.php), so repeating it
     * here would just be noise.
     */
    boardLine(item) {
        if (item.team_id !== this.activeTeamId && !item.team_is_personal) {
            return `${item.board_name} · ${item.team_name}`;
        }

        return item.board_name;
    },

    /**
     * First letter of each of the first two words, uppercased — same rule
     * as User::initials() server-side, reimplemented here because the only
     * thing the feed sends is the assigner's name, not a ready-made initial.
     */
    initialsOf(name) {
        const words = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (words.length === 0) return '?';

        return (words[0][0] + (words.length > 1 ? words[1][0] : '')).toUpperCase();
    },

    /**
     * Resolves a namespaced key from window.__i18n (see partials/
     * i18n-bundle.blade.php) against `this.locale`, not window.__i18n.current
     * directly — see that property's own comment for why the distinction
     * matters for reactivity. Substitutes `:key` placeholders the same way
     * Laravel's own __() does, since the server deliberately leaves these
     * untranslated-at-rest: this bell builds every sentence from DATA
     * (assigned_by_name, task_title, …), never a prebuilt one, the same
     * convention App\Notifications\TaskAssigned's own docblock describes.
     */
    i18nText(path, vars = {}) {
        const messages = window.__i18n?.messages?.[this.locale];
        let node = messages;
        for (const part of path.split('.')) {
            if (node == null || typeof node !== 'object') { node = null; break; }
            node = node[part];
        }

        let text = typeof node === 'string' ? node : '';
        for (const [key, value] of Object.entries(vars)) {
            text = text.split(`:${key}`).join(value);
        }

        return text;
    },

    /**
     * Splits an i18nText() template around its `:key` placeholders into an
     * ordered list of {value, strong} segments, so the two dynamic values
     * (the assigner's name, the task title) can render in medium weight
     * while the surrounding sentence stays regular — without ever assuming
     * which order a locale puts them in (ru/tg may not match the en word
     * order this was drafted in).
     */
    sentenceParts(template, vars) {
        const keys = Object.keys(vars);
        if (keys.length === 0) return [{ value: template, strong: false }];

        const tokens = keys.map((key) => `:${key}`);
        return template
            .split(new RegExp(`(${tokens.join('|')})`, 'g'))
            .filter((segment) => segment !== '')
            .map((segment) => (tokens.includes(segment)
                ? { value: vars[segment.slice(1)], strong: true }
                : { value: segment, strong: false }));
    },

    /**
     * Intl.RelativeTimeFormat in the current locale, falling back to a short
     * absolute date when the runtime doesn't really have that locale — ru
     * and en are universally supported, but tg (Tajik) is not guaranteed
     * everywhere, and an unsupported locale does NOT throw: Intl silently
     * resolves to its own best-fit locale instead, which would otherwise
     * show English or Russian relative phrasing mislabeled as Tajik. The
     * resolvedOptions() check below is what actually catches that — a plain
     * try/catch around the constructor would not.
     */
    relativeTime(iso) {
        const date = new Date(iso);
        const diffSeconds = Math.round((date.getTime() - Date.now()) / 1000);

        try {
            const rtf = new Intl.RelativeTimeFormat(this.locale, { numeric: 'auto' });
            if (!rtf.resolvedOptions().locale.toLowerCase().startsWith(this.locale.toLowerCase())) {
                throw new Error('Intl.RelativeTimeFormat has no real support for this locale');
            }

            const units = [
                [31536000, 'year'], [2592000, 'month'], [604800, 'week'],
                [86400, 'day'], [3600, 'hour'], [60, 'minute'],
            ];
            for (const [secondsInUnit, unit] of units) {
                if (Math.abs(diffSeconds) >= secondsInUnit) {
                    return rtf.format(Math.round(diffSeconds / secondsInUnit), unit);
                }
            }

            return rtf.format(diffSeconds, 'second');
        } catch (e) {
            return date.toLocaleDateString(this.locale, { day: 'numeric', month: 'short' });
        }
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

/**
 * Double-submit guard — ONE place, not per form.
 *
 * A fast double-click, or Enter pressed twice before the first response
 * lands, resubmits the same form a second time. That is where a duplicate
 * agent, a duplicate invitation, a duplicate CRM note, or a duplicate task
 * card comes from, and on the second response it can just as easily read as
 * an error (a unique-constraint violation, a token already consumed) rather
 * than a dup, which is the more confusing of the two outcomes.
 *
 * Rejected: copying the `saving`/`connecting`/`sending` Alpine flag pattern
 * already used by `@submit.prevent` forms (the inbox composer, the channel
 * connect forms, knowledge upload, …) into every remaining plain form. It
 * works, but it is exactly the per-form repetition this is supposed to
 * replace, and it is what every one of the forms this bug was filed against
 * (agent create, invite, task card, CRM note) was still missing.
 * Rejected: a server-side idempotency key alone. It can stop the second ROW,
 * but the browser still fires two requests, and the user still sees
 * whatever the second one answers with — the "confusing error on the second
 * response" half of the bug is a client problem, not a database one.
 *
 * This only acts on a submission that is about to make a real network round
 * trip. By the time this listener runs (bubble phase on `document`, so it is
 * last among listeners on the form itself and anything registered earlier
 * here) `event.defaultPrevented` already reflects whatever an
 * `@submit.prevent` handler or the `data-task-toggle` listener just above
 * decided — both call `preventDefault()` as their first line. Those already
 * own their own disable/re-enable, and must be left alone: no Turbo
 * `FormSubmission` ever starts for them, so there is no `turbo:submit-end`
 * to release this guard's lock on, and the next legitimate click would stay
 * dead forever instead of just the accidental second one.
 */
const SUBMIT_CONTROLS_SELECTOR = 'button[type="submit"], button:not([type]), input[type="submit"]';

function lockFormSubmission(form, submitter) {
    form.setAttribute('data-submit-locked', '');

    // A disabled control is excluded from FormData entirely (the HTML spec
    // says so), so if a controller ever reads the clicked button's own
    // name/value — none does today — disabling it here would silently drop
    // that value from the request. A hidden mirror keeps it in the request
    // while the real button goes inert.
    if (submitter?.name && submitter.form === form) {
        const shadow = document.createElement('input');
        shadow.type = 'hidden';
        shadow.name = submitter.name;
        shadow.value = submitter.value;
        shadow.setAttribute('data-submit-shadow', '');
        form.appendChild(shadow);
    }

    form.querySelectorAll(SUBMIT_CONTROLS_SELECTOR).forEach((control) => {
        control.disabled = true;
        control.setAttribute('aria-busy', 'true');
    });
}

function unlockFormSubmission(form) {
    if (!form.hasAttribute('data-submit-locked')) return;

    form.removeAttribute('data-submit-locked');
    form.querySelectorAll('[data-submit-shadow]').forEach((el) => el.remove());
    form.querySelectorAll(SUBMIT_CONTROLS_SELECTOR).forEach((control) => {
        control.disabled = false;
        control.removeAttribute('aria-busy');
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.hasAttribute('data-submit-locked')) {
        // The second click, or the second Enter, of the double. A disabled
        // button already stops the browser from generating this event at
        // all in the ordinary case; this only matters for a resubmission
        // fired some other way (a `requestSubmit()` from a key handler, say)
        // that does not go through the disabled control.
        event.preventDefault();
        return;
    }

    // Already claimed by `@submit.prevent`/`data-task-toggle` above — see
    // the block comment above `SUBMIT_CONTROLS_SELECTOR` for why this must
    // not also lock it.
    if (event.defaultPrevented) return;

    // Escape hatch for a form that legitimately needs to stay submittable
    // while its own previous submission is still in flight. Nothing in this
    // app needs it today — shipping the hatch unused is cheaper than finding
    // out later which form does, mid-incident.
    if (form.hasAttribute('data-allow-resubmit')) return;

    // A form whose response opens in a new tab is not what this bug
    // duplicates: the tab holding the button is free, and locking it would
    // just look stuck for no reason. (Audited: nothing in this codebase
    // submits a `<form>` to a file-response endpoint today — the customer
    // and deal exports are plain `<a download>`/`data-turbo="false"` links,
    // never a form — so this is the only automatic rule needed for now; a
    // future file-response form gets `data-allow-resubmit` too.)
    if (form.target === '_blank') return;

    // GET here is a search/filter resubmission, not a row-creating action —
    // a second identical GET is not a duplicate of anything.
    if (form.method.toLowerCase() === 'get') return;

    lockFormSubmission(form, event.submitter);
});

/**
 * `FormSubmission.requestFinished()` dispatches `turbo:submit-end`
 * unconditionally — success, validation failure, or network error — so this
 * one release point also covers a validation-error re-render leaving the
 * button dead, which is the regression this guard must not reintroduce.
 */
document.addEventListener('turbo:submit-end', (event) => {
    if (event.target instanceof HTMLFormElement) unlockFormSubmission(event.target);
});

/**
 * Two cases `turbo:submit-end` does not cover, because no `FormSubmission`
 * ever finishes:
 *  - Turbo is about to snapshot the page before navigating away. A form
 *    cached mid-lock would restore (via Back) looking permanently stuck.
 *  - A `data-turbo="false"` form (team switch, sign-out) does a true
 *    browser navigation, which the real back/forward cache can later
 *    restore — `pageshow` is the one event that fires for that restore.
 */
document.addEventListener('turbo:before-cache', () => {
    document.querySelectorAll('form[data-submit-locked]').forEach(unlockFormSubmission);
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submit-locked]').forEach(unlockFormSubmission);
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
 * Projects board: opening or closing a task card is a Turbo visit to the
 * same board with (or without) ?task=ID, and Turbo scrolls a normal visit to
 * the top like any link click — resetting the kanban strip's horizontal
 * scroll and the page's vertical scroll every time. Opt-in per link
 * (`data-preserve-scroll`, set only on the card overlay, the panel's X and
 * its backdrop — Esc already clicks the X) rather than a blanket rule:
 * pagination, filters and a link to a different board must keep landing at
 * the top like any other visit.
 *
 * Registered once here at module load, same as every other delegated
 * listener in this file — app.js itself is loaded from `<head>` (outside
 * Turbo's `<main>` swap region) and never re-executes on navigation, so
 * there is nothing to guard against double-binding.
 *
 * In-memory only: this is a same-tab convenience, not state worth
 * persisting across a reload or a new tab, so a plain module-level variable
 * is enough and a failure to read scroll state (try/catch below) just means
 * nothing gets restored rather than a thrown error.
 *
 * Two-stage on purpose — `candidate` then `saved` — because `click` fires
 * for a ctrl/cmd/middle/shift click too, and those open a new tab without
 * ever starting a Turbo visit. A single-stage version saved on click and
 * consumed on the next turbo:load stayed set across that open-in-new-tab, so
 * the NEXT unrelated visit in THIS tab (the Filter button, a pagination
 * link) wrongly restored a position nothing asked to return to.
 * `turbo:before-visit` only fires for a real Turbo visit, so promoting
 * `candidate` to `saved` there — and clearing `candidate` either way — means
 * a new-tab click leaves nothing for this tab's next visit to pick up.
 */
let scrollCandidate = null;
let savedBoardScroll = null;

document.addEventListener('click', (event) => {
    // Reset on every click, not just ones that end up matching below: a
    // candidate from a previous click that never reached turbo:before-visit
    // (new tab, or any other click afterward) must not survive to be
    // promoted by a LATER, unrelated visit.
    scrollCandidate = null;

    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[data-preserve-scroll]');
    if (!link) return;

    try {
        const key = document.querySelector('[data-scroll-key]')?.dataset.scrollKey;
        if (!key) return;
        const strip = document.querySelector('[data-kanban-strip]');
        scrollCandidate = { key, x: strip?.scrollLeft ?? 0, y: window.scrollY };
    } catch {
        scrollCandidate = null;
    }
});

document.addEventListener('turbo:before-visit', () => {
    savedBoardScroll = scrollCandidate;
    scrollCandidate = null;
});

/** Shared by both restore points below — only acts when the page carries the
 * SAME board's data-scroll-key as the one the position was saved for; a card
 * link always targets its own board, but this still guards the case where
 * the saved value is stale for any other reason. */
function restoreBoardScroll(state) {
    const root = document.querySelector('[data-scroll-key]');
    if (!root || root.dataset.scrollKey !== state.key) return;
    const strip = document.querySelector('[data-kanban-strip]');
    if (strip) strip.scrollLeft = state.x;
    window.scrollTo(0, state.y);
}

/**
 * Measured directly (frame-by-frame, timestamped against turbo:render): with
 * the restore done only from turbo:load, turbo:load itself lands ~300ms
 * after turbo:render, and Turbo's own scroll-to-top has already painted by
 * then — 10-18 real frames at (0,0) before the jump, a visible flash. This
 * restores from turbo:render instead: double-nested requestAnimationFrame so
 * Turbo's OWN reset has already landed (it runs as a synchronous DOM write,
 * not scheduled against a frame) but nothing has been PAINTED yet — a rAF
 * callback runs before the browser paints the frame it was scheduled in,
 * and the nested one gives Turbo's own render a full extra frame to settle
 * first.
 *
 * Deliberately does NOT clear `savedBoardScroll` — turbo:load below still
 * does that, and still re-applies the same values. Idempotent (setting an
 * identical scroll position twice is a no-op), and keeps a single place
 * responsible for "this has now been consumed" so a value can never survive
 * to leak into a later, unrelated visit even if this earlier attempt never
 * fires for some reason (a route that skips turbo:render entirely, say).
 */
document.addEventListener('turbo:render', () => {
    if (!savedBoardScroll) return;
    const state = savedBoardScroll;
    requestAnimationFrame(() => requestAnimationFrame(() => restoreBoardScroll(state)));
});

/**
 * Consumed exactly once per Turbo visit, win or lose, so a save can never
 * leak into a later, unrelated one. Kept as a fallback alongside the earlier
 * turbo:render attempt above — idempotent, so if that one already applied
 * the position this is a harmless no-op re-application, and if it didn't
 * fire this is still what actually restores it.
 */
document.addEventListener('turbo:load', () => {
    if (!savedBoardScroll) return;
    const state = savedBoardScroll;
    savedBoardScroll = null;
    requestAnimationFrame(() => restoreBoardScroll(state));
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
