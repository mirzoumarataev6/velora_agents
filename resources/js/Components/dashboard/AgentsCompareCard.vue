<script setup>
/**
 * Which assistant is carrying the load.
 *
 * Only rendered when there is more than one agent — the metrics service returns
 * an empty array below two, because a comparison of one thing with itself is
 * not a comparison.
 */
import Card from '../ui/Card.vue';

defineProps({
    agentComparison: { type: Array, required: true },
});
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-text">{{ $t('dashboard.agents_compare.title') }}</h3>
            <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.agents_compare.description') }}</p>
        </template>

        <ol class="space-y-3">
            <li v-for="agent in agentComparison" :key="agent.id">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                    <span class="text-body-sm font-medium text-text">{{ agent.name }}</span>
                    <span class="flex items-baseline gap-3 text-caption text-muted">
                        <span class="tabular-nums">{{ $t('dashboard.agents_compare.messages', { count: agent.messages }) }}</span>
                        <span class="tabular-nums">{{ $t('dashboard.agents_compare.leads', { count: agent.leads }) }}</span>
                        <span class="tabular-nums font-medium text-text">{{ $t('dashboard.agents_compare.share', { percent: agent.share }) }}</span>
                    </span>
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-pill bg-surface-2">
                    <div class="h-full rounded-pill bg-primary" :style="{ width: `${Math.max(2, agent.share)}%` }" />
                </div>
            </li>
        </ol>
    </Card>
</template>
