<script setup>
/**
 * When customers write — days down, hours across.
 *
 * A CSS grid rather than a charting library: 7×24 coloured cells is a table,
 * and a heatmap plugin would add weight and its own colour scale to draw it.
 *
 * The scale is a single-hue ramp of the primary, which is the correct encoding
 * for a sequential quantity — a rainbow scale implies categories that are not
 * there. Every cell carries its exact count in `title`, because a colour ramp
 * is for the shape and never for reading a value off.
 */
import { computed } from 'vue';
import Card from '../ui/Card.vue';
import { translate } from '../../i18n';

const props = defineProps({
    heatmap: { type: Object, required: true },
});

const hours = Array.from({ length: 24 }, (_, hour) => hour);

/** Five steps, so a cell is comparable rather than merely coloured. */
function intensity(count) {
    if (count === 0) return 'bg-surface-2';

    const ratio = count / props.heatmap.max;
    if (ratio <= 0.25) return 'bg-blue-200';
    if (ratio <= 0.5) return 'bg-blue-300';
    if (ratio <= 0.75) return 'bg-blue-400';

    return 'bg-blue-500';
}

const dayLabels = computed(() => Array.from({ length: 7 }, (_, i) => translate(`dashboard.heatmap.days.${i}`)));
</script>

<template>
    <Card>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 class="text-h4 text-text">{{ $t('dashboard.heatmap.title') }}</h3>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.heatmap.description') }}</p>
                </div>
                <p class="shrink-0 text-caption text-muted">{{ $t('dashboard.heatmap.timezone_label') }}</p>
            </div>
        </template>

        <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-0.5">
                <tbody>
                    <tr v-for="(row, day) in heatmap.grid" :key="day">
                        <th scope="row" class="pr-2 text-right text-caption font-medium text-muted">
                            {{ dayLabels[day] }}
                        </th>
                        <td
                            v-for="hour in hours"
                            :key="hour"
                            class="h-4 min-w-2 rounded-xs"
                            :class="intensity(row[hour] ?? 0)"
                            :title="$t('dashboard.heatmap.cell', { day: dayLabels[day], hour, count: row[hour] ?? 0 })"
                        />
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-3 flex items-center justify-end gap-1.5 text-caption text-muted">
            <span>{{ $t('dashboard.heatmap.less') }}</span>
            <span class="h-3 w-3 rounded-xs bg-surface-2" />
            <span class="h-3 w-3 rounded-xs bg-blue-200" />
            <span class="h-3 w-3 rounded-xs bg-blue-300" />
            <span class="h-3 w-3 rounded-xs bg-blue-400" />
            <span class="h-3 w-3 rounded-xs bg-blue-500" />
            <span>{{ $t('dashboard.heatmap.more') }}</span>
        </div>
    </Card>
</template>
