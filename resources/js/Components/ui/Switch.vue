<script setup>
/**
 * An on/off toggle for a setting that applies immediately.
 *
 * Use a Checkbox when the change is only committed on submit; use this when
 * flipping it IS the action. That distinction is the whole reason both exist.
 *
 * role="switch" with aria-checked, so the state is announced as on/off rather
 * than as a pressed button — and the state is also written out beside it, so it
 * never depends on reading the knob's position at projector distance.
 */
defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: null },
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Words for the two states. Shown beside the control. */
    onLabel: { type: String, default: null },
    offLabel: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex items-start justify-between gap-4">
        <label :for="id" class="min-w-0" :class="disabled && 'opacity-60'">
            <span class="block text-body-sm font-medium text-text">{{ label }}</span>
            <span v-if="hint" class="block text-caption text-muted">{{ hint }}</span>
        </label>

        <div class="flex shrink-0 items-center gap-2">
            <span v-if="onLabel || offLabel" class="text-caption font-medium" :class="modelValue ? 'text-success' : 'text-muted'">
                {{ modelValue ? onLabel : offLabel }}
            </span>

            <button
                :id="id"
                type="button"
                role="switch"
                :aria-checked="modelValue"
                :disabled="disabled"
                class="relative h-6 w-11 shrink-0 rounded-pill border transition-colors duration-fast disabled:opacity-60"
                :class="modelValue ? 'border-primary bg-primary' : 'border-input-border bg-surface-2'"
                @click="$emit('update:modelValue', !modelValue)"
            >
                <span
                    class="absolute top-0.5 h-4.5 w-4.5 rounded-full bg-surface shadow-e1 transition-[left] duration-fast"
                    :class="modelValue ? 'left-[1.375rem]' : 'left-0.5'"
                    aria-hidden="true"
                />
            </button>
        </div>
    </div>
</template>
