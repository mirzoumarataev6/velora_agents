<script setup>
/**
 * The surface everything else sits on.
 *
 * A card is a 1px border and an opaque background — never a shadow alone. On a
 * projector a soft shadow is invisible, so a "card" defined only by elevation
 * simply disappears and the page becomes one undifferentiated field. The
 * shadow here is a hint for a laptop; the border is what carries the boundary,
 * and stage mode doubles it.
 */
defineProps({
    /** Tightens padding for dense contexts (a card inside a card). */
    compact: { type: Boolean, default: false },
    /** Drops the padding entirely — for a card wrapping a full-bleed table. */
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="rounded-xl border border-border bg-surface shadow-e1">
        <header v-if="$slots.header" class="border-b border-border px-5 py-4">
            <slot name="header" />
        </header>

        <div :class="flush ? '' : compact ? 'p-4' : 'p-5'">
            <slot />
        </div>

        <footer v-if="$slots.footer" class="border-t border-border px-5 py-3.5">
            <slot name="footer" />
        </footer>
    </section>
</template>
