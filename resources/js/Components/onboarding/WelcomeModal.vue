<script setup>
/**
 * The one-time welcome, greeting the new owner by name.
 *
 * Shown on the first dashboard page an account lands on — UNLESS that page is
 * the agents screen with its checklist, which turns the same greeting into a
 * banner instead. The server decides which: claiming the banner also stamps the
 * account welcomed, so by the time this component is asked, its `welcome` prop
 * is already null. One greeting per arrival, never two.
 */
import { ref } from 'vue';
import Modal from '../ui/Modal.vue';
import Button from '../ui/Button.vue';
import VeloraMark from '../VeloraMark.vue';

defineProps({
    name: { type: String, required: true },
    agentsUrl: { type: String, required: true },
});

const open = ref(true);
</script>

<template>
    <Modal :open="open" :title="$t('onboarding.welcome.title', { name })" @close="open = false">
        <div class="text-center">
            <VeloraMark class="mx-auto h-11 w-auto" />
            <p class="mt-5 text-overline uppercase text-primary-text">{{ $t('onboarding.welcome.eyebrow') }}</p>
            <p class="mt-3 text-body-sm leading-relaxed text-muted">{{ $t('onboarding.welcome.body') }}</p>
        </div>

        <template #footer>
            <Button variant="secondary" @click="open = false">{{ $t('onboarding.welcome.later') }}</Button>
            <Button @click="open = false; $inertia.visit(agentsUrl)">{{ $t('onboarding.welcome.cta') }}</Button>
        </template>
    </Modal>
</template>
