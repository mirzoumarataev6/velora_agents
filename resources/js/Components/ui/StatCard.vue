<script setup>
/**
 * A KPI tile — the thing a jury reads in the first two seconds.
 *
 * The number is the loudest element on the screen by design: 32–44px, weight
 * 700–800, tabular figures so it cannot jitter as it updates.
 *
 * The trend is an arrow AND a sign AND a colour, never colour alone — and
 * `invert` exists because "up" is not always good. Response time going up is
 * bad; the card has to be told which direction is the good one rather than
 * assuming green-for-up.
 */
import { computed } from 'vue';
import { IconArrowDownRight, IconArrowUpRight, IconMinus } from '@tabler/icons-vue';
import Sparkline from './Sparkline.vue';

/**
 * A whole number gets a thin thousands separator — "1 238", not "1238".
 *
 * A non-breaking space, so the number never wraps across it, and only for
 * integers: a value that is already formatted ("5,2 с", "—", "75%") is passed
 * through untouched rather than mangled by a second pass.
 */
function formatValue(value) {
    return Number.isInteger(value) ? value.toLocaleString('ru-RU').replace(/\s/g, '\u00a0') : value;
}

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    /** Signed percentage change against the previous period. */
    trend: { type: Number, default: null },
    /** When true, a FALLING number is the good outcome (e.g. response time). */
    invert: { type: Boolean, default: false },
    trendLabel: { type: String, default: null },
    series: { type: Array, default: null },
    icon: { type: [Object, Function], default: null },
});

const good = computed(() => {
    if (props.trend === null || props.trend === 0) return null;

    return props.invert ? props.trend < 0 : props.trend > 0;
});

const tone = computed(() => {
    if (good.value === null) return 'text-muted';

    return good.value ? 'text-success' : 'text-danger';
});

const TrendIcon = computed(() => {
    if (props.trend === null || props.trend === 0) return IconMinus;

    return props.trend > 0 ? IconArrowUpRight : IconArrowDownRight;
});
</script>

<template>
    <section class="rounded-xl border border-border bg-surface p-5 shadow-e1">
        <div class="flex items-start justify-between gap-3">
            <p class="text-caption font-medium text-muted">{{ label }}</p>
            <component :is="icon" v-if="icon" :size="18" :stroke-width="1.75" class="shrink-0 text-muted" aria-hidden="true" />
        </div>

        <p class="mt-2 text-kpi-sm tabular-nums text-text">{{ formatValue(value) }}</p>

        <div v-if="trend !== null" class="mt-2 flex items-center gap-1.5">
            <span class="inline-flex items-center gap-0.5 text-caption font-semibold tabular-nums" :class="tone">
                <component :is="TrendIcon" :size="15" :stroke-width="2.5" aria-hidden="true" />
                {{ trend > 0 ? '+' : '' }}{{ trend }}%
            </span>
            <span v-if="trendLabel" class="text-caption text-muted">{{ trendLabel }}</span>
        </div>

        <div v-if="series?.length" class="mt-3" :class="tone">
            <Sparkline :values="series" />
        </div>
    </section>
</template>
