<script setup>
/**
 * What is waiting on the owner: open orders and price approvals.
 *
 * Both are counts that mean "somebody is waiting", so each links to the leads
 * screen FILTERED to exactly those leads — not to the list in general, which
 * would make the owner re-find what the number just told them.
 */
import { Link } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';
import Card from '../ui/Card.vue';

defineProps({
    pending: { type: Object, required: true },
});
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-text">{{ $t('dashboard.pending.title') }}</h3>
        </template>

        <div v-if="pending.open_orders > 0 || pending.price_approvals > 0" class="space-y-3">
            <div v-if="pending.open_orders > 0" class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-body-sm font-medium text-text">{{ $t('dashboard.pending.open_orders') }}</p>
                    <p class="text-caption text-muted">{{ $t('dashboard.pending.open_orders_hint') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-h3 tabular-nums text-text">{{ pending.open_orders }}</span>
                    <Link :href="route('leads.index', { status: 'new' })" class="text-caption font-medium text-primary-text hover:underline">
                        {{ $t('dashboard.pending.view') }}
                    </Link>
                </div>
            </div>

            <div v-if="pending.price_approvals > 0" class="flex items-center justify-between gap-3 border-t border-border pt-3">
                <div class="min-w-0">
                    <p class="text-body-sm font-medium text-text">{{ $t('dashboard.pending.price_approvals') }}</p>
                    <p class="text-caption text-muted">{{ $t('dashboard.pending.price_approvals_hint') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-h3 tabular-nums text-warning">{{ pending.price_approvals }}</span>
                    <Link :href="route('leads.index', { view: 'haggle', status: 'awaiting_price_approval' })" class="text-caption font-medium text-primary-text hover:underline">
                        {{ $t('dashboard.pending.view') }}
                    </Link>
                </div>
            </div>
        </div>

        <p v-else class="flex items-center gap-2 py-2 text-body-sm text-muted">
            <IconCircleCheck :size="18" :stroke-width="1.75" class="shrink-0 text-success" aria-hidden="true" />
            {{ $t('dashboard.pending.clear') }}
        </p>
    </Card>
</template>
