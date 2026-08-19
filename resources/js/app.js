import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
