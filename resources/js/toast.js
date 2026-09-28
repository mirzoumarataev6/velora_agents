import { reactive } from 'vue';

/**
 * Transient messages.
 *
 * A module-level reactive array rather than provide//inject or a store package:
 * a toast can be raised from anywhere — a component, an Inertia callback, a
 * plain function with no component instance — and threading an injection
 * through all of those is exactly the friction that ends with someone reaching
 * for `alert()`.
 *
 * Toasts are for things that DID happen ("saved", "link sent"). Anything the
 * reader has to act on belongs on the page, where it stays: a toast that
 * disappears after four seconds is the wrong place for an error somebody needs
 * to read twice.
 */
const state = reactive({ items: [] });

let nextId = 0;

export function useToasts() {
    return state;
}

export function toast(message, { type = 'info', timeout = 4000 } = {}) {
    const id = ++nextId;
    state.items.push({ id, message, type });

    // A danger toast stays until it is dismissed. Everything else times out.
    if (timeout > 0 && type !== 'danger') {
        setTimeout(() => dismiss(id), timeout);
    }

    return id;
}

export function dismiss(id) {
    const index = state.items.findIndex((item) => item.id === id);
    if (index !== -1) state.items.splice(index, 1);
}
