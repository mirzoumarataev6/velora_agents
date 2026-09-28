<script setup>
/**
 * "Агент онлайн · 24/7" — the one element on the page that is true RIGHT NOW
 * rather than true of the selected period.
 *
 * The pulse is the point: it marks a figure that is live, and nothing else on
 * the dashboard moves. That is also why it survives prefers-reduced-motion as a
 * static dot rather than disappearing — it carries information, so removing it
 * would remove meaning; it just stops animating (see app.css).
 */
defineProps({
    activeConversations: { type: Number, default: 0 },
});
</script>

<template>
    <div class="flex items-center gap-2.5 rounded-pill border border-success bg-success-tint px-3.5 py-1.5">
        <span class="relative flex h-2.5 w-2.5 shrink-0">
            <span class="pulse-ring absolute inline-flex h-full w-full rounded-full bg-success-fill" aria-hidden="true" />
            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-success-fill" />
        </span>

        <span class="text-caption font-semibold text-success">
            {{ $t('dashboard.live.online') }}
        </span>

        <span class="h-3.5 w-px bg-success/40" aria-hidden="true" />

        <span class="text-caption font-medium tabular-nums text-success">
            {{ $t('dashboard.live.active', { count: activeConversations }) }}
        </span>
    </div>
</template>
