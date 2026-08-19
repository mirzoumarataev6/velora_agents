/*
 * Velora service worker — makes repeat visits instant.
 *
 * Strategy:
 *   - Fingerprinted build assets (/build/*: CSS, JS, fonts) → cache-first.
 *     Their names change when content changes, so cached copies never go stale.
 *   - Brand images + favicons → cache-first (small, effectively immutable).
 *   - Landing pages (/, /ru, /en, /tg) → stale-while-revalidate: render from
 *     cache with zero network wait, refresh the copy in the background.
 *   - EVERYTHING else (dashboard, auth, webhooks, any non-GET) → untouched.
 *     Authenticated HTML and CSRF flows never enter this cache.
 *
 * Bump VERSION to invalidate all caches after a deploy. `activate` deletes every
 * cache whose name doesn't start with the current VERSION, so a returning
 * visitor cannot be served a stale landing from a previous design.
 */
// v10: header redesigned (grouped nav, dropdown language menu, mobile drawer).
// Landing pages are stale-while-revalidate, so without this bump a returning
// visitor would be served the previous header from cache on their next visit.
// v14: hero typewriter and chat demo reserve their final box up front, so
// neither jitters while typing. Cached landings still carry the old markup.
// v15: stats count up on first view, and the manifesto is a new pull-quote —
// new markup and new strings, so a cached landing must not survive.
// v16: the i18n engine changed how it switches locale (the dashboard now
// navigates with ?lang= instead of racing a background POST) and the manifesto
// copy changed again. The engine is inlined in the page, not in a fingerprinted
// build asset, so a cached landing would keep serving the old switcher.
// v17: the dashboard gained a real Conversations section (the sidebar item was
// a "СКОРО" placeholder), so the cached shell must not keep serving a nav that
// links nowhere.
// v18: Settings is a real section too, and the sidebar's theme toggle now
// persists to the account — a cached shell would serve the old "СКОРО" nav and
// the toggle that only ever wrote localStorage.
// v19: inbox and leads UX pass — leads gained a search field, the nav collapses
// to an icon rail while a conversation is open, and the inbox panes are
// full-height and drag-resizable. The cached shell carries the old chrome.
// v20: the leads search filters live as you type, so the cached page must not
// keep serving the submit-to-search version (whose markup has no x-ref the
// script writes into).
// v21: an agent's Диалоги tab is the real inbox now, not a placeholder — a
// cached agent page would still show "появятся здесь".
// v22: inbox filters collapsed from two rows to one (both the global page and
// the agent tab), and the panes grew into the space that freed up.
// v23: inbox search now looks inside message bodies and jumps to the matched
// message. A cached shell would still ship the old name-only search field.
// v24: the inbox search field showed two × buttons (the browser's native search
// cancel control alongside ours); the native one is suppressed now.
// v25: conversations can now be flagged "needs human" automatically, and the
// notification bell carries escalations alongside leads — a cached shell would
// ship a bell that can't render them.
// v26: message bubbles restyled (pre-line instead of pre-wrap, tighter measure)
// and the sidebar gained a persistent collapse toggle whose saved state is
// applied pre-paint — a cached shell would ship neither.
// v27: the newline-preserving rule moved off the bubble container onto its
// inner span, removing the blank line the markup's own indentation was
// rendering above every message.
// v28: the agent wizard is four steps now, with a new "Инструкции агента" step
// carrying the rules field — a cached shell would serve the 3-step form and
// silently drop rules_instructions on submit.
// v29: Telegram voice notes are transcribed and answered, and the inbox marks
// voice-originated turns — a cached shell would render transcripts with no
// indication the customer spoke them.
// v30: the sandbox gained a microphone button — a cached agent page would ship
// a playground with no recorder and no transcribe endpoint wired.
const VERSION = 'velora-sw-v30';
const STATIC_CACHE = VERSION + '-static';
const PAGE_CACHE = VERSION + '-pages';

const LANDING_PATHS = ['/', '/ru', '/en', '/tg'];

const STATIC_PREFIXES = ['/build/', '/images/brand/', '/images/landing/'];
const STATIC_FILES = ['/favicon.ico', '/site.webmanifest'];

// Read a Vite-style manifest and return the built asset URLs worth precaching.
async function filesFromManifest(url, pick) {
    try {
        const res = await fetch(url, { cache: 'no-cache' });
        if (!res.ok) return [];
        return pick(await res.json());
    } catch (e) {
        return [];
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            try {
                const cache = await caches.open(STATIC_CACHE);

                // Entry chunks: app CSS + JS.
                const build = await filesFromManifest('/build/manifest.json', (manifest) =>
                    Object.values(manifest)
                        .map((entry) => entry && entry.file)
                        .filter((f) => typeof f === 'string' && /\.(css|js)$/.test(f))
                        .map((f) => '/build/' + f)
                );

                // Fonts live in their own manifest, not in manifest.json. Only
                // woff2 is precached — the .woff twins exist purely as a legacy
                // fallback and would double the download for no modern browser.
                const fonts = await filesFromManifest('/build/fonts-manifest.json', (manifest) => {
                    const out = [];
                    if (manifest.style && manifest.style.file) out.push('/build/' + manifest.style.file);
                    (manifest.preloads || []).forEach((p) => {
                        if (p && typeof p.file === 'string' && p.file.endsWith('.woff2')) {
                            out.push('/build/' + p.file);
                        }
                    });
                    return out;
                });

                const images = ['/images/brand/gen/mark.webp', '/images/brand/gen/mark.png'];

                await Promise.allSettled(
                    [...build, ...fonts, ...images, ...STATIC_FILES].map((f) => cache.add(f))
                );
            } catch (e) {
                // Precaching is best-effort; runtime caching still applies.
            }
            await self.skipWaiting();
        })()
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(names.filter((n) => !n.startsWith(VERSION)).map((n) => caches.delete(n)));
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    const isStatic =
        STATIC_PREFIXES.some((p) => url.pathname.startsWith(p)) || STATIC_FILES.includes(url.pathname);

    if (isStatic) {
        // Cache-first: fingerprinted/immutable content.
        event.respondWith(
            (async () => {
                const cached = await caches.match(request);
                if (cached) return cached;
                const response = await fetch(request);
                if (response.ok) {
                    const cache = await caches.open(STATIC_CACHE);
                    cache.put(request, response.clone());
                }
                return response;
            })()
        );
        return;
    }

    // Landing pages only — never authenticated HTML.
    if (request.mode === 'navigate' && LANDING_PATHS.includes(url.pathname)) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(PAGE_CACHE);
                const cached = await cache.match(request);
                const network = fetch(request)
                    .then((response) => {
                        // A redirected response (e.g. "/" → "/ru") must not be
                        // cached for a navigation: replaying it later throws
                        // "Response served by service worker has redirected".
                        if (response.ok && !response.redirected) {
                            cache.put(request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => cached);
                // Stale-while-revalidate: serve the cached copy instantly.
                return cached || network;
            })()
        );
    }
    // Everything else falls through to the network untouched.
});
