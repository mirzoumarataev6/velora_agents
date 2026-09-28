<script setup>
/**
 * Create or edit an agent — a five-step wizard.
 *
 * Steps rather than one long form because the fields are genuinely different
 * decisions: what the business is, how the agent should behave, how replies are
 * shaped, whether it may negotiate, and a final confirmation. Asked all at once
 * they read as a wall; asked in order they read as a setup.
 *
 * ONE form object across all five steps, posted once at the end. A step is a
 * view onto the same data, not a save point — a half-created agent is not a
 * thing this product has.
 *
 * A validation error REOPENS the step it belongs to, because an error message
 * on a step nobody is looking at is an error message nobody sees.
 */
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconCheck } from '@tabler/icons-vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import Button from '../../Components/ui/Button.vue';
import Card from '../../Components/ui/Card.vue';
import Checkbox from '../../Components/ui/Checkbox.vue';
import Input from '../../Components/ui/Input.vue';
import Select from '../../Components/ui/Select.vue';
import Switch from '../../Components/ui/Switch.vue';
import Textarea from '../../Components/ui/Textarea.vue';
import PersonaAssistant from '../../Components/agents/PersonaAssistant.vue';
import { translate } from '../../i18n';

const props = defineProps({
    /** Null when creating. */
    agent: { type: Object, default: null },
});

const editing = computed(() => props.agent !== null);

const form = useForm({
    name: props.agent?.name ?? '',
    description: props.agent?.description ?? '',
    tone: props.agent?.tone ?? 'friendly',
    rules_instructions: props.agent?.rules_instructions ?? '',
    custom_system_prompt: props.agent?.custom_system_prompt ?? '',
    greeting_message: props.agent?.greeting_message ?? '',
    fallback_message: props.agent?.fallback_message ?? '',
    on_not_found: props.agent?.on_not_found ?? 'apologize',
    escalation: props.agent?.escalation ?? 'conservative',
    proactivity: props.agent?.proactivity ?? 'subtle',
    max_length: props.agent?.max_length ?? null,
    use_emojis: props.agent?.use_emojis ?? false,
    haggling_enabled: props.agent?.haggling_enabled ?? false,
    haggling_level: props.agent?.haggling_level ?? 'moderate',
    haggle_min_price_percent: props.agent?.haggle_min_price_percent ?? 100,
    haggling_instructions: props.agent?.haggling_instructions ?? '',
});

const STEPS = 5;
const step = ref(1);

/** Which step each field lives on, so an error can reopen the right one. */
const stepOfField = {
    name: 1,
    description: 1,
    tone: 1,
    rules_instructions: 2,
    custom_system_prompt: 2,
    greeting_message: 3,
    fallback_message: 3,
    on_not_found: 3,
    escalation: 3,
    proactivity: 3,
    max_length: 3,
    use_emojis: 3,
    haggling_enabled: 4,
    haggling_level: 4,
    haggle_min_price_percent: 4,
    haggling_instructions: 4,
};

watch(
    () => form.errors,
    (errors) => {
        const first = Object.keys(errors)[0];
        if (first && stepOfField[first]) step.value = stepOfField[first];
    },
    { deep: true }
);

const options = (key, values) =>
    computed(() => values.map((value) => ({ value, label: translate(`agents.form.${key}_options.${value}`) })));

const toneOptions = options('tone', ['friendly', 'professional', 'casual', 'formal']);
const notFoundOptions = options('on_not_found', ['apologize', 'human_handoff', 'suggest_alternatives']);
const escalationOptions = options('escalation', ['off', 'conservative', 'balanced', 'sensitive']);
const proactivityOptions = options('proactivity', ['off', 'subtle', 'active']);
const haggleLevelOptions = options('haggling_level', ['soft', 'moderate', 'firm']);

function submit() {
    if (editing.value) {
        form.put(route('agents.update', props.agent.slug));
    } else {
        form.post(route('agents.store'));
    }
}

/** The assistant writes both instruction fields; the owner edits from there. */
function applyDraft({ rules, persona }) {
    if (rules) form.rules_instructions = rules;
    if (persona) form.custom_system_prompt = persona;
    step.value = 2;
}
</script>

<template>
    <AppLayout :title="editing ? $t('agents.form.heading_edit') : $t('agents.form.heading_create')">
        <Link
            :href="editing ? route('agents.show', agent.slug) : route('agents.index')"
            class="mb-4 inline-flex items-center gap-1.5 text-body-sm font-medium text-muted transition-colors duration-fast hover:text-text"
        >
            <IconArrowLeft :size="16" :stroke-width="1.75" aria-hidden="true" />
            {{ $t('agents.form.back') }}
        </Link>

        <h1 class="mb-5 text-h1 text-text">
            {{ editing ? $t('agents.form.heading_edit') : $t('agents.form.heading_create') }}
        </h1>

        <!-- The stepper. Position is a number AND a filled state, so "where am
             I" survives at projector distance. -->
        <ol class="mb-6 flex items-center gap-2" :aria-label="$t('agents.form.step1_heading')">
            <li v-for="n in STEPS" :key="n" class="flex flex-1 items-center gap-2">
                <span
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-caption font-semibold tabular-nums"
                    :class="
                        n < step
                            ? 'bg-success-fill text-white'
                            : n === step
                              ? 'bg-primary text-on-primary'
                              : 'border border-border-strong text-muted'
                    "
                    :aria-current="n === step ? 'step' : undefined"
                >
                    <IconCheck v-if="n < step" :size="14" :stroke-width="3" aria-hidden="true" />
                    <template v-else>{{ n }}</template>
                </span>
                <span v-if="n < STEPS" class="h-px flex-1" :class="n < step ? 'bg-success-fill' : 'bg-border'" />
            </li>
        </ol>

        <form @submit.prevent="submit">
            <!-- ===== 1 · the business ===== -->
            <Card v-show="step === 1">
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('agents.form.step1_heading') }}</h2>
                </template>

                <div class="max-w-xl space-y-1">
                    <Input
                        id="name"
                        v-model="form.name"
                        :label="$t('agents.form.name_label')"
                        :placeholder="$t('agents.form.name_placeholder')"
                        :error="form.errors.name"
                        required
                        autofocus
                    />
                    <Textarea
                        id="description"
                        v-model="form.description"
                        :label="$t('agents.form.description_label')"
                        :placeholder="$t('agents.form.description_placeholder')"
                        :error="form.errors.description"
                        :rows="4"
                    />
                    <Select
                        id="tone"
                        v-model="form.tone"
                        :label="$t('agents.form.tone_label')"
                        :options="toneOptions"
                        :hint="$t('agents.form.preset_help')"
                        :error="form.errors.tone"
                    />
                </div>
            </Card>

            <!-- ===== 2 · instructions ===== -->
            <Card v-show="step === 2">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-h4 text-text">{{ $t('agents.form.step2_heading') }}</h2>
                        <PersonaAssistant
                            :agent-slug="agent?.slug ?? null"
                            :name="form.name"
                            :description="form.description"
                            @apply="applyDraft"
                        />
                    </div>
                </template>

                <!-- Rules ABOVE persona: rules are the concrete "if X then Y"
                     an owner actually has in mind, and asking for character
                     first sends them writing prose they then have to redo. -->
                <div class="max-w-2xl space-y-1">
                    <Textarea
                        id="rules_instructions"
                        v-model="form.rules_instructions"
                        :label="$t('agents.form.rules_label')"
                        :placeholder="$t('agents.form.rules_placeholder')"
                        :hint="$t('agents.form.rules_help')"
                        :error="form.errors.rules_instructions"
                        :rows="8"
                    />
                    <Textarea
                        id="custom_system_prompt"
                        v-model="form.custom_system_prompt"
                        :label="$t('agents.form.prompt_label')"
                        :placeholder="$t('agents.form.prompt_placeholder')"
                        :hint="$t('agents.form.prompt_help')"
                        :error="form.errors.custom_system_prompt"
                        :rows="6"
                    />
                </div>
            </Card>

            <!-- ===== 3 · replies ===== -->
            <Card v-show="step === 3">
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('agents.form.step3_heading') }}</h2>
                </template>

                <div class="max-w-xl space-y-1">
                    <Textarea
                        id="greeting_message"
                        v-model="form.greeting_message"
                        :label="$t('agents.form.greeting_label')"
                        :placeholder="$t('agents.form.greeting_placeholder')"
                        :error="form.errors.greeting_message"
                        :rows="2"
                    />
                    <Textarea
                        id="fallback_message"
                        v-model="form.fallback_message"
                        :label="$t('agents.form.fallback_label')"
                        :placeholder="$t('agents.form.fallback_placeholder')"
                        :error="form.errors.fallback_message"
                        :rows="2"
                    />
                    <Select
                        id="on_not_found"
                        v-model="form.on_not_found"
                        :label="$t('agents.form.on_not_found_label')"
                        :options="notFoundOptions"
                        :error="form.errors.on_not_found"
                    />
                    <Select
                        id="escalation"
                        v-model="form.escalation"
                        :label="$t('agents.form.escalation_label')"
                        :options="escalationOptions"
                        :hint="$t('agents.form.escalation_help')"
                        :error="form.errors.escalation"
                    />
                    <Select
                        id="proactivity"
                        v-model="form.proactivity"
                        :label="$t('agents.form.proactivity_label')"
                        :options="proactivityOptions"
                        :hint="$t('agents.form.proactivity_help')"
                        :error="form.errors.proactivity"
                    />
                    <Input
                        id="max_length"
                        :model-value="form.max_length === null ? '' : String(form.max_length)"
                        :label="$t('agents.form.max_length_label')"
                        :error="form.errors.max_length"
                        @update:model-value="(value) => (form.max_length = value === '' ? null : Number(value))"
                    />
                    <Checkbox
                        id="use_emojis"
                        v-model="form.use_emojis"
                        :label="$t('agents.form.use_emojis_label')"
                    />
                </div>
            </Card>

            <!-- ===== 4 · haggling ===== -->
            <Card v-show="step === 4">
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('agents.form.step4_heading') }}</h2>
                    <p class="mt-0.5 max-w-prose text-caption text-muted">
                        {{ $t('agents.form.haggling_section_description') }}
                    </p>
                </template>

                <div class="max-w-xl space-y-4">
                    <Switch
                        id="haggling_enabled"
                        v-model="form.haggling_enabled"
                        :label="$t('agents.form.haggling_label')"
                        :hint="$t('agents.form.haggling_help')"
                    />

                    <template v-if="form.haggling_enabled">
                        <Select
                            id="haggling_level"
                            v-model="form.haggling_level"
                            :label="$t('agents.form.haggling_level_label')"
                            :options="haggleLevelOptions"
                            :hint="$t('agents.form.haggling_level_help')"
                            :error="form.errors.haggling_level"
                        />

                        <!-- The floor is authored HERE and nowhere else: a
                             number in the free-text instructions below does not
                             move it. -->
                        <Input
                            id="haggle_min_price_percent"
                            :model-value="String(form.haggle_min_price_percent ?? '')"
                            :label="$t('agents.form.haggle_floor_label') + ', ' + $t('agents.form.haggle_floor_unit')"
                            :hint="$t('agents.form.haggle_floor_help')"
                            :error="form.errors.haggle_min_price_percent"
                            @update:model-value="(value) => (form.haggle_min_price_percent = value === '' ? null : Number(value))"
                        />

                        <Textarea
                            id="haggling_instructions"
                            v-model="form.haggling_instructions"
                            :label="$t('agents.form.haggling_instructions_label')"
                            :placeholder="$t('agents.form.haggling_instructions_placeholder')"
                            :hint="$t('agents.form.haggle_floor_authority')"
                            :error="form.errors.haggling_instructions"
                            :rows="6"
                        />
                    </template>
                </div>
            </Card>

            <!-- ===== 5 · confirm ===== -->
            <Card v-show="step === 5">
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('agents.form.step5_heading') }}</h2>
                </template>

                <p class="max-w-prose text-body-sm text-muted">
                    {{ editing ? $t('agents.form.confirm_edit') : $t('agents.form.confirm_create') }}
                </p>
            </Card>

            <div class="mt-5 flex items-center justify-between gap-3">
                <Button v-if="step > 1" variant="secondary" @click="step--">
                    {{ $t('agents.form.back_button') }}
                </Button>
                <span v-else />

                <Button v-if="step < STEPS" @click="step++">{{ $t('agents.form.next') }}</Button>
                <Button v-else type="submit" :loading="form.processing">
                    {{ editing ? $t('agents.form.save') : $t('agents.form.create_submit') }}
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
