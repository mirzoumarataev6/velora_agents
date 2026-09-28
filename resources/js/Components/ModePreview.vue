<script setup>
/**
 * Renders the same demo three times — light, dark and stage — side by side.
 *
 * This is the whole reason tokens.css scopes its themes to `.dark` and
 * `[data-mode="stage"]` rather than to `:root.dark`: an unanchored selector
 * lets a nested element open a theme island, so three panes on one page can
 * each be in a different mode.
 *
 * Side by side rather than behind a toggle, deliberately. A component is
 * reviewed by COMPARING its modes — a muted label that is fine in light and
 * disappears in dark is obvious in a row of three and invisible when you have
 * to click between them and remember what you saw.
 *
 * Each pane paints `bg-canvas` explicitly, because a transparent island would
 * inherit the page's own background and the dark pane would silently look
 * light.
 */
defineProps({
    /** A short label above the row, naming what is being shown. */
    title: { type: String, default: null },
    description: { type: String, default: null },
});

const modes = [
    { key: 'light', label: 'Light', class: '', attrs: {} },
    { key: 'dark', label: 'Dark', class: 'dark', attrs: {} },
    { key: 'stage', label: 'Stage', class: '', attrs: { 'data-mode': 'stage' } },
];
</script>

<template>
    <section class="mb-8">
        <div v-if="title" class="mb-3">
            <h3 class="text-h4 text-text">{{ title }}</h3>
            <p v-if="description" class="mt-0.5 max-w-prose text-caption text-muted">{{ description }}</p>
        </div>

        <div class="grid gap-3 lg:grid-cols-3">
            <div v-for="mode in modes" :key="mode.key" class="min-w-0">
                <p class="mb-1.5 text-overline uppercase text-muted">{{ mode.label }}</p>

                <div
                    class="overflow-hidden rounded-xl border border-border bg-canvas p-4"
                    :class="mode.class"
                    v-bind="mode.attrs"
                >
                    <slot :mode="mode.key" />
                </div>
            </div>
        </div>
    </section>
</template>
