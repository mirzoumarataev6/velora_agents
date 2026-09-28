<script setup>
/**
 * The sparkle — this product's AI symbol.
 *
 * The same four-pointed star that sits in the Velora mark, lifted out and
 * normalised to its own box. It marks a moment where the agent did something:
 * a suggested reply, an AI-written insight, a generated persona.
 *
 * RESERVED. It carries --color-sparkle (#38BDF8), which the design system
 * allows nowhere else — using it to decorate an ordinary control is precisely
 * what would make the AI signal stop meaning anything. See docs/DESIGN.md.
 *
 * Defaults to currentColor so it inherits whatever the caller set, which on a
 * light ground must be the ink cut (--color-sparkle-ink): #38BDF8 measures
 * 1.86:1 on the canvas and is unreadable there.
 */
defineProps({
    /** Accessible name. Omit for a decorative sparkle beside existing text. */
    label: { type: String, default: null },
});
</script>

<template>
    <svg viewBox="0 0 240.0 223.0" fill="currentColor"
        :role="label ? 'img' : undefined"
        :aria-label="label ?? undefined"
        :aria-hidden="label ? undefined : 'true'">
        <path d="M113.0 0.0 L106.0 24.0 L98.0 42.0 L87.0 59.0 L70.0 77.0 L41.0 96.0 L2.0 109.0 L0.0 112.0 L2.0 115.0 L39.0 127.0 L68.0 145.0 L88.0 166.0 L98.0 182.0 L106.0 200.0 L112.0 223.0 L120.0 223.0 L126.0 201.0 L135.0 181.0 L150.0 159.0 L164.0 145.0 L193.0 127.0 L213.0 120.0 L238.0 115.0 L240.0 113.0 L239.0 109.0 L218.0 105.0 L196.0 98.0 L180.0 90.0 L162.0 77.0 L146.0 60.0 L136.0 45.0 L127.0 26.0 L120.0 0.0 Z" />
    </svg>
</template>
