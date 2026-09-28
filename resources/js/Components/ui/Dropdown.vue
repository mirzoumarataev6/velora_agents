<script setup>
/**
 * A menu hung off a trigger.
 *
 * Closing is handled by a full-screen sibling overlay rather than a document
 * click listener. A listener has to guess whether a click landed "inside", gets
 * that wrong the moment something inside stops propagation, and fires for every
 * click on the page for as long as the menu is open. The overlay cannot be
 * fooled: anything that is not the menu is the overlay.
 *
 * Escape closes it too, because a menu that can only be dismissed with the
 * mouse is a trap for a keyboard.
 */
import { ref } from 'vue';

defineProps({
    align: { type: String, default: 'right' },
});

const open = ref(false);

defineExpose({ close: () => { open.value = false; } });
</script>

<template>
    <div class="relative inline-flex">
        <span
            :aria-expanded="open"
            aria-haspopup="menu"
            class="inline-flex"
            @click="open = !open"
            @keydown.escape="open = false"
        >
            <slot name="trigger" :open="open" />
        </span>

        <div v-if="open" class="fixed inset-0 z-30" @click="open = false" />

        <div
            v-if="open"
            role="menu"
            class="absolute top-full z-40 mt-2 min-w-48 overflow-hidden rounded-xl border border-border bg-surface py-1 shadow-e3"
            :class="align === 'right' ? 'right-0' : 'left-0'"
            @keydown.escape="open = false"
            @click="open = false"
        >
            <slot :close="() => (open = false)" />
        </div>
    </div>
</template>
