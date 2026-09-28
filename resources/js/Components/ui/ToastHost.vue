<script setup>
/**
 * Renders whatever is in the toast store. Mounted once per layout.
 *
 * `aria-live="polite"` on the container rather than on each toast, so a screen
 * reader announces arrivals without the region being re-created each time.
 * Every toast carries an icon and a word as well as its colour — the same rule
 * as Badge, for the same reason.
 */
import { IconAlertTriangle, IconCheck, IconInfoCircle, IconX } from '@tabler/icons-vue';
import { dismiss, useToasts } from '../../toast';

const toasts = useToasts();

const styles = {
    success: { class: 'border-success bg-success-tint text-success', icon: IconCheck },
    danger: { class: 'border-danger bg-danger-tint text-danger', icon: IconAlertTriangle },
    warning: { class: 'border-warning bg-warning-tint text-warning', icon: IconAlertTriangle },
    info: { class: 'border-border bg-surface text-text', icon: IconInfoCircle },
};
</script>

<template>
    <div
        class="pointer-events-none fixed left-1/2 top-4 z-[100] flex w-[calc(100%-2rem)] max-w-sm -translate-x-1/2 flex-col gap-2"
        role="status"
        aria-live="polite"
        aria-atomic="false"
    >
        <div
            v-for="item in toasts.items"
            :key="item.id"
            class="pointer-events-auto flex items-start gap-2.5 rounded-xl border px-4 py-3 text-body-sm font-medium shadow-e3"
            :class="(styles[item.type] ?? styles.info).class"
        >
            <component
                :is="(styles[item.type] ?? styles.info).icon"
                :size="17"
                :stroke-width="2"
                class="mt-0.5 shrink-0"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1">{{ item.message }}</span>
            <button
                type="button"
                class="-mr-1 -mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md opacity-70 transition-opacity hover:opacity-100"
                :aria-label="$t('common.close')"
                @click="dismiss(item.id)"
            >
                <IconX :size="15" :stroke-width="2" />
            </button>
        </div>
    </div>
</template>
