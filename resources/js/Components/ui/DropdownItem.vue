<script setup>
/**
 * One row of a Dropdown. A <button> by default, or a link when given an href,
 * so a menu entry that navigates is a real link — middle-clickable, and
 * announced as a link rather than as a button that happens to move you.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, default: null },
    icon: { type: [Object, Function], default: null },
    danger: { type: Boolean, default: false },
});
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : 'button'"
        role="menuitem"
        class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-body-sm transition-colors duration-fast hover:bg-surface-2"
        :class="danger ? 'text-danger' : 'text-text'"
    >
        <component
            :is="icon"
            v-if="icon"
            :size="17"
            :stroke-width="1.75"
            class="shrink-0"
            :class="danger ? 'text-danger' : 'text-muted'"
            aria-hidden="true"
        />
        <slot />
    </component>
</template>
