<script setup>
/**
 * A status chip.
 *
 * THE RULE THIS COMPONENT EXISTS TO ENFORCE: colour is never the only signal.
 * Every variant pairs a fill with a readable border AND carries text; an icon
 * slot is there for the cases where the word alone is ambiguous. A pastel-only
 * badge — the kind whose meaning lives entirely in its hue — is exactly what
 * the design system forbids, because it says nothing to a colourblind reader
 * and less than nothing through a projector.
 *
 * `sparkle` is reserved for AI moments. See docs/DESIGN.md.
 */
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'neutral' },
    /** A @tabler/icons-vue component rendered before the label. */
    icon: { type: [Object, Function], default: null },
});

const variants = {
    neutral: 'border-border bg-surface-2 text-muted',
    primary: 'border-primary bg-primary-tint text-primary-text',
    success: 'border-success bg-success-tint text-success',
    warning: 'border-warning bg-warning-tint text-warning',
    danger: 'border-danger bg-danger-tint text-danger',
    sparkle: 'border-sparkle-ink bg-sparkle-tint text-sparkle-ink',
};

const classes = computed(() => variants[props.variant] ?? variants.neutral);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-pill border px-2.5 py-0.5 text-caption font-medium"
        :class="classes"
    >
        <component :is="icon" v-if="icon" :size="14" :stroke-width="2" class="shrink-0" aria-hidden="true" />
        <slot />
    </span>
</template>
