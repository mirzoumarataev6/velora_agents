<script setup>
/**
 * Where every lead currently sits.
 *
 * A share bar plus the count, because "12 new" and "12 new out of 15" are
 * different facts and only the second one is actionable.
 */
import Card from '../ui/Card.vue';

defineProps({
    leadsByStatus: { type: Object, required: true },
});

const tone = {
    new: 'bg-chart-1',
    in_progress: 'bg-chart-4',
    awaiting_price_approval: 'bg-warning-fill',
    done: 'bg-success-fill',
};
</script>

<template>
    <Card>
        <template #header>
            <div class="flex items-baseline justify-between gap-3">
                <div>
                    <h3 class="text-h4 text-text">{{ $t('dashboard.leads_status.title') }}</h3>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.leads_status.description') }}</p>
                </div>
                <p class="shrink-0 text-caption text-muted">
                    {{ $t('dashboard.leads_status.total') }}:
                    <span class="font-semibold tabular-nums text-text">{{ leadsByStatus.total }}</span>
                </p>
            </div>
        </template>

        <ol v-if="leadsByStatus.total > 0" class="space-y-3">
            <li v-for="row in leadsByStatus.rows" :key="row.status">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-body-sm text-text">{{ $t(`dashboard.leads_status.statuses.${row.status}`) }}</span>
                    <span class="flex items-baseline gap-2">
                        <span class="text-body-sm font-semibold tabular-nums text-text">{{ row.count }}</span>
                        <span class="text-caption tabular-nums text-muted">{{ row.share }}%</span>
                    </span>
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-pill bg-surface-2">
                    <div class="h-full rounded-pill" :class="tone[row.status] ?? 'bg-chart-1'" :style="{ width: `${Math.max(2, row.share)}%` }" />
                </div>
            </li>
        </ol>

        <p v-else class="py-4 text-body-sm text-muted">{{ $t('dashboard.leads_status.empty') }}</p>
    </Card>
</template>
