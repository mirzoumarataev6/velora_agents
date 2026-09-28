<script setup>
/**
 * The main chart: message volume over the period, split by channel.
 *
 * THE TITLE IS AN INSIGHT, not a label — computed from the data itself, so it
 * says "Telegram принёс 62% сообщений" rather than "Сообщения по каналам". A
 * jury reading the dashboard for five seconds reads the title and nothing else.
 *
 * Channel colours are FIXED product-wide (Telegram blue, WhatsApp green,
 * Instagram pink) and come from tokens, so they match the badges and the legend
 * everywhere else in the product.
 *
 * Capped at five series before an "other" bucket, because a sixth line cannot
 * be told from a fifth by anyone.
 */
import { computed } from 'vue';
import ApexChart from '../ui/ApexChart.vue';
import ChartCard from '../ui/ChartCard.vue';
import { translate } from '../../i18n';

const props = defineProps({
    series: { type: Array, required: true },
    channels: { type: Array, default: () => [] },
    range: { type: Number, default: 7 },
});

const total = computed(() => props.series.reduce((sum, day) => sum + day.total, 0));

/** Per-channel totals across the period, biggest first. */
const byChannel = computed(() => {
    const totals = {};

    for (const day of props.series) {
        for (const [channel, count] of Object.entries(day.by_channel ?? {})) {
            totals[channel] = (totals[channel] ?? 0) + count;
        }
    }

    return Object.entries(totals)
        .map(([channel, count]) => ({ channel, count }))
        .sort((a, b) => b.count - a.count)
        .slice(0, 5);
});

const chartSeries = computed(() =>
    byChannel.value.map(({ channel }) => ({
        name: translate(`dashboard.channels.${channel}`),
        data: props.series.map((day) => day.by_channel?.[channel] ?? 0),
    }))
);

const labels = computed(() => props.series.map((day) => day.label));

/* Channel colours are read from the tokens by name so the chart, the legend and
   every badge elsewhere agree; anything outside the three known channels falls
   through to the ordinary categorical palette. */
const colors = computed(() =>
    byChannel.value.map(({ channel }) => {
        const named = { telegram: 1, whatsapp: 2, instagram: 3 };

        return named[channel]
            ? `var(--color-channel-${channel})`
            : `var(--color-chart-${(Object.keys(named).length % 5) + 1})`;
    })
);

/** The title, written as a finding rather than as a category. */
const insight = computed(() => {
    if (total.value === 0) return translate('dashboard.messages_chart.title');

    const leader = byChannel.value[0];
    if (!leader) return translate('dashboard.messages_chart.title');

    const share = Math.round((leader.count / total.value) * 100);

    return translate('dashboard.messages_chart.insight', {
        channel: translate(`dashboard.channels.${leader.channel}`),
        percent: share,
    });
});

const options = computed(() => ({
    chart: { type: 'area', stacked: true },
    xaxis: { categories: labels.value },
    yaxis: { labels: { formatter: (value) => Math.round(value) } },
    fill: { type: 'solid', opacity: 0.12 },
    tooltip: { shared: true, intersect: false },
}));
</script>

<template>
    <ChartCard
        :title="insight"
        :subtitle="$t('dashboard.messages_chart.total') + ': ' + total"
        sparkle
    >
        <ApexChart
            v-if="total > 0"
            type="area"
            :series="chartSeries"
            :options="options"
            :colors="colors"
            :height="300"
        />
        <p v-else class="py-12 text-center text-body-sm text-muted">
            {{ $t('dashboard.messages_chart.empty') }}
        </p>

        <template v-if="total > 0" #legend>
            <span
                v-for="entry in byChannel"
                :key="entry.channel"
                class="flex items-center gap-2 text-caption text-muted"
            >
                <span
                    class="h-2.5 w-2.5 rounded-sm"
                    :style="{ backgroundColor: `var(--color-channel-${entry.channel}, var(--color-chart-1))` }"
                />
                {{ $t(`dashboard.channels.${entry.channel}`) }}
                <span class="tabular-nums text-text">{{ entry.count }}</span>
            </span>
        </template>
    </ChartCard>
</template>
