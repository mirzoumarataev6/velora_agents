<script setup>
/**
 * Answer quality — the 👍/👎 customers leave in Telegram.
 *
 * The only thing on this page that can tell an owner an answer was WRONG rather
 * than missing, which is why it gets a full row to itself.
 *
 * Up and down are green and orange, NOT green and red: the system reserves red
 * for destructive actions, and red/green is the one pair a colourblind reader
 * cannot separate. Each also carries its own word, icon and count.
 *
 * An unrated agent scores `null`, never 100%. The distinction between "nobody
 * disliked it" and "nobody rated it" is the difference between a useful widget
 * and a flattering one, so the empty state TEACHES — it explains how ratings
 * are collected rather than apologising for having none.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { IconArrowRight, IconThumbDown, IconThumbUp } from '@tabler/icons-vue';
import ApexChart from '../ui/ApexChart.vue';
import Badge from '../ui/Badge.vue';
import Card from '../ui/Card.vue';
import { translate } from '../../i18n';

const props = defineProps({
    ratings: { type: Object, required: true },
});

const hasSeries = computed(() => props.ratings.series?.some((day) => day.up > 0 || day.down > 0));

const chartSeries = computed(() => [
    { name: translate('dashboard.ratings.up'), data: props.ratings.series.map((d) => d.up) },
    { name: translate('dashboard.ratings.down'), data: props.ratings.series.map((d) => d.down) },
]);

const options = computed(() => ({
    chart: { type: 'bar', stacked: true },
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
    xaxis: { categories: props.ratings.series.map((d) => d.label) },
    yaxis: { labels: { formatter: (v) => Math.round(v) } },
    stroke: { width: 0 },
    tooltip: { shared: true, intersect: false },
}));

// Success green and warning orange — see the note above.
const colors = ['var(--color-success-fill)', 'var(--color-chart-3)'];

/** The score ring, drawn as a conic gradient rather than an SVG arc. */
const ring = computed(() => ({
    background: `conic-gradient(var(--color-success-fill) 0 ${props.ratings.score}%, var(--color-surface-2) ${props.ratings.score}% 100%)`,
}));

const howSteps = computed(() => {
    const steps = translate('dashboard.ratings.empty_how');

    return Array.isArray(steps) ? steps : [];
});
</script>

<template>
    <Card>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="flex items-center gap-2 text-h4 text-text">
                        {{ $t('dashboard.ratings.title') }}
                        <Badge>{{ $t('dashboard.ratings.telegram_only') }}</Badge>
                    </h3>
                    <p class="mt-0.5 max-w-prose text-caption text-muted">{{ $t('dashboard.ratings.description') }}</p>
                </div>
            </div>
        </template>

        <div v-if="ratings.total > 0" class="space-y-5">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-4">
                <!-- The percentage AND a visual for it, not one or the other. -->
                <div class="flex items-center gap-3">
                    <span
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full"
                        :style="ring"
                        aria-hidden="true"
                    >
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-surface text-caption font-semibold tabular-nums text-text">
                            {{ ratings.score }}%
                        </span>
                    </span>
                    <p class="text-caption text-muted">{{ $t('dashboard.ratings.score') }}</p>
                </div>

                <span class="flex items-center gap-1.5 text-body-sm text-text">
                    <IconThumbUp :size="16" :stroke-width="2" class="text-success" aria-hidden="true" />
                    {{ $t('dashboard.ratings.up') }}
                    <span class="font-semibold tabular-nums">{{ ratings.up }}</span>
                </span>
                <span class="flex items-center gap-1.5 text-body-sm text-text">
                    <IconThumbDown :size="16" :stroke-width="2" class="text-warning" aria-hidden="true" />
                    {{ $t('dashboard.ratings.down') }}
                    <span class="font-semibold tabular-nums">{{ ratings.down }}</span>
                </span>
                <span class="text-caption tabular-nums text-muted">
                    {{ ratings.total }} {{ $t('dashboard.ratings.total') }}
                </span>
            </div>

            <!-- The links that make this a task rather than a report: each
                 points at the inbox FILTERED to those replies. -->
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                <Link
                    v-if="ratings.down > 0"
                    :href="route('conversations.index', { status: 'rated_down' })"
                    class="text-caption font-medium text-primary-text hover:underline"
                >
                    {{ $t('dashboard.ratings.view_negative') }}
                </Link>
                <span v-else class="text-caption text-muted">{{ $t('dashboard.ratings.no_negative') }}</span>

                <Link
                    v-if="ratings.up > 0"
                    :href="route('conversations.index', { status: 'rated_up' })"
                    class="text-caption font-medium text-primary-text hover:underline"
                >
                    {{ $t('dashboard.ratings.view_positive') }}
                </Link>
            </div>

            <div v-if="hasSeries">
                <h4 class="mb-2 text-caption font-medium text-muted">{{ $t('dashboard.ratings.trend_title') }}</h4>
                <ApexChart type="bar" :series="chartSeries" :options="options" :colors="colors" :height="180" />
            </div>

            <!-- The point of per-message ratings: WHICH reply, not how many. -->
            <div v-if="ratings.worst.length">
                <h4 class="mb-2 text-caption font-medium text-muted">{{ $t('dashboard.ratings.worst_title') }}</h4>
                <ul class="divide-y divide-border rounded-lg border border-border">
                    <li
                        v-for="reply in ratings.worst"
                        :key="reply.id"
                        class="flex items-start justify-between gap-3 px-3.5 py-2.5"
                    >
                        <p class="min-w-0 flex-1 line-clamp-2 text-caption text-text">{{ reply.excerpt }}</p>
                        <Link
                            :href="route('conversations.index', { conversation: reply.conversation_id })"
                            class="flex shrink-0 items-center gap-1 text-caption font-medium text-primary-text hover:underline"
                        >
                            {{ $t('dashboard.ratings.open_conversation') }}
                            <IconArrowRight :size="13" :stroke-width="2" aria-hidden="true" />
                        </Link>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Teaches rather than apologises. And above all: no ring, no chart,
             and no flattering 100%. -->
        <div v-else class="space-y-3">
            <p class="text-body-sm font-medium text-text">{{ $t('dashboard.ratings.empty_title') }}</p>
            <p class="max-w-prose text-body-sm text-muted">{{ $t('dashboard.ratings.empty') }}</p>

            <div>
                <h4 class="mb-1.5 text-caption font-medium text-text">{{ $t('dashboard.ratings.empty_how_title') }}</h4>
                <ol class="list-decimal space-y-1 pl-5 text-caption text-muted">
                    <li v-for="(step, index) in howSteps" :key="index">{{ step }}</li>
                </ol>
            </div>
        </div>
    </Card>
</template>
