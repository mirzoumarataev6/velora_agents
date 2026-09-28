import { usePage } from '@inertiajs/vue3';

/**
 * Translation for Vue components — the counterpart of Blade's __().
 *
 * Registered as the global `$t`, so a template writes `$t('auth.sign_in')`.
 *
 * It reads `usePage().props` on every call rather than closing over the
 * messages. That is what makes a locale switch work without a reload: the
 * switch is an Inertia visit that replaces the `translations` prop, the page
 * props are reactive, and every $t in the tree re-evaluates against the new
 * language. Nothing has to be invalidated, and there is no second copy of the
 * strings for another locale sitting in memory.
 *
 * @param {string} key      dot path, e.g. 'auth.login.title'
 * @param {object} replace  :placeholder values, as Laravel's __() takes
 */
export function translate(key, replace = {}) {
    const messages = usePage().props.translations ?? {};

    let value = messages;
    for (const segment of String(key).split('.')) {
        if (value === null || typeof value !== 'object') {
            value = null;
            break;
        }
        value = value[segment];
    }

    // A few translation entries are LISTS rather than strings — the ratings
    // card's "how this works" steps, for instance. Hand those back as they are
    // so a component can iterate them; only a string gets placeholders.
    if (Array.isArray(value)) {
        return value;
    }

    // Fall back to the key itself. A missing translation should be visible as
    // the key rather than as an empty element, which is indistinguishable from
    // a layout bug and takes far longer to track down.
    if (typeof value !== 'string') {
        return key;
    }

    return Object.entries(replace).reduce(
        (text, [token, replacement]) => text.replaceAll(`:${token}`, String(replacement)),
        value
    );
}
