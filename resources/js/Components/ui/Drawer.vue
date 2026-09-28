<script setup>
/**
 * A panel that slides in from the edge.
 *
 * The same dialog contract as Modal — escape, focus handling, locked
 * background — but anchored to a side, for content that is long or that the
 * reader wants to consult against the page behind it (a conversation
 * transcript, a filter set).
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { IconX } from '@tabler/icons-vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    side: { type: String, default: 'right' },
});

const emit = defineEmits(['close']);
const panel = ref(null);
let previouslyFocused = null;

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            previouslyFocused = document.activeElement;
            document.body.style.overflow = 'hidden';
            await nextTick();
            panel.value?.focus();
        } else {
            document.body.style.overflow = '';
            previouslyFocused?.focus?.();
        }
    }
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50" @keydown.escape="emit('close')">
            <div class="absolute inset-0 bg-slate-950/60" @click="emit('close')" />

            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="absolute inset-y-0 flex w-full max-w-md flex-col border-border bg-surface shadow-e4"
                :class="side === 'right' ? 'right-0 border-l' : 'left-0 border-r'"
            >
                <header class="flex shrink-0 items-center justify-between gap-4 border-b border-border px-5 py-4">
                    <h2 class="truncate text-h4 text-text">{{ title }}</h2>
                    <button
                        type="button"
                        class="-mr-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted transition-colors duration-fast hover:bg-surface-2 hover:text-text"
                        :aria-label="$t('common.close')"
                        @click="emit('close')"
                    >
                        <IconX :size="18" :stroke-width="1.75" />
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="flex shrink-0 justify-end gap-2 border-t border-border px-5 py-3.5">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
