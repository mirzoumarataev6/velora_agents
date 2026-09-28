<script setup>
/**
 * A centred dialog.
 *
 * Three things a hand-rolled modal usually gets wrong, all handled here:
 *   · Escape closes it
 *   · focus moves INTO the dialog on open and back to the trigger on close,
 *     and Tab is trapped inside while it is open
 *   · the page behind cannot be scrolled
 *
 * The backdrop is a solid tint rather than a blur — glassmorphism is out, and a
 * blurred backdrop is also the single most expensive thing a modal can do to a
 * projector's frame rate.
 */
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { IconX } from '@tabler/icons-vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: null },
    size: { type: String, default: 'md' },
});

const emit = defineEmits(['close']);

const panel = ref(null);
let previouslyFocused = null;

const sizes = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' };

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            previouslyFocused = document.activeElement;
            document.body.style.overflow = 'hidden';
            await nextTick();

            // NOT `focusable()[0]?.focus() ?? panel.focus()`: .focus() always
            // returns undefined, so the ?? branch would fire every time and
            // immediately pull focus back off the control it had just given it
            // to. Pick the target first, then focus it once.
            const first = focusable()[0] ?? panel.value;
            first?.focus();
        } else {
            document.body.style.overflow = '';
            previouslyFocused?.focus?.();
        }
    }
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});

function focusable() {
    return Array.from(
        panel.value?.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
        ) ?? []
    );
}

/** Keep Tab inside the dialog by wrapping at both ends. */
function trap(event) {
    const items = focusable();
    if (items.length === 0) return;

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            @keydown.escape="emit('close')"
            @keydown.tab="trap"
        >
            <div class="absolute inset-0 bg-slate-950/60" @click="emit('close')" />

            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="relative w-full overflow-hidden rounded-xl border border-border bg-surface shadow-e4"
                :class="sizes[size] ?? sizes.md"
            >
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                    <div class="min-w-0">
                        <h2 class="text-h4 text-text">{{ title }}</h2>
                        <p v-if="description" class="mt-0.5 text-caption text-muted">{{ description }}</p>
                    </div>

                    <button
                        type="button"
                        class="-mr-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted transition-colors duration-fast hover:bg-surface-2 hover:text-text"
                        :aria-label="$t('common.close')"
                        @click="emit('close')"
                    >
                        <IconX :size="18" :stroke-width="1.75" />
                    </button>
                </header>

                <div class="px-5 py-4">
                    <slot />
                </div>

                <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-border px-5 py-3.5">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
