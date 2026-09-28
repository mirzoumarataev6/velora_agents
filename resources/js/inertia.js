import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { ZiggyVue, route as ziggyRoute } from 'ziggy-js';
import { translate } from './i18n';
import { startStageMode } from './stage-mode';

/**
 * The Vue entry point, loaded only by the Inertia root template.
 *
 * resources/js/app.js (Alpine) is still the entry for every Blade page that has
 * not been migrated yet. Keeping the two separate means a Blade page does not
 * download Vue and an Inertia page does not download Alpine — which matters
 * while both exist, and costs nothing once they do not.
 */

/**
 * Publish the route list Ziggy needs, from the props the server shares.
 *
 * THIS IS LOAD-BEARING AND WAS THE CAUSE OF A BLANK-SCREEN BUG.
 *
 * Ziggy resolves its config per call, in this order: an explicit argument, a
 * bare `Ziggy` binding, then `globalThis.Ziggy`. Installing ZiggyVue without a
 * config left all three empty, so the very first `route()` — and components
 * call it during setup, for a <Link href> — threw
 * "can't access property 'routes', undefined" and Vue mounted nothing at all.
 *
 * Publishing it on `globalThis` rather than passing it to `.use(ZiggyVue, …)`
 * is deliberate: the plugin would capture the object it was given at boot, and
 * `location` changes on every Inertia visit. A global that is refreshed per
 * response means `route().current()` — which the sidebar uses to decide the
 * active item — is answered against the page you are actually on.
 *
 * It also has to be set BEFORE `app.mount()`, because setup() runs during mount
 * and that is exactly where the failing call was.
 */
function publishRoutes(page) {
    if (page?.props?.ziggy) {
        globalThis.Ziggy = page.props.ziggy;
    }
}

// Keep it current across visits. Both events, deliberately: `success` carries
// the new page as soon as the response is processed (before the component swap,
// so a route() call in the incoming page's setup already sees it), and
// `navigate` also covers history traversal, which does not go through success.
router.on('success', (event) => publishRoutes(event.detail.page));
router.on('navigate', (event) => publishRoutes(event.detail.page));

createInertiaApp({
    // Every page component, resolved eagerly.
    //
    // Eager, not lazy: this is a dashboard behind a login, so there is no cold
    // first-visit cost worth optimising, and lazy chunks would mean a network
    // round trip on the first visit to each screen — which on a projector, on
    // conference wifi, is exactly where a stall is most visible.
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Inertia page not found: ./Pages/${name}.vue`);
        }

        return page;
    },

    setup({ el, App, props, plugin }) {
        // Before anything else — see publishRoutes above.
        publishRoutes(props.initialPage);

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            // No config argument: the plugin then resolves globalThis.Ziggy on
            // every call rather than closing over a snapshot from boot.
            .use(ZiggyVue);

        /*
         * `route` as a real global, not only a template helper.
         *
         * ZiggyVue installs it on `app.config.globalProperties`, which reaches
         * templates and nothing else. Plenty of our components call route()
         * from <script setup> — every router.visit, every form action — and
         * there it is an ordinary free variable that has to resolve globally.
         */
        globalThis.route = ziggyRoute;

        /*
         * Translation, as a global so a component can write $t('auth.sign_in')
         * the way a Blade view writes __('auth.sign_in').
         *
         * It reads the CURRENT page's props on every call rather than closing
         * over them, so a locale switch — which is an Inertia visit that
         * replaces the translations prop — re-renders into the new language
         * with no reload and nothing to invalidate.
         */
        app.config.globalProperties.$t = translate;

        app.mount(el);
    },

    // The default is the primary; on a light canvas that reads as the product's
    // own colour rather than a generic browser affordance.
    progress: {
        color: '#1E5EFF',
        showSpinner: false,
    },
});

startStageMode(router);
