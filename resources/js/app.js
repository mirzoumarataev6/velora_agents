import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

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

    /**
     * Stage mode — the presentation setting.
     *
     * The attribute is applied pre-paint by partials/stage-mode.blade.php, so
     * all this does is flip it and record the choice. Everything it changes
     * (root font size, muted text, border weight, shadows, transparency) is a
     * token swap in tokens.css; nothing here knows any of those values.
     *
     * localStorage rather than the account, deliberately: stage mode is a
     * property of the room, not of the person. The same owner wants it on for
     * the projector and off at their desk half an hour later, so following
     * them between devices would be exactly wrong.
     */
    const stageToggle = document.getElementById('stage-toggle');

    stageToggle?.addEventListener('click', () => {
        const root = document.documentElement;
        const on = root.getAttribute('data-mode') !== 'stage';

        if (on) {
            root.setAttribute('data-mode', 'stage');
        } else {
            root.removeAttribute('data-mode');
        }

        // The label has to say what the button will DO next, not what the state
        // is — and it is the accessible name, so it cannot be left stale.
        stageToggle.setAttribute('aria-pressed', String(on));
        stageToggle.setAttribute(
            'aria-label',
            (on ? stageToggle.dataset.labelOn : stageToggle.dataset.labelOff) ?? ''
        );

        try {
            localStorage.setItem('mode', on ? 'stage' : 'normal');
        } catch (e) {
            /* private mode — the choice just won't outlive the tab */
        }

        // Charts read their colours from CSS custom properties, so anything
        // already drawn has to be told to re-read them.
        document.dispatchEvent(new CustomEvent('mode:changed', { detail: { stage: on } }));
    });

    // The button renders with the "off" label server-side, because the server
    // cannot know what localStorage holds. If the pre-paint script switched
    // stage mode on, correct the state here.
    if (stageToggle && document.documentElement.getAttribute('data-mode') === 'stage') {
        stageToggle.setAttribute('aria-pressed', 'true');
        stageToggle.setAttribute('aria-label', stageToggle.dataset.labelOn ?? '');
    }

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
