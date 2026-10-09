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
 * added, say) must not fetch the chunk again.
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
let biChartModule = null;
window.loadBiCharts = async function loadBiCharts() {
    biChartModule ??= import('./bi-charts.js').then(module => module.mountBiCharts);
    return biChartModule;
};

Alpine.start();

/**
 * CRM section links swap only the content column. The app shell and Alpine's
 * long-lived sidebar stay mounted, so moving between CRM work areas feels
 * immediate while the URL/history still behave like ordinary navigation.
 * Page-specific inline components are explicitly re-evaluated before Alpine
 * initializes the new tree; executing every script in fetched HTML would also
 * rerun the theme bootstrap and the global app bundle.
 */
let crmNavigationSequence = 0;
let crmNavigationController = null;

async function navigateCrm(url, push = true) {
    const sequence = ++crmNavigationSequence;
    crmNavigationController?.abort();
    crmNavigationController = new AbortController();
    const currentMain = document.querySelector('main[data-app-main]');
    if (!currentMain || !url) return;
    currentMain.classList.add('crm-nav-loading');

    try {
        const response = await fetch(url, {
            headers: { Accept: 'text/html', 'X-CRM-Partial': '1' },
            signal: crmNavigationController.signal,
        });
        if (sequence !== crmNavigationSequence) return;
        if (!response.ok) throw new Error('CRM navigation failed');
        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const incoming = parsed.querySelector('main[data-app-main]');
        if (!incoming) throw new Error('CRM page content missing');

        window.Alpine?.destroyTree(currentMain);
        const replacement = document.importNode(incoming, true);
        currentMain.replaceWith(replacement);
        document.title = parsed.title;
        document.querySelectorAll('script[data-crm-page-script]').forEach(script => script.remove());
        parsed.querySelectorAll('script[data-crm-page-script]').forEach(source => {
            const script = document.createElement('script');
            script.dataset.crmPageScript = '';
            script.textContent = source.textContent;
            document.body.appendChild(script);
        });
        window.Alpine?.initTree(replacement);
        if (push) window.history.pushState({ crm: true }, '', url);
        // A section switch is not a long-page navigation; smooth scrolling made
        // the new page feel slower even after its content had arrived.
        window.scrollTo(0, 0);
        replacement.classList.add('crm-nav-enter');
        requestAnimationFrame(() => replacement.classList.remove('crm-nav-enter'));
    } catch (error) {
        if (error.name === 'AbortError' || sequence !== crmNavigationSequence) return;
        // A failed partial request must leave the current workspace usable.
        // Falling back to location.assign made a transient timeout look like
        // ordinary CRM navigation and discarded the user's in-page state.
        window.showToast?.(currentMain.dataset.crmNavigationFailed || 'Could not load this CRM section. Please try again.', 'error');
    } finally {
        if (sequence === crmNavigationSequence) {
            document.querySelector('main[data-app-main]')?.classList.remove('crm-nav-loading');
        }
    }
}

document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') return;
    const target = new URL(link.href, window.location.href);
    if (target.origin !== window.location.origin || !target.pathname.startsWith('/crm')) return;
    event.preventDefault();
    navigateCrm(target.href);
});

document.addEventListener('change', event => {
    const select = event.target.closest('[data-crm-filter-form] select');
    if (!select) return;
    const form = select.form;
    const url = new URL(form.action, window.location.href);
    new FormData(form).forEach((value, key) => { if (value) url.searchParams.set(key, value); });
    navigateCrm(url.href);
});

document.addEventListener('submit', async event => {
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

window.addEventListener('popstate', () => {
    if (window.location.pathname.startsWith('/crm')) navigateCrm(window.location.href, false);
});

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Dashboard summary figures counting up from zero on first paint.
 *
 * The one piece of dashboard motion that cannot be done in CSS — there is no
 * way to interpolate a text node's numeric content — so it lives here beside
 * the reveal observer rather than as a component of its own.
 *
 * The element already contains its final value, server-rendered. This only ever
 * REPLACES that text for the duration of the animation and puts the exact
 * original string back at the end, so the number a reader ends up looking at is
 * always the one the server sent, not one reconstructed from a float. A page
 * with JS disabled, or reduced motion asked for, simply shows it immediately.
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

document.addEventListener('DOMContentLoaded', () => {
    requestAnimationFrame(() => document.body.classList.add('ready'));

    document.querySelectorAll('[data-flash-toast]').forEach(toast => {
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

    document.getElementById('theme-toggle')?.addEventListener('click', () => {
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

    document.querySelectorAll('a[data-transition]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href');
            if (!href || link.target === '_blank' || reduceMotion) return;
            event.preventDefault();
            document.body.classList.add('page-exit');
            setTimeout(() => {
                window.location.href = href;
            }, 200);
        });
    });
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
