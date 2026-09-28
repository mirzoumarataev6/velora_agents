<script setup>
/**
 * Multi-line text, with the same label/error/hint contract as Input.
 *
 * `rows` rather than autosizing: a textarea that grows as you type reflows
 * everything below it, which on a form being demonstrated live is a visible
 * jolt for no benefit.
 */
import { computed } from 'vue';
import { IconAlertCircle } from '@tabler/icons-vue';

const props = defineProps({
    id: { type: String, required: true },
    name: { type: String, default: null },
    label: { type: String, required: true },
    modelValue: { type: String, default: '' },
    rows: { type: Number, default: 4 },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
    placeholder: { type: String, default: null },
});

defineEmits(['update:modelValue', 'blur']);

const messageId = computed(() => `${props.id}-message`);
</script>

<template>
    <div>
        <label :for="id" class="block text-caption font-medium text-text">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>

        <textarea
            :id="id"
            :name="name ?? id"
            :value="modelValue"
            :rows="rows"
            :required="required"
            :placeholder="placeholder"
            :aria-invalid="error ? 'true' : 'false'"
            :aria-describedby="messageId"
            class="field mt-1.5 w-full resize-y px-3 py-2.5 text-body-sm"
            :class="error ? '!border-danger' : ''"
            @input="$emit('update:modelValue', $event.target.value)"
            @blur="$emit('blur')"
        />

        <p :id="messageId" class="mt-1 flex min-h-5 items-start gap-1 text-caption" aria-live="polite">
            <template v-if="error">
                <IconAlertCircle :size="15" :stroke-width="2" class="mt-px shrink-0 text-danger" aria-hidden="true" />
                <span class="text-danger">{{ error }}</span>
            </template>
            <span v-else-if="hint" class="text-muted">{{ hint }}</span>
        </p>
    </div>
</template>
