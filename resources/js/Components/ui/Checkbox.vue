<script setup>
/**
 * A checkbox and its label, as one clickable target.
 *
 * The native input is kept and styled rather than hidden behind a drawn box:
 * it brings the keyboard behaviour, the indeterminate state and the
 * announcement for free, and a drawn replacement is where those quietly go
 * missing.
 */
defineProps({
    id: { type: String, required: true },
    name: { type: String, default: null },
    label: { type: String, required: true },
    hint: { type: String, default: null },
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex items-start gap-2.5">
        <input
            :id="id"
            :name="name ?? id"
            type="checkbox"
            :checked="modelValue"
            :disabled="disabled"
            class="mt-0.5 h-4 w-4 shrink-0 rounded border-input-border text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60"
            @change="$emit('update:modelValue', $event.target.checked)"
        />
        <label :for="id" class="min-w-0" :class="disabled && 'opacity-60'">
            <span class="block text-body-sm text-text">{{ label }}</span>
            <span v-if="hint" class="block text-caption text-muted">{{ hint }}</span>
        </label>
    </div>
</template>
