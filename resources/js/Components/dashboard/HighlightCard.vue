<script setup>
/**
 * The one thing to do right now.
 *
 * Every other card on this page reports; this one asks. It is the single
 * attention-grabbing element the 60-30-10 rule allows per screen, which is why
 * nothing else here is filled with the primary colour.
 *
 * The metrics service picks WHICH signal by priority (a customer waiting on a
 * price beats an unanswered question); this only renders whichever came back.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { IconArrowRight, IconCircleCheck, IconSparkles } from '@tabler/icons-vue';
import { translate } from '../../i18n';

const props = defineProps({
    highlight: { type: Object, default: null },
    afterHours: { type: Object, default: null },
});

const routes = {
    price_approvals: 'leads.index',
    thumbs_down: 'conversations.index',
    unanswered: 'agents.index',
    product_gap: 'agents.index',
    open_orders: 'leads.index',
};

const body = computed(() => {
    if (!props.highlight) return null;

    const { kind, count, subject } = props.highlight;

    return {
        title: translate(`dashboard.highlight.${kind}`, { count, subject: subject ?? '' }),
        hint: translate(`dashboard.highlight.${kind}_hint`, { count, subject: subject ?? '' }),
        action: translate(`dashboard.highlight.${kind}_action`),
        href: route(routes[kind] ?? 'leads.index'),
    };
});
</script>

<template>
    <section
        v-if="body"
        class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-primary bg-primary-tint px-5 py-4"
    >
        <div class="min-w-0">
            <p class="text-overline uppercase text-primary-text">{{ $t('dashboard.highlight.eyebrow') }}</p>
            <h2 class="mt-1 text-h4 text-text">{{ body.title }}</h2>
            <p class="mt-0.5 text-body-sm text-muted">{{ body.hint }}</p>
        </div>

        <Link
            :href="body.href"
            class="flex shrink-0 items-center gap-2 rounded-md border border-primary bg-primary px-4 py-2.5 text-body-sm font-medium text-on-primary transition-colors duration-fast hover:bg-primary-hover"
        >
            {{ body.action }}
            <IconArrowRight :size="16" :stroke-width="2" aria-hidden="true" />
        </Link>
    </section>

    <!-- Nothing to act on is itself worth saying. An owner who sees an empty
         space cannot tell "all clear" from "this widget is broken". -->
    <section v-else class="flex flex-wrap items-center gap-3 rounded-xl border border-success bg-success-tint px-5 py-4">
        <IconCircleCheck :size="22" :stroke-width="1.75" class="shrink-0 text-success" aria-hidden="true" />
        <div class="min-w-0">
            <h2 class="text-h4 text-success">{{ $t('dashboard.highlight.clear_title') }}</h2>
            <p class="mt-0.5 text-body-sm text-muted">{{ $t('dashboard.highlight.clear') }}</p>
        </div>

        <p
            v-if="afterHours?.messages > 0"
            class="ml-auto flex items-center gap-2 text-caption text-muted"
        >
            <IconSparkles :size="15" :stroke-width="1.75" class="text-sparkle-ink" aria-hidden="true" />
            {{ $t('dashboard.highlight.after_hours', { count: afterHours.messages }) }}
        </p>
    </section>
</template>
