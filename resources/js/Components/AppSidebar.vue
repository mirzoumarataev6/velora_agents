<script setup>
/**
 * The navy rail.
 *
 * Dark in BOTH themes, which is why it uses the `sidebar-*` tokens rather than
 * the surface ones: it is the product's fixed anchor, and a sidebar that
 * inverts with the theme makes the app look like two different products.
 *
 * Collapsible to icons. The choice is persisted, and re-applied by the root
 * template before first paint, so a rail somebody collapsed does not flash open
 * on every navigation.
 *
 * Labels stay in the DOM when collapsed (hidden with a class, not v-if) so the
 * collapsed rail still has accessible names, and every item carries an
 * aria-label for the same reason.
 */
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    IconChevronLeft,
    IconClipboardList,
    IconLayoutDashboard,
    IconMessageCircle,
    IconSettings,
    IconUsers,
} from '@tabler/icons-vue';
import VeloraMark from './VeloraMark.vue';

const page = usePage();
const collapsed = ref(false);

onMounted(() => {
    // The pre-paint script is the source of truth; read it back rather than
    // re-reading storage, so the two can never disagree on the first frame.
    collapsed.value = document.documentElement.classList.contains('rail-collapsed');
});

function toggle() {
    collapsed.value = !collapsed.value;
    document.documentElement.classList.toggle('rail-collapsed', collapsed.value);

    try {
        localStorage.setItem('sidebar:collapsed', collapsed.value ? '1' : '0');
    } catch (e) {
        /* private mode — the choice just won't outlive the tab */
    }
}

const items = computed(() => [
    { key: 'dashboard', route: 'dashboard', label: 'agents.nav.dashboard', icon: IconLayoutDashboard, match: 'dashboard' },
    { key: 'agents', route: 'agents.index', label: 'agents.nav.agents', icon: IconUsers, match: 'agents.' },
    { key: 'conversations', route: 'conversations.index', label: 'agents.nav.conversations', icon: IconMessageCircle, match: 'conversations.' },
    { key: 'leads', route: 'leads.index', label: 'agents.nav.leads', icon: IconClipboardList, match: 'leads.' },
    { key: 'settings', route: 'settings.index', label: 'agents.nav.settings', icon: IconSettings, match: 'settings.' },
]);

/**
 * Ziggy's current() does the matching, so "is this the active screen" is
 * decided by the route the server actually resolved rather than by comparing
 * URL strings — which breaks the moment a query string or a locale prefix
 * appears.
 */
function isActive(item) {
    return route().current(item.match) || route().current(item.match.replace(/\.$/, ''));
}

defineExpose({ collapsed });
</script>

<template>
    <aside
        data-sidebar
        class="sticky top-0 z-30 flex h-screen shrink-0 flex-col border-r border-sidebar-border bg-sidebar transition-[width] duration-base ease-out"
        :class="collapsed ? 'w-[4.75rem]' : 'w-64'"
    >
        <div class="flex h-16 shrink-0 items-center px-4" :class="collapsed && 'justify-center px-0'">
            <Link
                :href="route('dashboard')"
                class="flex items-center gap-2.5 rounded-md"
                :aria-label="$t('common.brand')"
            >
                <VeloraMark class="h-7 w-auto shrink-0" />
                <span v-show="!collapsed" class="text-body font-semibold text-sidebar-text-strong">
                    {{ $t('common.brand') }}
                </span>
            </Link>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-2" :aria-label="$t('agents.nav.dashboard')">
            <Link
                v-for="item in items"
                :key="item.key"
                :href="route(item.route)"
                :aria-label="$t(item.label)"
                :aria-current="isActive(item) ? 'page' : undefined"
                class="flex items-center gap-3 rounded-md px-3 py-2.5 text-body-sm font-medium transition-colors duration-fast"
                :class="[
                    collapsed && 'justify-center px-0',
                    isActive(item)
                        ? 'bg-sidebar-active text-white'
                        : 'text-sidebar-text hover:bg-sidebar-hover hover:text-sidebar-text-strong',
                ]"
            >
                <component :is="item.icon" :size="20" :stroke-width="1.75" class="shrink-0" aria-hidden="true" />
                <span v-show="!collapsed" class="truncate">{{ $t(item.label) }}</span>
            </Link>
        </nav>

        <div class="shrink-0 border-t border-sidebar-border p-3">
            <button
                type="button"
                :aria-expanded="!collapsed"
                :aria-label="collapsed ? $t('agents.nav.expand_sidebar') : $t('agents.nav.collapse_sidebar')"
                :title="collapsed ? $t('agents.nav.expand_sidebar') : $t('agents.nav.collapse_sidebar')"
                class="flex w-full items-center justify-center rounded-md px-3 py-2 text-sidebar-text transition-colors duration-fast hover:bg-sidebar-hover hover:text-sidebar-text-strong"
                @click="toggle"
            >
                <IconChevronLeft
                    :size="18"
                    :stroke-width="2"
                    class="transition-transform duration-base"
                    :class="collapsed && 'rotate-180'"
                    aria-hidden="true"
                />
            </button>
        </div>
    </aside>
</template>
