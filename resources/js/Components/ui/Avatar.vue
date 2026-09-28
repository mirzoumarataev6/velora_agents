<script setup>
/**
 * A person, as initials or a picture.
 *
 * Initials rather than a generated illustration: they are legible at 24px and
 * on a projector, they never need a network request, and they cannot be the
 * wrong person's face.
 */
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    initials: { type: String, default: null },
    src: { type: String, default: null },
    size: { type: String, default: 'md' },
});

const sizes = {
    sm: 'h-7 w-7 text-caption',
    md: 'h-9 w-9 text-body-sm',
    lg: 'h-12 w-12 text-body',
};

const fallback = computed(
    () => props.initials
        ?? props.name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
);
const classes = computed(() => sizes[props.size] ?? sizes.md);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center overflow-hidden rounded-lg bg-primary-tint font-semibold text-primary-text"
        :class="classes"
        :title="name || undefined"
    >
        <img v-if="src" :src="src" :alt="name" class="h-full w-full object-cover" />
        <template v-else>{{ fallback }}</template>
    </span>
</template>
