<script setup>
/**
 * What customers ask the catalogue for — and how often it has no answer.
 *
 * The miss rate is the useful half: a term asked forty times and found every
 * time is a healthy catalogue; the same term missed half the time is demand
 * going unmet, so it is badged rather than left as a column of numbers.
 */
import Card from '../ui/Card.vue';
import Badge from '../ui/Badge.vue';
import EmptyState from '../ui/EmptyState.vue';
import { IconSearch } from '@tabler/icons-vue';

defineProps({
    products: { type: Array, required: true },
});
</script>

<template>
    <Card :flush="products.length > 0">
        <template #header>
            <h3 class="text-h4 text-text">{{ $t('dashboard.products.title') }}</h3>
            <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.products.description') }}</p>
        </template>

        <ul v-if="products.length" class="divide-y divide-border">
            <li v-for="row in products" :key="row.term" class="flex items-center justify-between gap-3 px-5 py-3">
                <span class="min-w-0 truncate text-body-sm text-text">{{ row.term }}</span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="text-caption tabular-nums text-muted">
                        {{ $t('dashboard.products.asked', { count: row.count }) }}
                    </span>
                    <Badge v-if="row.miss_rate >= 50" variant="warning">
                        {{ $t('dashboard.products.miss_rate', { percent: row.miss_rate }) }}
                    </Badge>
                </span>
            </li>
        </ul>

        <EmptyState v-else :icon="IconSearch" :title="$t('dashboard.products.empty')" />
    </Card>
</template>
