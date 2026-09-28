<script setup>
/**
 * The title block at the top of a screen.
 *
 * The description is not decoration — it is where a screen says what it is FOR,
 * which is the difference between a jury understanding a page in two seconds
 * and squinting at it. Actions sit on the right, and the 60-30-10 rule applies:
 * at most one of them is primary.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-h1 text-text">{{ title }}</h1>
            <p v-if="description" class="mt-1 max-w-prose text-body-sm text-muted">{{ description }}</p>
        </div>

        <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
