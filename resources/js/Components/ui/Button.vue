<script setup>
/**
 * The product's button.
 *
 * Variants carry meaning, not decoration: `primary` is the one action a screen
 * is about, `secondary` everything else, `danger` the destructive one. The
 * 60-30-10 rule means at most one primary per screen — if a screen seems to
 * need two, one of them is secondary.
 *
 * No glow, no gradient, no lift on hover. Those read as a smear at projector
 * gamma and add nothing on a laptop; hover is a colour step, which survives
 * both. The focus ring is the system's 2px primary, inherited from app.css.
 *
 * While `loading`, the button keeps its label and adds a spinner rather than
 * swapping the text out — a button whose width changes mid-submit makes the
 * layout jump at exactly the moment someone is watching it.
 */
import { computed } from 'vue';

const props = defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
});

const variants = {
    primary: 'bg-primary text-on-primary hover:bg-primary-hover active:bg-primary-pressed border-primary',
    secondary: 'bg-surface text-text border-border hover:bg-surface-2',
    danger: 'bg-danger text-white border-danger hover:opacity-90',
    ghost: 'bg-transparent text-muted border-transparent hover:bg-surface-2 hover:text-text',
};

const sizes = {
    // 13px is the system's type floor, so `sm` shrinks the box rather than the
    // label — a button whose text drops to 11px is unreadable across a room.
    sm: 'px-3 py-1.5 text-caption',
    md: 'px-4 py-2.5 text-body-sm',
    lg: 'px-5 py-3 text-body',
};

const classes = computed(() => variants[props.variant] ?? variants.primary);
const sizeClasses = computed(() => sizes[props.size] ?? sizes.md);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading ? 'true' : 'false'"
        class="inline-flex items-center justify-center gap-2 rounded-md border font-medium transition-colors duration-fast disabled:cursor-not-allowed disabled:opacity-60"
        :class="[classes, sizeClasses, block ? 'w-full' : '']"
    >
        <span
            v-if="loading"
            class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
            aria-hidden="true"
        />
        <slot />
    </button>
</template>
