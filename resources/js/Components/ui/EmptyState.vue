<script setup>
/**
 * What a screen shows when there is nothing to show.
 *
 * Never a bare "No data". An empty state has one job: say what would be here
 * and give the one action that fills it. On a demo this is also the screen most
 * likely to be seen by accident, so it has to look designed rather than broken.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    /** A @tabler/icons-vue component. */
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <span
            v-if="icon"
            class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl border border-border bg-surface-2 text-muted"
        >
            <component :is="icon" :size="24" :stroke-width="1.75" aria-hidden="true" />
        </span>

        <h3 class="text-h4 text-text">{{ title }}</h3>
        <p v-if="description" class="mt-1 max-w-sm text-body-sm text-muted">{{ description }}</p>

        <div v-if="$slots.action" class="mt-5">
            <slot name="action" />
        </div>
    </div>
</template>
