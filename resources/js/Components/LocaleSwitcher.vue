<script setup>
/**
 * Language switch — ru / tg / en.
 *
 * Under Blade this needed a bespoke engine: every locale's strings embedded in
 * the page and a walker that swapped [data-i18n] nodes in place, because a
 * server round trip to change language was too slow to feel like a toggle.
 *
 * Inertia removes the reason for all of it. Switching is an ordinary visit,
 * SetLocale applies the new locale to that very request, and the replaced
 * `translations` prop patches the component tree — no document reload, no
 * second copy of the strings, and deep content nobody remembered to tag comes
 * back from the server already translated.
 *
 * These are real links, not buttons, and that is deliberate: the href is a
 * working URL for this page in that language, so the switch survives JavaScript
 * being unavailable, can be opened in a new tab, and is crawlable. Inertia's
 * <Link> intercepts the click for the SPA visit; the href is the fallback.
 *
 * Two URL shapes, because the product has two:
 *   · the landing and auth pages carry the locale as the first path segment,
 *     so the segment is swapped and the URL stays clean and shareable
 *   · the dashboard has no such segment, so the locale rides along as ?lang=,
 *     which SetLocale reads and persists
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { IconWorld } from '@tabler/icons-vue';

const page = usePage();
const current = computed(() => page.props.locale.current);
const supported = computed(() => page.props.locale.supported);

// Each language is named IN that language — a reader looking for Тоҷикӣ cannot
// be expected to find it under "Tajik".
const labels = { ru: 'Русский', tg: 'Тоҷикӣ', en: 'English' };
const short = { ru: 'RU', tg: 'TJ', en: 'EN' };

function hrefFor(locale) {
    const { pathname, search, hash } = window.location;
    const segments = pathname.split('/');

    // segments[0] is '' (leading slash); segments[1] is the locale prefix if
    // this page has one.
    if (segments.length > 1 && supported.value.includes(segments[1])) {
        segments[1] = locale;

        return segments.join('/') + search + hash;
    }

    const params = new URLSearchParams(search);
    params.set('lang', locale);

    return `${pathname}?${params.toString()}${hash}`;
}
</script>

<template>
    <div class="flex items-center gap-1 rounded-lg border border-border p-0.5" role="group" :aria-label="$t('common.language')">
        <IconWorld :size="16" :stroke-width="1.75" class="ml-1.5 shrink-0 text-muted" aria-hidden="true" />

        <Link
            v-for="locale in supported"
            :key="locale"
            :href="hrefFor(locale)"
            :data-locale="locale"
            :aria-current="locale === current ? 'true' : 'false'"
            :title="labels[locale]"
            preserve-scroll
            class="rounded-md px-2 py-1 text-caption font-medium transition-colors duration-fast"
            :class="
                locale === current
                    ? 'bg-primary text-on-primary'
                    : 'text-muted hover:bg-surface-2 hover:text-text'
            "
        >
            {{ short[locale] }}
            <span class="sr-only">{{ labels[locale] }}</span>
        </Link>
    </div>
</template>
