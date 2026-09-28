<script setup>
/**
 * The conversion funnel: message → lead → order → payment.
 *
 * Bars, not a tapering trapezoid. A trapezoid encodes value in an area, and
 * area is the hardest encoding to read accurately — a stage at half the width
 * looks like a quarter of the value. Length along a shared baseline is the
 * easiest, and it stays readable at the back of a room.
 *
 * Every stage carries its absolute count AND its conversion from the previous
 * stage, because the interesting number in a funnel is always the drop.
 */
import { computed } from 'vue';

const props = defineProps({
    /** [{ label, value, hint? }] in order. */
    stages: { type: Array, required: true },
});

const top = computed(() => props.stages[0]?.value || 1);

const rows = computed(() =>
    props.stages.map((stage, index) => {
        const previous = index === 0 ? null : props.stages[index - 1].value;

        return {
            ...stage,
            width: `${Math.max(2, (stage.value / top.value) * 100)}%`,
            conversion: previous ? Math.round((stage.value / previous) * 1000) / 10 : null,
        };
    })
);
</script>

<template>
    <ol class="space-y-3">
        <li v-for="stage in rows" :key="stage.label">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-body-sm font-medium text-text">{{ stage.label }}</span>
                <span class="flex items-baseline gap-2">
                    <span class="text-body-sm font-semibold tabular-nums text-text">{{ stage.value }}</span>
                    <span
                        v-if="stage.conversion !== null"
                        class="text-caption tabular-nums text-muted"
                    >{{ stage.conversion }}%</span>
                </span>
            </div>

            <div class="mt-1.5 h-2.5 overflow-hidden rounded-pill bg-surface-2">
                <div class="h-full rounded-pill bg-primary transition-[width] duration-slow" :style="{ width: stage.width }" />
            </div>

            <p v-if="stage.hint" class="mt-1 text-caption text-muted">{{ stage.hint }}</p>
        </li>
    </ol>
</template>
