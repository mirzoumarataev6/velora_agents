<script setup>
/**
 * The owner panel's front page.
 *
 * COMPOSITION, top to bottom — the order is the argument:
 *   1. four KPI cards, big tabular numbers with a trend and a sparkline
 *   2. the one thing to do right now (the only filled-primary block on screen)
 *   3. the main chart, titled with what it FOUND rather than what it plots
 *   4. live conversations beside the funnel — the product working, and the
 *      money it produced
 *   5. everything else, in decreasing order of how often it changes a decision
 *
 * The first three of those are what a jury sees in five seconds, which is why
 * the page spends its one attention-grabbing element on row 2 and keeps
 * everything below it quiet.
 *
 * Read-only and one request: changing the range or the agent filter is an
 * Inertia visit that re-renders every widget from the same metrics pass, so no
 * two numbers on the page can ever come from different moments.
 */
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    IconChartBar,
    IconClock,
    IconClipboardList,
    IconMessage2,
} from '@tabler/icons-vue';

import AppLayout from '../Layouts/AppLayout.vue';
import Button from '../Components/ui/Button.vue';
import EmptyState from '../Components/ui/EmptyState.vue';
import PageHeader from '../Components/ui/PageHeader.vue';
import Select from '../Components/ui/Select.vue';
import StatCard from '../Components/ui/StatCard.vue';

import AgentsCompareCard from '../Components/dashboard/AgentsCompareCard.vue';
import FunnelCard from '../Components/dashboard/FunnelCard.vue';
import HeatmapCard from '../Components/dashboard/HeatmapCard.vue';
import HighlightCard from '../Components/dashboard/HighlightCard.vue';
import LeadsStatusCard from '../Components/dashboard/LeadsStatusCard.vue';
import LiveConversation from '../Components/dashboard/LiveConversation.vue';
import LiveStatus from '../Components/dashboard/LiveStatus.vue';
import MessagesChart from '../Components/dashboard/MessagesChart.vue';
import PendingCard from '../Components/dashboard/PendingCard.vue';
import ProductsCard from '../Components/dashboard/ProductsCard.vue';
import RatingsCard from '../Components/dashboard/RatingsCard.vue';
import ResponseTrendCard from '../Components/dashboard/ResponseTrendCard.vue';
import UnansweredCard from '../Components/dashboard/UnansweredCard.vue';

import { translate } from '../i18n';
import { halfOverHalf } from '../trend';

const props = defineProps({
    metrics: { type: Object, required: true },
    agents: { type: Array, default: () => [] },
});

const summary = computed(() => props.metrics.summary);

/* --- Filters ------------------------------------------------------------- */

const agentOptions = computed(() => [
    { value: '', label: translate('dashboard.agent_filter.all') },
    ...props.agents.map((agent) => ({ value: String(agent.id), label: agent.name })),
]);

const selectedAgent = computed({
    get: () => String(props.metrics.agent_id ?? ''),
    set: (value) => visit({ agent_id: value || undefined }),
});

/**
 * Both filters go through one place, and both preserve state so the page does
 * not scroll back to the top when the range changes under the reader's cursor.
 */
function visit(params) {
    router.get(
        route('dashboard'),
        {
            range: props.metrics.range,
            agent_id: props.metrics.agent_id ?? undefined,
            ...params,
        },
        { preserveScroll: true, preserveState: true, replace: true }
    );
}

/* --- KPIs ---------------------------------------------------------------- */

const messageSeries = computed(() => props.metrics.series.map((day) => day.total));
const responseSeries = computed(() =>
    props.metrics.response_trend.series.map((day) => (day.ms === null ? null : day.ms / 1000))
);

const totalMessages = computed(() => messageSeries.value.reduce((sum, n) => sum + n, 0));

/** The label is precise about what the comparison actually was. See trend.js. */
const trendLabel = translate('dashboard.summary.trend_half');

const responseValue = computed(() => {
    const ms = summary.value.avg_response_ms;
    if (ms === null) return '—';

    return ms >= 1000 ? `${Math.round(ms / 100) / 10} с` : `${Math.round(ms)} мс`;
});

const kpis = computed(() => [
    {
        key: 'messages',
        label: translate('dashboard.messages_chart.title'),
        value: totalMessages.value,
        trend: halfOverHalf(messageSeries.value),
        series: messageSeries.value,
        icon: IconMessage2,
    },
    {
        key: 'leads_week',
        label: translate('dashboard.summary.leads_week'),
        value: summary.value.leads_week,
        trend: null,
        icon: IconClipboardList,
    },
    {
        key: 'conversion',
        label: translate('dashboard.summary.conversion'),
        value: summary.value.conversion_rate === null ? '—' : `${summary.value.conversion_rate}%`,
        trend: null,
        icon: IconChartBar,
    },
    {
        key: 'response',
        label: translate('dashboard.summary.response_time'),
        value: responseValue.value,
        // Falling response time is the GOOD direction, which the card cannot
        // know on its own — hence `invert`.
        trend: halfOverHalf(responseSeries.value.filter((v) => v !== null)),
        invert: true,
        series: responseSeries.value.filter((v) => v !== null),
        icon: IconClock,
    },
]);
</script>

<template>
    <AppLayout :title="$t('dashboard.heading')">
        <PageHeader :title="$t('dashboard.heading')" :description="$t('dashboard.subheading')">
            <template #actions>
                <LiveStatus :active-conversations="summary.active_conversations" />

                <!-- A filter over one agent is not a filter. -->
                <Select
                    v-if="agents.length > 1"
                    id="dashboard_agent_filter"
                    v-model="selectedAgent"
                    :label="$t('dashboard.agent_filter.label')"
                    :options="agentOptions"
                    class="w-48 [&_label]:sr-only"
                />

                <div class="flex rounded-md border border-border p-0.5" role="group">
                    <button
                        v-for="option in [7, 30]"
                        :key="option"
                        type="button"
                        :aria-current="metrics.range === option ? 'true' : 'false'"
                        class="rounded px-3 py-1.5 text-caption font-medium transition-colors duration-fast"
                        :class="
                            metrics.range === option
                                ? 'bg-primary text-on-primary'
                                : 'text-muted hover:bg-surface-2 hover:text-text'
                        "
                        @click="visit({ range: option })"
                    >
                        {{ $t(`dashboard.messages_chart.range_${option}`) }}
                    </button>
                </div>
            </template>
        </PageHeader>

        <!-- One honest empty state for the whole page rather than eight lonely
             zeroes: an owner who has not connected a channel learns nothing
             from a grid of noughts. -->
        <div v-if="!metrics.has_history" class="rounded-xl border border-dashed border-border-strong bg-surface">
            <EmptyState
                :icon="IconChartBar"
                :title="$t('dashboard.empty.title')"
                :description="$t('dashboard.empty.description')"
            >
                <template #action>
                    <Button @click="router.get(route('agents.index'))">{{ $t('dashboard.empty.cta') }}</Button>
                </template>
            </EmptyState>
        </div>

        <div v-else class="space-y-6">
            <!-- 1 · the four headline numbers -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-for="kpi in kpis"
                    :key="kpi.key"
                    :label="kpi.label"
                    :value="kpi.value"
                    :trend="kpi.trend"
                    :invert="kpi.invert"
                    :trend-label="kpi.trend === null ? null : trendLabel"
                    :series="kpi.series"
                    :icon="kpi.icon"
                />
            </div>

            <!-- 2 · the one decision to make now -->
            <HighlightCard :highlight="metrics.highlight" :after-hours="metrics.after_hours" />

            <!-- 3 · volume over time, titled with what it found -->
            <MessagesChart :series="metrics.series" :channels="metrics.channels" :range="metrics.range" />

            <!-- 4 · the product working, beside the money it produced -->
            <div class="grid gap-4 lg:grid-cols-2">
                <LiveConversation />
                <FunnelCard :funnel="metrics.funnel" />
            </div>

            <!-- 5 · answer quality, on its own: the only thing here that can
                 say an answer was WRONG rather than missing -->
            <RatingsCard :ratings="metrics.ratings" />

            <div class="grid gap-4 lg:grid-cols-2">
                <PendingCard :pending="metrics.pending" />
                <LeadsStatusCard :leads-by-status="metrics.leads_by_status" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <ResponseTrendCard :response-trend="metrics.response_trend" />
                <HeatmapCard :heatmap="metrics.heatmap" />
            </div>

            <AgentsCompareCard v-if="metrics.agent_comparison.length" :agent-comparison="metrics.agent_comparison" />

            <div class="grid gap-4 lg:grid-cols-2">
                <ProductsCard :products="metrics.products" />
                <UnansweredCard :unanswered="metrics.unanswered" />
            </div>
        </div>
    </AppLayout>
</template>
