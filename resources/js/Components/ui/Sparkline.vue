<script setup>
/**
 * A bare trend line for a KPI card.
 *
 * Hand-drawn SVG rather than a chart library: it has no axes, no legend, no
 * tooltip and no interaction, so a charting engine would add 70 KB and a
 * mount cost to draw a polyline. It also inherits currentColor, which lets the
 * card decide the colour from the trend direction in one place.
 *
 * 2.5px stroke and `vector-effect: non-scaling-stroke`, so the line keeps its
 * weight however the box is scaled — a hairline sparkline is invisible on a
 * projector, and a stretched viewBox is what usually produces one.
 */
import { computed } from 'vue';

const props = defineProps({
    values: { type: Array, required: true },
    /** Fill the area under the line as well. */
    area: { type: Boolean, default: true },
});

const W = 100;
const H = 28;

const points = computed(() => {
    const values = props.values;
    if (values.length < 2) return [];

    const min = Math.min(...values);
    const max = Math.max(...values);
    // A flat series has no range to normalise against; draw it down the middle
    // rather than dividing by zero and drawing nothing.
    const span = max - min || 1;

    return values.map((value, index) => [
        (index / (values.length - 1)) * W,
        H - ((value - min) / span) * (H - 4) - 2,
    ]);
});

const line = computed(() => points.value.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' '));
const fill = computed(() => (points.value.length ? `${line.value} L${W} ${H} L0 ${H} Z` : ''));
</script>

<template>
    <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="h-7 w-full" aria-hidden="true">
        <path v-if="area && fill" :d="fill" fill="currentColor" opacity="0.12" />
        <path
            :d="line"
            fill="none"
            stroke="currentColor"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
