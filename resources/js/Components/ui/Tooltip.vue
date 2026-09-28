<script setup>
/**
 * A short hint attached to a control.
 *
 * Shown on hover AND on keyboard focus — a tooltip that only appears on hover
 * is invisible to anyone navigating by keyboard, which is the most common way
 * this kind of hint gets lost.
 *
 * It is NOT the `title` attribute: that renders in the OS's own style, ignores
 * the theme, waits a second before appearing and cannot be read by touch. It
 * also is not a place to put information that is needed — a tooltip is by
 * definition hidden most of the time, so anything essential belongs in the
 * label instead.
 */
import { ref, useId } from 'vue';

defineProps({
    text: { type: String, required: true },
    placement: { type: String, default: 'top' },
});

const open = ref(false);
const id = `tooltip-${useId()}`;

const placements = {
    top: 'bottom-full left-1/2 -translate-x-1/2 mb-2',
    bottom: 'top-full left-1/2 -translate-x-1/2 mt-2',
    left: 'right-full top-1/2 -translate-y-1/2 mr-2',
    right: 'left-full top-1/2 -translate-y-1/2 ml-2',
};
</script>

<template>
    <span
        class="relative inline-flex"
        @mouseenter="open = true"
        @mouseleave="open = false"
        @focusin="open = true"
        @focusout="open = false"
    >
        <span :aria-describedby="open ? id : undefined" class="inline-flex">
            <slot />
        </span>

        <span
            v-if="open"
            :id="id"
            role="tooltip"
            class="pointer-events-none absolute z-50 w-max max-w-xs rounded-md border border-border bg-surface px-2.5 py-1.5 text-caption text-text shadow-e3"
            :class="placements[placement] ?? placements.top"
        >
            {{ text }}
        </span>
    </span>
</template>
