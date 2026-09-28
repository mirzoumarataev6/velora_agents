<script setup>
/**
 * The shell for sign-in, sign-up and password recovery.
 *
 * A single centred column rather than the marketing split-panel the product
 * used to have on the right: the page has one job, and a decorative half-screen
 * is the first thing that goes illegible on a projector while costing the form
 * the width that makes it comfortable.
 *
 * The chrome is deliberately the same three controls as the app's topbar —
 * language, theme, stage — so someone presenting can put the product into stage
 * mode before signing in rather than discovering the toggle afterwards.
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LocaleSwitcher from '../Components/LocaleSwitcher.vue';
import StageToggle from '../Components/StageToggle.vue';
import ThemeToggle from '../Components/ThemeToggle.vue';
import VeloraMark from '../Components/VeloraMark.vue';
import ToastHost from '../Components/ui/ToastHost.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});

const page = usePage();
const status = computed(() => page.props.flash?.status);
</script>

<template>
    <Head :title="title" />

    <ToastHost />

    <div class="flex min-h-screen flex-col bg-canvas">
        <a
            href="#main-content"
            class="sr-only rounded-md bg-primary px-4 py-2 text-body-sm font-medium text-on-primary focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50"
        >
            {{ $t('common.skip_to_content') }}
        </a>

        <header class="flex h-16 shrink-0 items-center justify-between px-4 sm:px-6">
            <Link :href="route('welcome', { locale: $page.props.locale.current })" class="flex items-center gap-2.5" :aria-label="$t('common.brand')">
                <VeloraMark class="h-7 w-auto" />
                <span class="text-body font-semibold text-text">{{ $t('common.brand') }}</span>
            </Link>

            <div class="flex items-center gap-2">
                <LocaleSwitcher />
                <ThemeToggle />
                <StageToggle />
            </div>
        </header>

        <main id="main-content" class="flex flex-1 items-center justify-center px-4 py-8 sm:px-6">
            <div class="w-full max-w-md">
                <!-- Flash first: a "we sent you a link" message that renders
                     below the form is a message nobody reads. -->
                <div
                    v-if="status"
                    class="mb-5 rounded-xl border border-success bg-success-tint px-4 py-3 text-body-sm font-medium text-success"
                    role="status"
                >
                    {{ status }}
                </div>

                <div class="rounded-xl border border-border bg-surface p-6 shadow-e1 sm:p-8">
                    <h1 class="text-h2 text-text">{{ title }}</h1>
                    <p v-if="subtitle" class="mt-1 text-body-sm text-muted">{{ subtitle }}</p>

                    <slot />
                </div>

                <slot name="below" />
            </div>
        </main>

        <noscript>
            <div class="fixed inset-x-0 bottom-0 z-50 bg-danger px-4 py-3 text-center text-body-sm font-medium text-white">
                {{ $t('common.js_required') }}
            </div>
        </noscript>
    </div>
</template>
