<script setup>
/**
 * A live feed of the conversations the agents are handling right now.
 *
 * This is the one panel that shows the PRODUCT working rather than reporting on
 * it. A jury reads four KPI numbers and believes them or not; it reads a real
 * customer question with the agent's reply underneath and understands what was
 * built.
 *
 * It polls the existing `conversations.list` endpoint rather than new dashboard
 * data, so the panel and the inbox are the same view of the same thing instead
 * of two differently-shaped copies that can disagree. No backend change.
 *
 * IT DELIBERATELY DOES NOT CALL `conversations.show`, which would give a full
 * transcript and is the obvious thing to reach for. That endpoint stamps
 * `operator_read_at` — opening a conversation is what marks it read, which is
 * correct for the inbox and completely wrong here: a dashboard left open on a
 * second monitor would quietly mark every incoming conversation as read and
 * empty the unread badge without anyone having looked at anything. `list` is
 * read-only and already carries the last message as `preview`.
 *
 * Polls every 20 seconds, and only while the tab is visible.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { IconArrowRight, IconMessageCircle } from '@tabler/icons-vue';
import Sparkle from '../Sparkle.vue';
import EmptyState from '../ui/EmptyState.vue';
import Skeleton from '../ui/Skeleton.vue';

const conversations = ref([]);
const loading = ref(true);

let timer = null;

async function load() {
    try {
        const response = await fetch(route('conversations.list'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return;

        const payload = await response.json();
        conversations.value = (payload.data ?? []).slice(0, 4);
    } catch (error) {
        /* transient — the next tick retries */
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    load();
    timer = setInterval(() => {
        if (document.visibilityState === 'visible') load();
    }, 20000);
});

onBeforeUnmount(() => clearInterval(timer));

const channelColour = {
    telegram: 'bg-channel-telegram',
    whatsapp: 'bg-channel-whatsapp',
    greenapi: 'bg-channel-whatsapp',
    instagram: 'bg-channel-instagram',
};
</script>

<template>
    <section class="flex flex-col rounded-xl border border-border bg-surface shadow-e1">
        <header class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 text-h4 text-text">
                    <Sparkle class="h-4 w-4 shrink-0 text-sparkle-ink" label="AI" />
                    {{ $t('dashboard.live_conversation.title') }}
                </h3>
                <p class="mt-0.5 text-caption text-muted">
                    {{ $t('dashboard.live_conversation.description') }}
                </p>
            </div>

            <Link
                :href="route('conversations.index')"
                class="flex shrink-0 items-center gap-1 text-caption font-medium text-primary-text hover:underline"
            >
                {{ $t('dashboard.live_conversation.open') }}
                <IconArrowRight :size="14" :stroke-width="2" aria-hidden="true" />
            </Link>
        </header>

        <div class="flex-1">
            <div v-if="loading" class="space-y-3 px-5 py-4">
                <Skeleton class="h-12 w-full" />
                <Skeleton class="h-12 w-full" />
                <Skeleton class="h-12 w-full" />
            </div>

            <ul v-else-if="conversations.length" class="divide-y divide-border">
                <li v-for="item in conversations" :key="item.id" class="px-5 py-3">
                    <div class="flex items-center gap-2">
                        <span
                            class="h-2 w-2 shrink-0 rounded-full"
                            :class="channelColour[item.channel] ?? 'bg-chart-1'"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate text-body-sm font-medium text-text">
                            {{ item.customer }}
                        </span>
                        <span v-if="item.unread" class="shrink-0 rounded-pill bg-primary-tint px-2 py-0.5 text-caption font-medium text-primary-text">
                            {{ $t('dashboard.live_conversation.unread') }}
                        </span>
                    </div>

                    <!-- The agent's own words get the sparkle-tinted rail, so
                         it is visible at a glance which side the AI is on. -->
                    <p
                        class="mt-1 line-clamp-2 border-l-2 pl-2.5 text-caption text-muted"
                        :class="item.preview_role === 'assistant' ? 'border-sparkle-ink' : 'border-border-strong'"
                    >
                        {{ item.preview || '—' }}
                    </p>
                </li>
            </ul>

            <EmptyState
                v-else
                :icon="IconMessageCircle"
                :title="$t('dashboard.live_conversation.empty')"
            />
        </div>
    </section>
</template>
