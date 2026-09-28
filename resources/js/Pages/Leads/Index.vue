<script setup>
/**
 * Заявки — the orders and price requests the assistants collected.
 *
 * Two tabs, and the tab is the strict filter: a price request must never
 * surface in the orders list, whatever else is narrowing it. The Торг tab
 * carries an unfiltered count badge, because a pending decision that the
 * current filter happens to hide is still a customer waiting.
 *
 * Search is an Inertia PARTIAL reload — `only: ['leads']` — so typing re-fetches
 * the result list and nothing else. That replaces the old `?fragment=1` route
 * that rendered a chrome-less block of HTML, and it is also what keeps the
 * unread bell from being re-marked on every keystroke (see LeadController).
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { IconInbox, IconSearch } from '@tabler/icons-vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import Badge from '../../Components/ui/Badge.vue';
import Button from '../../Components/ui/Button.vue';
import EmptyState from '../../Components/ui/EmptyState.vue';
import PageHeader from '../../Components/ui/PageHeader.vue';
import Select from '../../Components/ui/Select.vue';
import LeadCard from '../../Components/leads/LeadCard.vue';
import { translate } from '../../i18n';

const props = defineProps({
    leads: { type: Object, required: true },
    activeView: { type: String, required: true },
    activeStatus: { type: String, default: null },
    activeAgent: { type: [String, Number], default: null },
    activeSearch: { type: String, default: null },
    statusOptions: { type: Array, required: true },
    agents: { type: Array, default: () => [] },
    pendingHaggleCount: { type: Number, default: 0 },
});

const search = ref(props.activeSearch ?? '');

const isFiltered = computed(
    () => Boolean(props.activeStatus || props.activeAgent || props.activeSearch)
);

const statusChoices = computed(() => [
    { value: '', label: translate('leads.filters.all') },
    ...props.statusOptions.map((status) => ({ value: status, label: translate(`leads.filters.${status}`) })),
]);

const agentChoices = computed(() => [
    { value: '', label: translate('leads.index.all_agents') },
    ...props.agents.map((agent) => ({ value: String(agent.id), label: agent.name })),
]);

/**
 * One place builds every navigation on this page, so a filter can never drop
 * another one by forgetting to carry it.
 */
function visit(params, { partial = false } = {}) {
    router.get(
        route('leads.index'),
        {
            view: props.activeView,
            status: props.activeStatus ?? undefined,
            agent: props.activeAgent ?? undefined,
            q: props.activeSearch ?? undefined,
            ...params,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            // Typing re-fetches only the results; changing a tab re-renders the
            // page, because the tab changes the status options too.
            ...(partial ? { only: ['leads', 'activeSearch'] } : {}),
        }
    );
}

/** Debounced so a search is one request per pause, not one per character. */
let timer = null;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => visit({ q: value || undefined, page: undefined }, { partial: true }), 250);
});

function clearSearch() {
    search.value = '';
}

const views = ['orders', 'haggle'];
</script>

<template>
    <AppLayout :title="$t('leads.index.heading')">
        <PageHeader :title="$t('leads.index.heading')" :description="$t('leads.index.subheading')" />

        <!-- Tabs first: the tab decides which statuses even exist below it. -->
        <div class="mb-4 flex gap-1 border-b border-border" role="tablist">
            <button
                v-for="view in views"
                :key="view"
                type="button"
                role="tab"
                :aria-selected="activeView === view"
                class="-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-body-sm transition-colors duration-fast"
                :class="
                    activeView === view
                        ? 'border-primary font-semibold text-primary-text'
                        : 'border-transparent font-medium text-muted hover:border-border-strong hover:text-text'
                "
                @click="visit({ view, status: undefined, page: undefined })"
            >
                {{ $t(`leads.views.${view}`) }}
                <Badge v-if="view === 'haggle' && pendingHaggleCount > 0" variant="primary">
                    {{ pendingHaggleCount }}
                </Badge>
            </button>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="relative min-w-56 flex-1">
                <IconSearch
                    :size="17"
                    :stroke-width="1.75"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
                    aria-hidden="true"
                />
                <input
                    id="leads-search"
                    v-model="search"
                    type="search"
                    class="field w-full py-2.5 pl-9 pr-3 text-body-sm"
                    :placeholder="$t('leads.index.search_placeholder')"
                    :aria-label="$t('leads.index.search_placeholder')"
                />
            </div>

            <Select
                id="leads-status"
                :model-value="activeStatus ?? ''"
                :label="$t('leads.filters.all')"
                :options="statusChoices"
                class="w-48 [&_label]:sr-only [&_p]:hidden"
                @update:model-value="(value) => visit({ status: value || undefined, page: undefined })"
            />

            <Select
                v-if="agents.length > 1"
                id="leads-agent"
                :model-value="activeAgent ? String(activeAgent) : ''"
                :label="$t('leads.index.all_agents')"
                :options="agentChoices"
                class="w-48 [&_label]:sr-only [&_p]:hidden"
                @update:model-value="(value) => visit({ agent: value || undefined, page: undefined })"
            />
        </div>

        <p v-if="activeSearch" class="mb-3 flex items-center gap-3 text-body-sm text-muted">
            <span>{{ $t('leads.index.search_results', { term: activeSearch }) }}</span>
            <button type="button" class="font-medium text-primary-text hover:underline" @click="clearSearch">
                {{ $t('leads.index.search_clear') }}
            </button>
        </p>

        <div v-if="leads.data.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface">
            <EmptyState
                :icon="IconInbox"
                :title="isFiltered ? $t('leads.index.empty_filtered_title') : $t(`leads.views.empty_${activeView}_title`)"
                :description="isFiltered ? $t('leads.index.empty_filtered_description') : $t(`leads.views.empty_${activeView}_description`)"
            />
        </div>

        <div v-else class="space-y-3">
            <LeadCard
                v-for="lead in leads.data"
                :key="lead.id"
                :lead="lead"
                :status-options="statusOptions"
            />
        </div>

        <div v-if="leads.last_page > 1" class="mt-5 flex items-center justify-between gap-4">
            <p class="text-caption tabular-nums text-muted">{{ leads.current_page }} / {{ leads.last_page }}</p>

            <div class="flex gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="leads.current_page === 1"
                    @click="visit({ page: leads.current_page - 1 })"
                >
                    ←
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="leads.current_page === leads.last_page"
                    @click="visit({ page: leads.current_page + 1 })"
                >
                    →
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
