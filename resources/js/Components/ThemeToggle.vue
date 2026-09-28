<script setup>
/**
 * Light/dark toggle.
 *
 * The state lives on <html>, put there before first paint by the root
 * template's inline script — so this reads the DOM on mount rather than holding
 * its own idea of the theme, which could only ever disagree with the class that
 * is actually painting the page.
 *
 * When someone is signed in the choice is also written to their account, so it
 * follows them between devices. A guest on the auth pages has no account to
 * write to; localStorage is the whole record there.
 */
import { onMounted, ref } from 'vue';
import { IconMoon, IconSun } from '@tabler/icons-vue';
import { isDark, toggleTheme } from '../stage-mode';

const props = defineProps({
    /** The per-account persistence endpoint. Omit for guests. */
    themeUrl: { type: String, default: null },
});

const dark = ref(false);

onMounted(() => {
    dark.value = isDark();
});

const flip = () => {
    dark.value = toggleTheme(props.themeUrl);
};
</script>

<template>
    <button
        type="button"
        :aria-pressed="dark"
        :aria-label="$t('common.toggle_theme')"
        :title="$t('common.toggle_theme')"
        class="flex h-9 w-9 items-center justify-center rounded-lg border border-border text-muted transition-colors duration-fast hover:bg-surface-2 hover:text-text"
        @click="flip"
    >
        <!-- The icon shows what you will GET, not what you have — the
             convention every OS uses for this control. -->
        <IconMoon v-if="!dark" :size="18" :stroke-width="1.75" />
        <IconSun v-else :size="18" :stroke-width="1.75" />
    </button>
</template>
