<script setup>
/**
 * Questions the assistant could not answer.
 *
 * The single most actionable list on the page: each row is a gap in the
 * knowledge base that a customer has already hit, with the number of times they
 * hit it. The reason badge says WHERE the gap is — catalogue, knowledge base,
 * or a provider outage that is nobody's content problem.
 */
import Card from '../ui/Card.vue';
import Badge from '../ui/Badge.vue';
import EmptyState from '../ui/EmptyState.vue';
import { IconCircleCheck } from '@tabler/icons-vue';

defineProps({
    unanswered: { type: Array, required: true },
});
</script>

<template>
    <Card :flush="unanswered.length > 0">
        <template #header>
            <h3 class="text-h4 text-text">{{ $t('dashboard.unanswered.title') }}</h3>
            <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.unanswered.description') }}</p>
        </template>

        <ul v-if="unanswered.length" class="divide-y divide-border">
            <li v-for="row in unanswered" :key="row.question" class="px-5 py-3">
                <p class="text-body-sm text-text">{{ row.question }}</p>
                <p class="mt-1 flex items-center gap-2">
                    <span class="text-caption tabular-nums text-muted">
                        {{ $t('dashboard.unanswered.times', { count: row.count }) }}
                    </span>
                    <Badge v-if="row.reason" variant="warning">
                        {{ $t(`dashboard.unanswered.reasons.${row.reason}`) }}
                    </Badge>
                </p>
            </li>
        </ul>

        <EmptyState v-else :icon="IconCircleCheck" :title="$t('dashboard.unanswered.empty')" />
    </Card>
</template>
