<script setup>
/**
 * The agents list — the panel's working front door.
 *
 * Onboarding sits ABOVE the heading rather than instead of it: the grid below
 * stays exactly as it is for anyone who has finished or skipped setup, so the
 * page never becomes two different screens depending on account age.
 */
import { Link } from '@inertiajs/vue3';
import { IconBook2, IconMessageCircle, IconPlus, IconPlug, IconUsers } from '@tabler/icons-vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import Badge from '../../Components/ui/Badge.vue';
import Button from '../../Components/ui/Button.vue';
import EmptyState from '../../Components/ui/EmptyState.vue';
import PageHeader from '../../Components/ui/PageHeader.vue';
import OnboardingCard from '../../Components/onboarding/OnboardingCard.vue';
import WelcomeBanner from '../../Components/onboarding/WelcomeBanner.vue';

defineProps({
    agents: { type: Array, required: true },
    onboarding: { type: Object, required: true },
    /** The owner's name when this page owes them a sign-up banner. */
    banner: { type: String, default: null },
});

const initial = (name) => (name ?? '').trim().charAt(0).toUpperCase();
</script>

<template>
    <AppLayout :title="$t('agents.index.heading')">
        <div v-if="onboarding.visible" class="mb-6 space-y-4">
            <WelcomeBanner v-if="banner" :name="banner" />
            <OnboardingCard :onboarding="onboarding" />
        </div>

        <PageHeader :title="$t('agents.index.heading')" :description="$t('agents.index.subheading')">
            <template #actions>
                <Link
                    :href="route('agents.create')"
                    class="inline-flex items-center gap-2 rounded-md border border-primary bg-primary px-4 py-2.5 text-body-sm font-medium text-on-primary transition-colors duration-fast hover:bg-primary-hover"
                >
                    <IconPlus :size="16" :stroke-width="2" aria-hidden="true" />
                    {{ $t('agents.index.create') }}
                </Link>
            </template>
        </PageHeader>

        <div v-if="agents.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface">
            <EmptyState
                :icon="IconUsers"
                :title="$t('agents.index.empty_title')"
                :description="$t('agents.index.empty_description')"
            >
                <template #action>
                    <Button @click="$inertia.visit(route('agents.create'))">
                        {{ $t('agents.index.empty_cta') }}
                    </Button>
                </template>
            </EmptyState>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="agent in agents"
                :key="agent.id"
                :href="route('agents.show', agent.slug)"
                class="group flex flex-col rounded-xl border border-border bg-surface p-5 shadow-e1 transition-colors duration-fast hover:border-border-strong"
            >
                <div class="flex items-start justify-between gap-2">
                    <span
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-primary-tint text-h4 font-semibold text-primary-text"
                        aria-hidden="true"
                    >
                        {{ initial(agent.name) }}
                    </span>

                    <!-- Status is colour AND a dot AND a word, never one of the
                         three on its own. -->
                    <Badge :variant="agent.status === 'active' ? 'success' : 'neutral'">
                        <span
                            class="h-1.5 w-1.5 rounded-full"
                            :class="agent.status === 'active' ? 'bg-success-fill' : 'bg-muted'"
                            aria-hidden="true"
                        />
                        {{ agent.status === 'active' ? $t('agents.index.status_active') : $t('agents.index.status_paused') }}
                    </Badge>
                </div>

                <h3 class="mt-4 text-h4 text-text group-hover:text-primary-text">{{ agent.name }}</h3>
                <p class="mt-1 line-clamp-2 text-body-sm text-muted">
                    {{ agent.description || $t('agents.index.no_description') }}
                </p>

                <div class="mt-4 flex items-center gap-4 border-t border-border pt-4 text-caption text-muted">
                    <span class="flex items-center gap-1.5" :title="$t('agents.show.tabs.knowledge')">
                        <IconBook2 :size="15" :stroke-width="1.75" aria-hidden="true" />
                        <span class="tabular-nums">{{ agent.knowledge_sources_count }}</span>
                    </span>
                    <span class="flex items-center gap-1.5" :title="$t('agents.show.tabs.channels')">
                        <IconPlug :size="15" :stroke-width="1.75" aria-hidden="true" />
                        <span class="tabular-nums">{{ agent.channels_count }}</span>
                    </span>
                    <span class="flex items-center gap-1.5" :title="$t('agents.show.tabs.conversations')">
                        <IconMessageCircle :size="15" :stroke-width="1.75" aria-hidden="true" />
                        <span class="tabular-nums">{{ agent.conversations_count }}</span>
                    </span>
                </div>
            </Link>
        </div>
    </AppLayout>
</template>
