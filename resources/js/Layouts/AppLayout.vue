<script setup>
/**
 * The signed-in shell: navy rail on the left, light topbar across the content.
 *
 * Tabler's structure, at this product's density — the rail is the fixed anchor
 * in both themes, the bar carries only view-level controls, and the content
 * sits on the canvas colour with cards providing their own surface.
 *
 * Pages give it a `title`, which becomes both the document title and the
 * heading in the bar, so the two can never disagree.
 */
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import AppSidebar from '../Components/AppSidebar.vue';
import AppTopbar from '../Components/AppTopbar.vue';
import ToastHost from '../Components/ui/ToastHost.vue';
import WelcomeModal from '../Components/onboarding/WelcomeModal.vue';
import { toast } from '../toast';

defineProps({
    title: { type: String, required: true },
});

const page = usePage();
const status = computed(() => page.props.flash?.status);

/**
 * The arrival greeting. It is spent by the server on the response that carries
 * it, so raising it here can only ever happen once — and it is a toast rather
 * than a banner because it is a pleasantry, not something to act on.
 *
 * Deferred a beat so it reads as arriving rather than as already having been
 * there when the page painted.
 */
onMounted(() => {
    const greeting = page.props.flash?.greeting;

    if (greeting) {
        setTimeout(() => toast(greeting), 350);
    }
});
</script>

<template>
    <Head :title="title" />

    <ToastHost />

    <!-- Null unless this response owes the owner their one-time welcome; the
         agents screen claims it as a banner instead, which nulls this. -->
    <WelcomeModal
        v-if="page.props.welcome"
        :name="page.props.welcome"
        :agents-url="route('agents.index')"
    />

    <div class="flex min-h-screen bg-canvas">
        <AppSidebar />

        <div class="flex min-w-0 flex-1 flex-col">
            <AppTopbar :title="title" />

            <main id="main-content" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <div
                    v-if="status"
                    class="mb-6 rounded-xl border border-success bg-success-tint px-4 py-3 text-body-sm font-medium text-success"
                    role="status"
                >
                    {{ status }}
                </div>

                <slot />
            </main>
        </div>
    </div>
</template>
