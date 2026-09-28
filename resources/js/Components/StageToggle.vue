<script setup>
/**
 * Stage mode — the presentation setting.
 *
 * Raises the root font size, darkens muted text, doubles border weight and
 * switches shadows and transparency off, so the product survives a projector in
 * a lit hall. The values are all token swaps in resources/css/tokens.css; this
 * only flips the attribute that selects them.
 *
 * `aria-pressed` carries the state and the label says what the button will DO
 * next, so the state is never signalled by appearance alone — the same rule the
 * rest of the system follows for status colours.
 */
import { onMounted, ref } from 'vue';
import { IconPresentation } from '@tabler/icons-vue';
import { isStage, toggleStage } from '../stage-mode';

const on = ref(false);

onMounted(() => {
    on.value = isStage();
});

const flip = () => {
    on.value = toggleStage();
};
</script>

<template>
    <button
        type="button"
        :aria-pressed="on"
        :aria-label="on ? $t('agents.nav.stage_off') : $t('agents.nav.stage_on')"
        :title="on ? $t('agents.nav.stage_off') : $t('agents.nav.stage_on')"
        class="flex h-9 w-9 items-center justify-center rounded-lg border transition-colors duration-fast"
        :class="
            on
                ? 'border-primary bg-primary text-on-primary'
                : 'border-border text-muted hover:bg-surface-2 hover:text-text'
        "
        @click="flip"
    >
        <IconPresentation :size="18" :stroke-width="1.75" />
    </button>
</template>
