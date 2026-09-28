<script setup>
/**
 * The sign-up greeting, as a card above the checklist.
 *
 * A standalone card rather than a strip inside the checklist: the greeting is a
 * separate thing that happens to arrive at the same time, and it leaves on its
 * own without the checklist moving.
 *
 * It lasts exactly one page view. Nothing is persisted about dismissing it —
 * the server spent the greeting when it rendered this page, so reloading or
 * navigating away simply ends it.
 */
import { ref } from 'vue';
import { IconX } from '@tabler/icons-vue';
import Sparkle from '../Sparkle.vue';

defineProps({
    name: { type: String, required: true },
});

const shown = ref(true);
</script>

<template>
    <div
        v-if="shown"
        class="flex items-start gap-3 rounded-xl border border-primary bg-primary-tint px-5 py-4"
        role="status"
    >
        <Sparkle class="mt-0.5 h-5 w-5 shrink-0 text-sparkle-ink" aria-hidden="true" />

        <div class="min-w-0 flex-1">
            <p class="text-overline uppercase text-primary-text">{{ $t('onboarding.welcome.eyebrow') }}</p>
            <h2 class="mt-1 text-h4 text-text">{{ $t('onboarding.welcome.title', { name }) }}</h2>
            <p class="mt-1 text-body-sm text-muted">{{ $t('onboarding.welcome.body') }}</p>
        </div>

        <button
            type="button"
            class="-mr-1 -mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted transition-colors duration-fast hover:bg-surface-2 hover:text-text"
            :aria-label="$t('onboarding.welcome.close')"
            @click="shown = false"
        >
            <IconX :size="17" :stroke-width="2" />
        </button>
    </div>
</template>
