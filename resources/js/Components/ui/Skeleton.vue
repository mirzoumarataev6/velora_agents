<script setup>
/**
 * A placeholder for content that is still loading.
 *
 * A pulse, not a sweeping shimmer: a gradient travelling across the screen is
 * the kind of motion that reads as a glitch on a projector, and it costs a
 * repaint every frame for content nobody is looking at yet. The pulse is
 * switched off wholesale under prefers-reduced-motion by app.css.
 *
 * Dimensions come from the CALLER, through ordinary class fallthrough:
 * `<Skeleton class="h-4 w-1/3" />`. Declaring a `class` prop instead would
 * shadow Vue's own class merging, which is a trap for the next person who
 * writes the obvious thing and finds it silently ignored.
 */
defineProps({
    /** Round, for an avatar or chip placeholder. */
    circle: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="animate-pulse bg-surface-2"
        :class="circle ? 'rounded-full' : 'rounded-md'"
        aria-hidden="true"
    />
</template>
