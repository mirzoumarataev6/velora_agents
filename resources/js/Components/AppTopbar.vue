<script setup>
/**
 * The light topbar.
 *
 * Everything here is about HOW the product is being looked at rather than what
 * is in it — language, theme, stage mode — plus the two things that are about
 * the person: notifications and the account menu. Navigation lives in the rail;
 * nothing in this bar moves you between screens except the user menu.
 *
 * Separated from the content by a 1px border rather than by a shadow, per the
 * projector rules: a soft shadow is invisible in a lit hall, so the surface
 * boundary has to be a real line.
 */
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { IconBell, IconLogout, IconSearch, IconSettings } from '@tabler/icons-vue';
import LocaleSwitcher from './LocaleSwitcher.vue';
import StageToggle from './StageToggle.vue';
import ThemeToggle from './ThemeToggle.vue';

defineProps({
    /** Shown at the left of the bar, naming the screen. */
    title: { type: String, default: null },
});

const page = usePage();
const menuOpen = ref(false);

function signOut() {
    router.post(route('logout'));
}
</script>

<template>
    <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-border bg-surface px-4 sm:px-6">
        <h1 v-if="title" class="truncate text-h3 text-text">{{ title }}</h1>

        <!-- Search is a placeholder shell until the pages it searches exist;
             it is in the bar now so the layout is not re-cut around it later. -->
        <div class="relative ml-auto hidden w-full max-w-xs lg:block">
            <IconSearch
                :size="17"
                :stroke-width="1.75"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
                aria-hidden="true"
            />
            <input
                type="search"
                :placeholder="$t('common.loading')"
                :aria-label="$t('common.loading')"
                class="field w-full py-2 pl-9 pr-3 text-body-sm"
            />
        </div>

        <div class="flex items-center gap-2" :class="!title && 'ml-auto lg:ml-0'">
            <LocaleSwitcher class="hidden sm:flex" />
            <ThemeToggle :theme-url="route('settings.theme')" />
            <StageToggle />

            <button
                type="button"
                :aria-label="$t('agents.nav.leads')"
                class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-border text-muted transition-colors duration-fast hover:bg-surface-2 hover:text-text"
            >
                <IconBell :size="18" :stroke-width="1.75" />
            </button>

            <div class="relative">
                <button
                    type="button"
                    :aria-expanded="menuOpen"
                    aria-haspopup="menu"
                    class="flex items-center gap-2 rounded-lg border border-border py-1 pl-1 pr-2 transition-colors duration-fast hover:bg-surface-2"
                    @click="menuOpen = !menuOpen"
                >
                    <span
                        class="flex h-7 w-7 items-center justify-center rounded-md bg-primary-tint text-caption font-semibold text-primary-text"
                        aria-hidden="true"
                    >
                        {{ page.props.auth.user?.initials }}
                    </span>
                    <span class="hidden max-w-32 truncate text-body-sm font-medium text-text sm:block">
                        {{ page.props.auth.user?.name }}
                    </span>
                </button>

                <!-- Click-away is a sibling overlay rather than a document
                     listener: it cannot be missed by a stopped propagation, and
                     it keeps the menu's own clicks from closing it early. -->
                <div v-if="menuOpen" class="fixed inset-0 z-30" @click="menuOpen = false" />

                <div
                    v-if="menuOpen"
                    role="menu"
                    class="absolute right-0 z-40 mt-2 w-56 overflow-hidden rounded-xl border border-border bg-surface shadow-e3"
                >
                    <div class="border-b border-border px-4 py-3">
                        <p class="truncate text-body-sm font-medium text-text">{{ page.props.auth.user?.name }}</p>
                        <p class="truncate text-caption text-muted">{{ page.props.auth.user?.email }}</p>
                    </div>

                    <Link
                        :href="route('settings.index')"
                        role="menuitem"
                        class="flex items-center gap-2.5 px-4 py-2.5 text-body-sm text-text transition-colors duration-fast hover:bg-surface-2"
                        @click="menuOpen = false"
                    >
                        <IconSettings :size="17" :stroke-width="1.75" class="text-muted" aria-hidden="true" />
                        {{ $t('agents.nav.settings') }}
                    </Link>

                    <button
                        type="button"
                        role="menuitem"
                        class="flex w-full items-center gap-2.5 border-t border-border px-4 py-2.5 text-left text-body-sm text-text transition-colors duration-fast hover:bg-surface-2"
                        @click="signOut"
                    >
                        <IconLogout :size="17" :stroke-width="1.75" class="text-muted" aria-hidden="true" />
                        {{ $t('agents.nav.logout') }}
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>
