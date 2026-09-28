<script setup>
/**
 * A tab strip.
 *
 * Implements the ARIA tabs pattern properly, which mostly means the keyboard:
 * arrow keys move between tabs, Home/End jump to the ends, and only the
 * selected tab is in the tab order (roving tabindex) so Tab moves OUT of the
 * strip to the panel rather than walking through every tab.
 *
 * The selected tab is marked by a 2px underline AND a weight change — not by
 * colour alone, which at projector distance is the difference between obvious
 * and invisible.
 */
import { ref } from 'vue';

const props = defineProps({
    /** [{ value, label, badge? }] */
    tabs: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
});

const emit = defineEmits(['update:modelValue']);
const strip = ref(null);

function move(offset) {
    const index = props.tabs.findIndex((tab) => tab.value === props.modelValue);
    const next = (index + offset + props.tabs.length) % props.tabs.length;
    emit('update:modelValue', props.tabs[next].value);
    strip.value?.querySelectorAll('[role="tab"]')[next]?.focus();
}

function jump(to) {
    const index = to === 'start' ? 0 : props.tabs.length - 1;
    emit('update:modelValue', props.tabs[index].value);
    strip.value?.querySelectorAll('[role="tab"]')[index]?.focus();
}
</script>

<template>
    <div
        ref="strip"
        role="tablist"
        class="flex gap-1 overflow-x-auto border-b border-border"
        @keydown.right.prevent="move(1)"
        @keydown.left.prevent="move(-1)"
        @keydown.home.prevent="jump('start')"
        @keydown.end.prevent="jump('end')"
    >
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            role="tab"
            :aria-selected="tab.value === modelValue"
            :tabindex="tab.value === modelValue ? 0 : -1"
            class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 text-body-sm transition-colors duration-fast"
            :class="
                tab.value === modelValue
                    ? 'border-primary font-semibold text-primary-text'
                    : 'border-transparent font-medium text-muted hover:border-border-strong hover:text-text'
            "
            @click="emit('update:modelValue', tab.value)"
        >
            {{ tab.label }}
            <span
                v-if="tab.badge !== undefined"
                class="rounded-pill bg-surface-2 px-1.5 py-0.5 text-caption font-medium text-muted"
            >
                {{ tab.badge }}
            </span>
        </button>
    </div>
</template>
