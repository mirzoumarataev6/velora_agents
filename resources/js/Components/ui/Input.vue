<script setup>
/**
 * A labelled text input — label, optional leading icon, error, hint.
 *
 * Errors come from two places and this treats them identically: the `error`
 * prop (server-side, arriving as an Inertia `errors` prop after a failed
 * submit) and whatever the parent puts there from its own real-time checks. A
 * field does not care which produced the message, and keeping one channel means
 * a server error cannot end up rendered differently from a client one.
 *
 * Accessibility, none of it optional:
 *   · the label is a real <label for>, not a placeholder standing in for one
 *   · aria-invalid marks the state, aria-describedby points at the message
 *   · the message is aria-live, so it is announced when it appears
 *   · the error is text AND colour AND an icon — never colour alone
 *
 * The message slot keeps its height whether or not there is a message, so a
 * form does not jump as fields validate under the cursor.
 *
 * A password field gets a show/hide button automatically. That button reports
 * state with aria-pressed rather than by swapping an icon only.
 */
import { computed, ref } from 'vue';
import { IconAlertCircle, IconEye, IconEyeOff } from '@tabler/icons-vue';

const props = defineProps({
    id: { type: String, required: true },
    /** Submitted field name. Defaults to the id, which is the usual case. */
    name: { type: String, default: null },
    label: { type: String, required: true },
    modelValue: { type: String, default: '' },
    type: { type: String, default: 'text' },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    autocomplete: { type: String, default: null },
    placeholder: { type: String, default: null },
    required: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false },
    /** A @tabler/icons-vue component rendered inside the field, on the left. */
    icon: { type: [Object, Function], default: null },
});

const emit = defineEmits(['update:modelValue', 'blur']);

const revealed = ref(false);

const isPassword = computed(() => props.type === 'password');
const inputType = computed(() => (isPassword.value && revealed.value ? 'text' : props.type));
const messageId = computed(() => `${props.id}-message`);
</script>

<template>
    <div>
        <label :for="id" class="block text-caption font-medium text-text">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>

        <div class="relative mt-1.5">
            <component
                :is="icon"
                v-if="icon"
                :size="18"
                :stroke-width="1.75"
                class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted"
                aria-hidden="true"
            />

            <input
                :id="id"
                :name="name ?? id"
                :value="modelValue"
                :type="inputType"
                :autocomplete="autocomplete"
                :placeholder="placeholder"
                :required="required"
                :autofocus="autofocus"
                :aria-invalid="error ? 'true' : 'false'"
                :aria-describedby="messageId"
                class="field w-full py-2.5 text-body-sm"
                :class="[
                    icon ? 'pl-10' : 'pl-3',
                    isPassword ? 'pr-11' : 'pr-3',
                    error ? '!border-danger' : '',
                ]"
                @input="emit('update:modelValue', $event.target.value)"
                @blur="emit('blur')"
            />

            <button
                v-if="isPassword"
                type="button"
                :aria-pressed="revealed"
                :aria-label="revealed ? $t('auth.login.hide_password') : $t('auth.login.show_password')"
                class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-muted transition-colors duration-fast hover:bg-surface-2 hover:text-text"
                @click="revealed = !revealed"
            >
                <IconEyeOff v-if="revealed" :size="17" :stroke-width="1.75" />
                <IconEye v-else :size="17" :stroke-width="1.75" />
            </button>
        </div>

        <!-- Reserved height, so validating a field never shifts the form. -->
        <p :id="messageId" class="mt-1 flex min-h-5 items-start gap-1 text-caption" aria-live="polite">
            <template v-if="error">
                <IconAlertCircle :size="15" :stroke-width="2" class="mt-px shrink-0 text-danger" aria-hidden="true" />
                <span class="text-danger">{{ error }}</span>
            </template>
            <span v-else-if="hint" class="text-muted">{{ hint }}</span>
        </p>
    </div>
</template>
