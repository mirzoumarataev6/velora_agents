<script setup>
/**
 * The frame around a chart.
 *
 * THE TITLE IS AN INSIGHT, NOT A LABEL. "Telegram drove 62% of leads" rather
 * than "Leads by channel" — the label makes a reader do the work of finding the
 * point, which at the back of a hall they will not do. The axis labels already
 * say what the data is; the title is where the chart says what it MEANS.
 *
 * `sparkle` marks a title the agent wrote itself, which is the one place an AI
 * claim appears on a dashboard.
 */
import Sparkle from '../Sparkle.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    /** Marks the title as AI-generated. Reserved — see docs/DESIGN.md. */
    sparkle: { type: Boolean, default: false },
});
</script>

<template>
    <section class="rounded-xl border border-border bg-surface shadow-e1">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0">
                <h3 class="flex items-center gap-2 text-h4 text-text">
                    <Sparkle v-if="sparkle" class="h-4 w-4 shrink-0 text-sparkle-ink" :label="'AI'" />
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="mt-0.5 text-caption text-muted">{{ subtitle }}</p>
            </div>

            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div class="p-5">
            <slot />
        </div>

        <!-- The legend sits BELOW the plot and is part of the card, not of the
             chart library, so every chart in the product legends the same way. -->
        <footer v-if="$slots.legend" class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-border px-5 py-3">
            <slot name="legend" />
        </footer>
    </section>
</template>
