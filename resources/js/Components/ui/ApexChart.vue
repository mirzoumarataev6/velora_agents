<script setup>
/**
 * The only place ApexCharts is touched.
 *
 * Wrapping it does three things no chart component should have to repeat:
 *
 *   1. Resolves colours from the CSS tokens of THIS element's subtree, so a
 *      chart inside a `.dark` preview pane draws the dark palette.
 *   2. Re-resolves them on `theme:changed` and `mode:changed`, so a chart
 *      already on screen recolours when the theme or stage mode is toggled
 *      instead of keeping the palette it was born with.
 *   3. Applies the house defaults (2.5–3px strokes, 13px axis labels,
 *      horizontal gridlines, no animation) before the caller's own options.
 *
 * IT DRIVES APEXCHARTS DIRECTLY rather than through `vue3-apexcharts`, and the
 * reason is measured: that wrapper is 7 KB of glue, but its dist ALSO vendors
 * its own 750 KB copy of apexcharts, so the build shipped the library twice —
 * 136 KB + 272 KB gzipped of the same code, on the one page a demo is judged
 * on. The imperative API below is the same handful of calls the wrapper makes.
 *
 * Loaded lazily: ~270 KB gzipped belongs to the dashboard, not to the auth
 * pages, so it is imported on mount rather than at the top of the bundle.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { baseOptions, mergeOptions, readTokens } from '../../chart-theme';

const props = defineProps({
    type: { type: String, default: 'line' },
    series: { type: Array, required: true },
    height: { type: [Number, String], default: 280 },
    /** Apex options, deep-merged over the house defaults. */
    options: { type: Object, default: () => ({}) },
    /** Explicit series colours. Defaults to the chart palette in token order. */
    colors: { type: Array, default: null },
});

const host = ref(null);
const ready = ref(false);

let ApexCharts = null;
let chart = null;

function build() {
    const tokens = readTokens(host.value);
    const base = baseOptions(tokens, { element: host.value });

    return mergeOptions(
        {
            ...base,
            chart: { ...base.chart, type: props.type, height: props.height },
            colors: props.colors ?? tokens.series,
            series: props.series,
        },
        props.options
    );
}

async function render() {
    if (!ApexCharts || !host.value) return;

    // Destroy rather than updateOptions when the palette changes: Apex leaves
    // the previous colours on paths it has already drawn, so a theme switch
    // would recolour the axes and leave the series in the old palette.
    chart?.destroy();
    chart = new ApexCharts(host.value, build());
    await chart.render();
    ready.value = true;
}

onMounted(async () => {
    ApexCharts = (await import('apexcharts')).default;
    await render();

    document.addEventListener('theme:changed', render);
    document.addEventListener('mode:changed', render);
});

onBeforeUnmount(() => {
    document.removeEventListener('theme:changed', render);
    document.removeEventListener('mode:changed', render);
    chart?.destroy();
    chart = null;
});

// Data changes are an update, not a rebuild — the palette has not moved, and
// re-creating the chart on every filter change would flash the whole card.
watch(
    () => [props.series, props.options],
    () => {
        if (!chart) return;
        chart.updateOptions(build(), false, false);
    },
    { deep: true }
);

watch(() => props.colors, render);
</script>

<template>
    <div class="relative" :style="{ minHeight: `${height}px` }">
        <div ref="host" />

        <!-- Reserves the space until the library lands, so the page does not
             jump when a lazily-imported chart resolves. -->
        <div
            v-if="!ready"
            class="absolute inset-0 animate-pulse rounded-lg bg-surface-2"
            aria-hidden="true"
        />
    </div>
</template>
