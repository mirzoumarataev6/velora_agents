<script setup>
/**
 * Диалог → заявка → продажа, as bars on a shared baseline.
 *
 * The stage labels and hints come from the translation file keyed by the
 * stage's `key`, so adding a stage in the metrics service does not need a
 * parallel edit here.
 */
import { computed } from 'vue';
import Card from '../ui/Card.vue';
import Funnel from '../ui/Funnel.vue';
import { translate } from '../../i18n';

const props = defineProps({
    funnel: { type: Array, required: true },
});

const stages = computed(() =>
    props.funnel.map((stage) => ({
        label: translate(`dashboard.funnel.${stage.key}`),
        value: stage.value,
        hint: translate(`dashboard.funnel.${stage.key}_hint`),
    }))
);
</script>

<template>
    <Card>
        <template #header>
            <h3 class="text-h4 text-text">{{ $t('dashboard.funnel.title') }}</h3>
            <p class="mt-0.5 text-caption text-muted">{{ $t('dashboard.funnel.description') }}</p>
        </template>

        <Funnel :stages="stages" />
    </Card>
</template>
