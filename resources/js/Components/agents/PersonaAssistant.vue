<script setup>
/**
 * "Help me fill this in" — a few plain questions, then a drafted persona.
 *
 * It exists because the two instruction fields are the hardest part of setting
 * up an agent: an owner knows their business perfectly and has never had to
 * write a system prompt. Asking five concrete questions and drafting from the
 * answers turns a blank textarea into an edit.
 *
 * The draft is explicitly A DRAFT — badged as one, inserted into the fields
 * rather than saved, and edited from there. It is never applied silently:
 * the owner is the one who decides what their agent says.
 */
import { computed, ref } from 'vue';
import { IconSparkles } from '@tabler/icons-vue';
import Button from '../ui/Button.vue';
import Modal from '../ui/Modal.vue';
import Textarea from '../ui/Textarea.vue';
import { translate } from '../../i18n';
import { toast } from '../../toast';

const props = defineProps({
    agentSlug: { type: String, default: null },
    name: { type: String, default: '' },
    description: { type: String, default: '' },
});

const emit = defineEmits(['apply']);

const QUESTIONS = ['priority', 'forbidden', 'phrases', 'angry', 'extra'];

const open = ref(false);
const index = ref(0);
const answers = ref({});
const generating = ref(false);
const draft = ref(null);

const current = computed(() => QUESTIONS[index.value]);
const isLast = computed(() => index.value === QUESTIONS.length - 1);

function start() {
    if (!props.name.trim()) {
        toast(translate('agents.persona_assistant.errors.name_required'), { type: 'danger' });

        return;
    }

    index.value = 0;
    draft.value = null;
    open.value = true;
}

async function generate() {
    generating.value = true;

    try {
        const response = await fetch(route('agents.persona-assistant'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                agent: props.agentSlug,
                name: props.name,
                description: props.description,
                answers: answers.value,
            }),
        });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            toast(body.message ?? translate('agents.persona_assistant.errors.failed'), { type: 'danger' });

            return;
        }

        draft.value = await response.json();
    } catch (error) {
        toast(translate('agents.persona_assistant.errors.network'), { type: 'danger' });
    } finally {
        generating.value = false;
    }
}

function apply() {
    emit('apply', { rules: draft.value?.rules ?? '', persona: draft.value?.persona ?? '' });
    open.value = false;
}
</script>

<template>
    <div>
        <Button variant="secondary" size="sm" @click="start">
            <IconSparkles :size="15" :stroke-width="1.75" class="text-sparkle-ink" aria-hidden="true" />
            {{ $t('agents.persona_assistant.button') }}
        </Button>

        <Modal
            :open="open"
            :title="$t('agents.persona_assistant.title')"
            :description="draft ? $t('agents.persona_assistant.draft_note') : $t('agents.persona_assistant.intro')"
            size="lg"
            @close="open = false"
        >
            <!-- The questions, one at a time. All of them are skippable: an
                 owner who has nothing to say about one should not be blocked. -->
            <div v-if="!draft">
                <p class="mb-2 text-caption text-muted">
                    {{ $t('agents.persona_assistant.step', { current: index + 1, total: QUESTIONS.length }) }}
                </p>

                <Textarea
                    :id="`pa-${current}`"
                    :model-value="answers[current] ?? ''"
                    :label="$t(`agents.persona_assistant.questions.${current}`)"
                    :placeholder="$t(`agents.persona_assistant.placeholders.${current}`)"
                    :hint="$t('agents.persona_assistant.optional')"
                    :rows="4"
                    @update:model-value="(value) => (answers[current] = value)"
                />
            </div>

            <div v-else class="space-y-4">
                <p class="inline-flex items-center gap-1.5 rounded-pill border border-sparkle-ink bg-sparkle-tint px-2.5 py-0.5 text-caption font-medium text-sparkle-ink">
                    <IconSparkles :size="14" :stroke-width="2" aria-hidden="true" />
                    {{ $t('agents.persona_assistant.draft_badge') }}
                </p>

                <div>
                    <h4 class="mb-1 text-caption font-medium text-text">
                        {{ $t('agents.persona_assistant.rules_preview') }}
                    </h4>
                    <p class="whitespace-pre-line rounded-lg border border-border bg-surface-2 p-3 text-body-sm text-text">
                        {{ draft.rules }}
                    </p>
                </div>

                <div>
                    <h4 class="mb-1 text-caption font-medium text-text">
                        {{ $t('agents.persona_assistant.persona_preview') }}
                    </h4>
                    <p class="whitespace-pre-line rounded-lg border border-border bg-surface-2 p-3 text-body-sm text-text">
                        {{ draft.persona }}
                    </p>
                </div>
            </div>

            <template #footer>
                <template v-if="draft">
                    <Button variant="secondary" @click="draft = null">
                        {{ $t('agents.persona_assistant.retry') }}
                    </Button>
                    <Button @click="apply">{{ $t('agents.persona_assistant.apply') }}</Button>
                </template>

                <template v-else>
                    <Button v-if="index > 0" variant="secondary" @click="index--">
                        {{ $t('agents.persona_assistant.back') }}
                    </Button>
                    <Button v-if="!isLast" @click="index++">{{ $t('agents.persona_assistant.next') }}</Button>
                    <Button v-else :loading="generating" @click="generate">
                        {{ generating ? $t('agents.persona_assistant.generating') : $t('agents.persona_assistant.generate') }}
                    </Button>
                </template>
            </template>
        </Modal>
    </div>
</template>
