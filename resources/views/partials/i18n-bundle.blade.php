@php
    /**
     * Embeds the translation strings for the current page — only the namespaces
     * it actually uses, across all supported locales — plus a small vanilla
     * engine that swaps every [data-i18n*] element in place when the locale
     * switcher is used. First render is server-side in the requested locale
     * (SEO, direct links); the client swap avoids a full reload afterwards.
     *
     * Expects: $i18nNamespaces (string[]). Optional: $i18nReload (bool) — when
     * true (dashboard), the switch also navigates so deep, untagged content
     * re-renders server-side; the instant swap gives immediate feedback first.
     */
    $__locales = \App\Http\Middleware\SetLocale::SUPPORTED_LOCALES;
    $__namespaces = $i18nNamespaces ?? [];
    $__messages = [];
    foreach ($__locales as $__loc) {
        foreach ($__namespaces as $__ns) {
            $__messages[$__loc][$__ns] = trans($__ns, [], $__loc);
        }
    }
@endphp
<script>
    window.__i18n = {
        locales: @json($__locales),
        current: @json(app()->getLocale()),
        reload: @json((bool) ($i18nReload ?? false)),
        messages: @json($__messages),
    };

    (function () {
        const i18n = window.__i18n;

        const resolve = (locale, path) => {
            const parts = String(path).split('.');
            let node = i18n.messages[locale];
            for (const part of parts) {
                if (node == null || typeof node !== 'object') return null;
                node = node[part];
            }
            return typeof node === 'string' ? node : null;
        };

        const applyLocale = (locale) => {
            document.querySelectorAll('[data-i18n]').forEach((el) => {
                const value = resolve(locale, el.getAttribute('data-i18n'));
                if (value !== null) el.textContent = value;
            });
            document.querySelectorAll('[data-i18n-ph]').forEach((el) => {
                const value = resolve(locale, el.getAttribute('data-i18n-ph'));
                if (value !== null) el.setAttribute('placeholder', value);
            });
            document.querySelectorAll('[data-i18n-al]').forEach((el) => {
                const value = resolve(locale, el.getAttribute('data-i18n-al'));
                if (value !== null) el.setAttribute('aria-label', value);
            });
            i18n.current = locale;
            document.documentElement.setAttribute('lang', locale);
            // For anything that renders from the bundle itself rather than from
            // a [data-i18n] attribute (the hero typewriter and its space
            // reservation) and has to re-derive its strings after the swap.
            document.dispatchEvent(new CustomEvent('i18n:changed', { detail: { locale: locale } }));
        };

        const swapUrlLocale = (locale) => {
            const segments = window.location.pathname.split('/');
            // segments[0] is '' (leading slash); segments[1] is the locale prefix.
            if (segments.length > 1 && i18n.locales.includes(segments[1])) {
                segments[1] = locale;
            }
            const newPath = segments.join('/') + window.location.search + window.location.hash;
            window.history.replaceState({}, '', newPath);
            return newPath;
        };

        /**
         * Put the locale on the navigation itself, for pages whose path has no
         * locale segment to carry it (the dashboard). SetLocale reads it and
         * applies it to that very request, so the page that comes back is
         * already switched.
         */
        const withLangParam = (path, locale) => {
            const url = new URL(path, window.location.origin);
            url.searchParams.set('lang', locale);
            return url.pathname + url.search + url.hash;
        };

        const readCookie = (name) => {
            const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
            return match ? decodeURIComponent(match[1]) : null;
        };

        // Fire-and-forget: persist the choice (user record when authenticated,
        // session otherwise). Never blocks the UI; failures are inconsequential
        // since the URL prefix already reflects the chosen locale.
        const persist = (locale) => {
            try {
                const body = new FormData();
                body.append('locale', locale);
                const token = readCookie('XSRF-TOKEN');
                fetch('/locale', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: token ? { 'X-XSRF-TOKEN': token, Accept: 'application/json' } : { Accept: 'application/json' },
                    body,
                    keepalive: true,
                }).catch(() => {});
            } catch (e) { /* ignore */ }
        };

        window.switchLocale = (locale) => {
            if (!i18n.locales.includes(locale) || locale === i18n.current) return;
            applyLocale(locale);
            const newPath = swapUrlLocale(locale);
            markActiveSwitcher(locale);

            // Dashboard: deep, untagged content (form partials) still needs a
            // server re-render. The chrome already swapped, so this reads as a
            // fast refresh rather than a cold navigation.
            //
            // The locale rides along IN the navigation rather than going out as
            // a separate background POST. The old code fired both in the same
            // tick and they raced: the navigation normally reached the server
            // first, so the server re-rendered in the locale it still had on
            // record and undid the swap — which is why switching appeared to
            // need a second click. One request cannot race itself.
            if (i18n.reload) {
                window.location.assign(withLangParam(newPath, locale));
                return true;
            }

            // Landing and auth pages: the path already names the locale and the
            // swap is complete, so nothing needs to navigate. Record the choice
            // in the background and stay put.
            persist(locale);
            return true;
        };

        const markActiveSwitcher = (locale) => {
            document.querySelectorAll('[data-locale]').forEach((el) => {
                el.setAttribute('aria-current', el.getAttribute('data-locale') === locale ? 'true' : 'false');
            });
        };

        // ?lang= has done its job by the time this runs — the server already
        // rendered in that locale and wrote it to the session. Drop it from the
        // address bar so the URL stays clean to copy, share and bookmark.
        (() => {
            const url = new URL(window.location.href);
            if (!url.searchParams.has('lang')) return;
            url.searchParams.delete('lang');
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        })();

        // Intercept switcher activations. Elements opt in with [data-locale];
        // their href/form remains the no-JS fallback.
        //
        // Guarded: on the dashboard this whole block is NOT behind
        // data-turbo-permanent (window.__i18n has to be rebuilt with each
        // page's own namespaces), so Turbo re-executes this inline script in
        // full on every navigation — including this addEventListener, which
        // has no element lifecycle to unbind it. Without the guard, every
        // visit stacked one more document-wide click listener on top of the
        // last, forever (measured: +1 per navigation, never released). The
        // flag survives exactly as long as the listener needs to: it lives on
        // `window`, which Turbo keeps alive across visits and a real page
        // load (auth/landing, where this script only ever runs once anyway)
        // starts fresh.
        if (!window.__i18nSwitcherBound) {
            window.__i18nSwitcherBound = true;
            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-locale]');
                if (!trigger) return;
                const locale = trigger.getAttribute('data-locale');
                if (!window.__i18n.locales.includes(locale)) return;
                event.preventDefault();
                window.switchLocale(locale);
                // Close any open <details> switcher.
                const details = trigger.closest('details');
                if (details) details.removeAttribute('open');
            });
        }
    })();
</script>
