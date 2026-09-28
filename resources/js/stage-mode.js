/**
 * Theme and stage mode, for the Inertia half of the product.
 *
 * Both settings are applied BEFORE first paint by inline scripts in the root
 * template (resources/views/app.blade.php and partials/stage-mode.blade.php) —
 * they change how the first frame is drawn, and Vue does not exist yet when
 * that frame is painted. What lives here is only the toggling and the
 * persistence, which is all that is left once the pre-paint work is done.
 *
 * Deliberately plain functions on the document element rather than a Pinia
 * store or a reactive singleton: the state already lives in the DOM (the `dark`
 * class and the `data-mode` attribute), the pre-paint script is the one that
 * puts it there, and a second copy in JavaScript could only ever disagree with
 * it.
 */

const root = () => document.documentElement;

const remember = (key, value) => {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        /* private mode — the choice just won't outlive the tab */
    }
};

/** Is the dark theme currently on? */
export function isDark() {
    return root().classList.contains('dark');
}

/**
 * Flip the theme.
 *
 * `themeUrl` is the per-account endpoint, present only when someone is signed
 * in — a guest on the auth pages has no account to write to, and localStorage
 * is the only record of what they picked. Fire-and-forget: localStorage has
 * already made the change stick locally, so a failed request costs nothing this
 * session.
 */
export function toggleTheme(themeUrl = null) {
    const dark = root().classList.toggle('dark');
    const theme = dark ? 'dark' : 'light';

    remember('theme', theme);

    if (themeUrl) {
        fetch(themeUrl, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ theme }),
            keepalive: true,
        }).catch(() => {});
    }

    // Charts read their colours from CSS custom properties, so anything already
    // drawn has to be told to re-read them.
    document.dispatchEvent(new CustomEvent('theme:changed', { detail: { theme } }));

    return dark;
}

/** Is stage mode currently on? */
export function isStage() {
    return root().getAttribute('data-mode') === 'stage';
}

/**
 * Flip stage mode.
 *
 * localStorage only, never the account: stage mode is a property of the ROOM,
 * not of the person. The same owner wants it on for the projector and off at
 * their desk twenty minutes later, so following them between devices would be
 * exactly wrong.
 */
export function toggleStage() {
    const on = !isStage();

    if (on) {
        root().setAttribute('data-mode', 'stage');
    } else {
        root().removeAttribute('data-mode');
    }

    remember('mode', on ? 'stage' : 'normal');
    document.dispatchEvent(new CustomEvent('mode:changed', { detail: { stage: on } }));

    return on;
}

/**
 * Keep both settings applied across Inertia navigations.
 *
 * Inertia swaps the component tree but never reloads the document, so <html>
 * keeps whatever the pre-paint script put on it and neither setting can be lost
 * on a visit. This exists for the one case that is not a normal visit: a
 * full-page reload triggered by an asset-version change, where the new document
 * is painted by the pre-paint script again and the two must not disagree.
 *
 * It re-asserts from storage on `navigate` rather than trusting the DOM, so a
 * browser that restored a cached document with stale attributes is corrected.
 */
export function startStageMode(router) {
    const apply = () => {
        let mode = null;
        let theme = null;

        try {
            mode = localStorage.getItem('mode');
            theme = localStorage.getItem('theme');
        } catch (e) {
            return;
        }

        if (mode === 'stage') {
            root().setAttribute('data-mode', 'stage');
        } else if (mode === 'normal') {
            root().removeAttribute('data-mode');
        }

        if (theme === 'dark' || theme === 'light') {
            root().classList.toggle('dark', theme === 'dark');
        }
    };

    apply();
    router.on('navigate', apply);
}
