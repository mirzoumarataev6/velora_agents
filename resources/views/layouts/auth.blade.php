<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="UTF-8" />
<script>
    (function () {
        var stored = localStorage.getItem('theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var isDark = stored ? stored === 'dark' : prefersDark;
        document.documentElement.classList.toggle('dark', isDark);
    })();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1" />
{{-- Declares that this page has its own dark theme. Without it Samsung
     Internet's (and Chrome's auto) dark mode force-darkens the light theme,
     which turned the old terracotta accent dark red on those phones only. The
     same forced-dark pass muddies the azure accent this scheme uses, so the
     declaration matters just as much after the re-skin. --}}
<meta name="color-scheme" content="light dark" />
@include('partials.seo-meta', [
    'seoTitle' => ($seoTitle ?? __('common.brand')).' — '.__('common.brand'),
    'seoDescription' => $seoDescription ?? '',
])
{{-- Compiled Tailwind bundle (shared, cached across the app) instead of the
     in-browser CDN JIT that made these pages compile CSS on every load. CSS
     only: the auth pages run their own vanilla inline JS (theme toggle, form
     handling) and loading app.js would double-bind those handlers. --}}
{{-- Self-hosted Inter, same as the dashboard and the landing. Replaces the
     third-party Google Fonts request this page used to make. --}}
@include('partials.fonts')
@vite(['resources/css/app.css'])
<style>
    /* Font family and .font-display now come from the theme tokens in app.css,
       so all three surfaces share one definition. */

    .page-enter { opacity: 0; transition: opacity .35s ease; }
    .page-enter.ready { opacity: 1; }
    .page-exit { opacity: 0 !important; transition: opacity .2s ease; }

    .reveal {
        opacity: 0;
        transform: translateY(20px);
        filter: blur(6px);
        transition: opacity .4s cubic-bezier(.22,1,.36,1), transform .4s cubic-bezier(.22,1,.36,1), filter .4s cubic-bezier(.22,1,.36,1);
    }
    .reveal.is-visible { opacity: 1; transform: translateY(0); filter: blur(0); }

    .field-shake { animation: shake .4s ease; }
    @keyframes shake {
        10%, 90% { transform: translateX(-1px); }
        20%, 80% { transform: translateX(2px); }
        30%, 50%, 70% { transform: translateX(-4px); }
        40%, 60% { transform: translateX(4px); }
    }

    .spinner {
        display: inline-block; width: 1rem; height: 1rem; border-radius: 9999px;
        border: 2px solid rgba(255,255,255,.4); border-top-color: #fff;
        animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .toast {
        pointer-events: auto;
        opacity: 0;
        transform: translateY(-12px) scale(.96);
        transition: opacity .35s ease, transform .35s ease;
    }
    .toast.show { opacity: 1; transform: translateY(0) scale(1); }

    .sr-only {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
    }
    .skip-link {
        position: fixed; top: -3rem; left: 1rem; z-index: 100;
        transition: top .2s ease;
    }
    .skip-link:focus { top: 1rem; }

    @media (prefers-reduced-motion: reduce) {
        .reveal { opacity: 1; transform: none; filter: none; transition: none; }
        .field-shake { animation: none; }
        .page-enter, .page-exit { transition: none; }
    }
</style>
</head>
<body class="page-enter flex min-h-screen flex-col bg-paper text-ink antialiased transition-colors duration-300 dark:bg-night dark:text-night-ink">

<a href="#main-content" class="btn btn-primary skip-link">{{ __('common.skip_to_content') }}</a>

{{-- Slim top bar: logo on the left, locale switcher + theme toggle on the right. --}}
<header class="flex h-16 shrink-0 items-center justify-between px-4 sm:px-6">
    <a href="{{ route('welcome') }}" data-transition aria-label="{{ __('common.brand') }}" class="flex items-center">
        <img src="{{ asset('images/brand/gen/mark.webp') }}?v={{ config('agent.brand_mark_version') }}" width="32" height="32" alt="{{ __('common.brand') }}" class="h-8 w-8" decoding="async" />
    </a>

    <div class="flex items-center gap-2">
        @include('partials.locale-switcher')
        <button id="theme-toggle" type="button" aria-label="{{ __('common.toggle_theme') }}" aria-pressed="false"
            class="flex h-9 w-9 items-center justify-center rounded-full bg-white/70 text-ink backdrop-blur-xl transition-all dark:bg-night-raised dark:text-night-ink">
            <svg class="h-4 w-4 dark:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
            <svg class="hidden h-4 w-4 dark:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
        </button>
    </div>
</header>

<div id="toast-container" class="pointer-events-none fixed left-1/2 top-4 z-[100] flex w-[calc(100%_-_2rem)] max-w-sm -translate-x-1/2 flex-col px-2" role="status" aria-live="polite" aria-atomic="true"></div>

{{-- Single centered column. flex-1 + justify-center vertically centers the card in
     whatever space the top bar leaves, and py-* keeps it scrollable on short viewports. --}}
<main id="main-content" class="flex flex-1 items-center justify-center px-4 py-10 sm:px-6">
    <div class="reveal w-full max-w-md">
        @if (session('status'))
            <div class="mb-6 rounded-2xl border-brand/20 bg-azure-50 px-4 py-3 text-sm font-medium text-brand-strong" role="status">
                {{ session('status') }}
            </div>
        @endif

        @yield('card')
    </div>
</main>

<noscript>
    <div class="fixed inset-x-0 bottom-0 z-[200] bg-danger px-4 py-3 text-center text-sm font-medium text-white">
        {{ __('common.js_required') }}
    </div>
</noscript>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        requestAnimationFrame(() => {
            document.body.classList.add('ready');
            document.querySelectorAll('.reveal').forEach((el) => el.classList.add('is-visible'));
        });

        document.getElementById('theme-toggle')?.addEventListener('click', (event) => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            event.currentTarget.setAttribute('aria-pressed', String(isDark));
        });

        document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.togglePassword);
                if (!input) return;
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.setAttribute('aria-pressed', String(show));
                btn.setAttribute('aria-label', show ? @json(__('auth.login.hide_password')) : @json(__('auth.login.show_password')));
                btn.querySelector('.icon-eye')?.classList.toggle('hidden', show);
                btn.querySelector('.icon-eye-off')?.classList.toggle('hidden', !show);
            });
        });

        document.querySelectorAll('a[data-transition]').forEach((link) => {
            link.addEventListener('click', (event) => {
                const href = link.getAttribute('href');
                if (!href || link.target === '_blank') return;
                event.preventDefault();
                document.body.classList.add('page-exit');
                setTimeout(() => { window.location.href = href; }, 200);
            });
        });
    });

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function markInvalid(input, message) {
        input.classList.add('!border-danger', 'field-shake');
        input.setAttribute('aria-invalid', 'true');
        setTimeout(() => input.classList.remove('field-shake'), 400);
        const errorEl = document.getElementById(input.id + '-error');
        if (errorEl) errorEl.textContent = message;
    }

    function clearInvalid(input) {
        input.classList.remove('!border-danger');
        input.setAttribute('aria-invalid', 'false');
        const errorEl = document.getElementById(input.id + '-error');
        if (errorEl) errorEl.textContent = '';
    }

    function applyServerErrors(form, errors) {
        Object.entries(errors).forEach(([field, messages]) => {
            const input = form.querySelector('#' + field);
            if (input) markInvalid(input, messages[0]);
        });
    }

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    async function ensureCsrfCookie() {
        if (getCookie('XSRF-TOKEN')) return;
        try {
            await fetch('/', { credentials: 'same-origin' });
        } catch (error) {
            // Ignored — the follow-up POST will surface any resulting CSRF failure as a normal error toast.
        }
    }

    async function postForm(action, formData) {
        await ensureCsrfCookie();
        return fetch(action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') || '',
            },
            body: formData,
        });
    }

    async function handleAuthSubmit(event, { form, validator, onSuccess }) {
        event.preventDefault();
        const error = validator ? validator() : null;
        if (error) {
            showToast(error, 'error');
            return;
        }

        const button = form.querySelector('button[type="submit"]');
        const label = button.querySelector('.btn-label');
        const originalText = label.textContent;
        button.disabled = true;
        label.innerHTML = '<span class="spinner" aria-hidden="true"></span><span class="sr-only">' + @json(__('common.loading')) + '</span>';

        try {
            const response = await postForm(form.action, new FormData(form));
            if (response.ok) {
                if (onSuccess) {
                    await onSuccess(response);
                } else {
                    window.location.href = response.url;
                    return;
                }
            } else if (response.status === 422) {
                const data = await response.json();
                applyServerErrors(form, data.errors || {});
                showToast(data.message || @json(__('common.error_generic')), 'error');
            } else {
                showToast(@json(__('common.error_generic')), 'error');
            }
        } catch (error) {
            showToast(@json(__('common.error_network')), 'error');
        }

        button.disabled = false;
        label.textContent = originalText;
    }

    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;
        const colors = {
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
    }
</script>
@include('partials.i18n-bundle', ['i18nNamespaces' => ['auth', 'nav', 'common']])
@stack('scripts')
</body>
</html>
