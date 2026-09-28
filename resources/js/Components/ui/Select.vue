<script setup>
/**
 * A native <select>, deliberately.
 *
 * A custom listbox would match the rest of the system more exactly, and it
 * would also be the component most likely to be subtly broken for a keyboard
 * or a screen reader — and on a phone it would replace the OS picker with
 * something worse. The native control is styled through `.field` and inherits
 * the document's colour-scheme, so its popup follows the theme.
 */
import { computed } from 'vue';
import { IconAlertCircle, IconChevronDown } from '@tabler/icons-vue';

const props = defineProps({
    id: { type: String, required: true },
    name: { type: String, default: null },
    label: { type: String, required: true },
    modelValue: { type: [String, Number], default: '' },
    /** [{ value, label }] */
    options: { type: Array, default: () => [] },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const messageId = computed(() => `${props.id}-message`);
</script>

<template>
    <div>
        <label :for="id" class="block text-caption font-medium text-text">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>

        <div class="relative mt-1.5">
            <select
                :id="id"
                :name="name ?? id"
                :value="modelValue"
                :required="required"
                :aria-invalid="error ? 'true' : 'false'"
                :aria-describedby="messageId"
                class="field w-full appearance-none py-2.5 pl-3 pr-10 text-body-sm"
                :class="error ? '!border-danger' : ''"
                @change="$emit('update:modelValue', $event.target.value)"
            >
                <option v-for="option in options" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>

            <IconChevronDown
                :size="17"
                :stroke-width="1.75"
                class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted"
                aria-hidden="true"
            />
        </div>

        <p :id="messageId" class="mt-1 flex min-h-5 items-start gap-1 text-caption" aria-live="polite">
            <template v-if="error">
                <IconAlertCircle :size="15" :stroke-width="2" class="mt-px shrink-0 text-danger" aria-hidden="true" />
                <span class="text-danger">{{ error }}</span>
            </template>
            <span v-else-if="hint" class="text-muted">{{ hint }}</span>
        </p>
    </div>
</template>
