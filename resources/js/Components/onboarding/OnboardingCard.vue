<script setup>
/**
 * The first-run checklist: create an agent, teach it, connect a channel, try it.
 *
 * Only the FIRST incomplete step gets a button. A checklist where every row is
 * equally actionable is a menu, not a path — and the whole point here is to
 * answer "what do I do next?" with exactly one thing.
 *
 * Finishing earns one congratulations panel and then the card is gone for good.
 * The stamp that makes that true is written server-side when the finished state
 * renders (AgentController@index), so dismissing is only ever a local collapse.
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { IconCheck } from '@tabler/icons-vue';
import Button from '../ui/Button.vue';
import Card from '../ui/Card.vue';

const props = defineProps({
    onboarding: { type: Object, required: true },
});

const collapsed = ref(false);

/** The one step that gets a button. */
const nextStep = computed(() => props.onboarding.steps.find((step) => !step.done)?.key ?? null);

function skip() {
    collapsed.value = true;
    // Fire-and-forget: the card is already gone locally, and a failed request
    // costs only that it reappears on the next full load.
    router.delete(route('onboarding.dismiss'), { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Card v-if="!collapsed" id="onboarding">
        <!-- Shown exactly once — see the note above. -->
        <div v-if="onboarding.celebrate" class="py-4 text-center">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-success-tint text-success">
                <IconCheck :size="24" :stroke-width="2" aria-hidden="true" />
            </span>
            <h2 class="mt-4 text-h4 text-text">{{ $t('onboarding.complete.title') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-body-sm text-muted">{{ $t('onboarding.complete.body') }}</p>
            <Button variant="secondary" size="sm" class="mt-4" @click="collapsed = true">
                {{ $t('onboarding.complete.cta') }}
            </Button>
        </div>

        <div v-else>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-h4 text-text">{{ $t('onboarding.checklist.title') }}</h2>
                    <p class="mt-0.5 text-body-sm text-muted">{{ $t('onboarding.checklist.subtitle') }}</p>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-caption tabular-nums text-muted">
                        {{ $t('onboarding.checklist.progress', { done: onboarding.completed, total: onboarding.total }) }}
                    </span>
                    <button
                        type="button"
                        class="text-caption font-medium text-muted transition-colors duration-fast hover:text-text"
                        @click="skip"
                    >
                        {{ $t('onboarding.checklist.skip') }}
                    </button>
                </div>
            </div>

            <div
                class="mt-4 h-2 overflow-hidden rounded-pill bg-surface-2"
                role="progressbar"
                :aria-valuenow="onboarding.completed"
                aria-valuemin="0"
                :aria-valuemax="onboarding.total"
            >
                <div class="h-full rounded-pill bg-primary transition-[width] duration-slow" :style="{ width: `${onboarding.percent}%` }" />
            </div>

            <ol class="mt-4 space-y-3">
                <li v-for="step in onboarding.steps" :key="step.key" class="flex items-start gap-3">
                    <!-- Filled disc when done, dashed ring when not: the state is
                         a shape as well as a colour. -->
                    <span
                        class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full"
                        :class="step.done ? 'bg-success-fill text-white' : 'border border-dashed border-border-strong'"
                    >
                        <IconCheck v-if="step.done" :size="13" :stroke-width="3" aria-hidden="true" />
                        <span v-if="step.done" class="sr-only">{{ $t('onboarding.checklist.done_label') }}</span>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-body-sm font-medium" :class="step.done ? 'text-muted' : 'text-text'">
                            {{ $t(`onboarding.steps.${step.key}.title`) }}
                        </p>
                        <p v-if="!step.done" class="mt-0.5 text-caption text-muted">
                            {{ $t(`onboarding.steps.${step.key}.description`) }}
                        </p>
                    </div>

                    <Link
                        v-if="step.key === nextStep"
                        :href="step.cta"
                        class="shrink-0 rounded-md border border-primary bg-primary px-3 py-1.5 text-caption font-medium text-on-primary transition-colors duration-fast hover:bg-primary-hover"
                    >
                        {{ $t(`onboarding.steps.${step.key}.cta`) }}
                    </Link>
                </li>
            </ol>
        </div>
    </Card>
</template>
