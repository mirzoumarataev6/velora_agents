<script setup>
/**
 * How fast the assistant answers, by day.
 *
 * Lower is better here, which the axis makes plain by staying in seconds rather
 * than switching units per point — a chart whose unit changes between days
 * cannot be read as a trend at all.
 */
import { computed } from 'vue';
import ApexChart from '../ui/ApexChart.vue';
import Card from '../ui/Card.vue';
import { translate } from '../../i18n';

const props = defineProps({
    responseTrend: { type: Object, required: true },
});

const chartSeries = computed(() => [
    {
        name: translate('dashboard.response_trend.title'),
        // Nulls are days with no replies. Apex draws a gap, which is honest —
        // joining across them would invent a measurement that was never made.
        data: props.responseTrend.series.map((d) => (d.ms === null ? null : Math.round((d.ms / 1000) * 10) / 10)),
    },
]);

const options = computed(() => ({
    chart: { type: 'line' },
    xaxis: { categories: props.responseTrend.series.map((d) => d.label) },
    yaxis: { labels: { formatter: (v) => `${Math.round(v * 10) / 10} с` } },
    markers: { size: 4, strokeWidth: 0 },
    tooltip: { y: { formatter: (v) => (v === null ? translate('dashboard.response_trend.no_replies') : `${v} с`) } },
}));
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-text">{{ $t('dashboard.response_trend.title') }}</h3>
            <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.response_trend.description') }}</p>
        </template>

        <ApexChart
            v-if="responseTrend.has_data"
            type="line"
            :series="chartSeries"
            :options="options"
            :colors="['var(--color-chart-2)']"
            :height="200"
        />
        <p v-else class="py-4 text-body-sm text-muted">{{ $t('dashboard.response_trend.empty') }}</p>
    </Card>
</template>
