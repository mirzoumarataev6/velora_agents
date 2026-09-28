<script setup>
/**
 * The Velora mark.
 *
 * Traced from resources/brand/logo-source.png and verified against it at 99.4%
 * pixel agreement; resources/brand/velora-mark.svg is the master this mirrors.
 * Inline rather than an <img>, for three reasons that all matter here: it
 * inherits the page's own rendering (no second network request, no flash of
 * nothing on a slow projector wifi), it can be recoloured by CSS for the flat
 * variants, and at 2 KB it is smaller than the HTTP request that would fetch
 * it.
 *
 * THE SPARKLE IS THE PRODUCT'S AI SYMBOL. It appears in the mark, and on its
 * own (see Sparkle.vue) to label an AI moment. Do not use it decoratively.
 *
 * `flat` drops the gradients for a single-colour rendering — currentColor, so
 * the caller sets it with an ordinary text utility. Below roughly 48px the
 * gradient's dark end and a dark ground converge and the mark loses its
 * silhouette, which is why the favicons are flat too.
 */
import { computed, useId } from 'vue';

defineProps({
    /** Render as a single colour (currentColor) rather than the gradient art. */
    flat: { type: Boolean, default: false },
});

// Gradient ids are document-global, so two marks on one page would otherwise
// share — and fight over — the same three definitions.
const uid = useId();
const id = computed(() => (name) => `velora-${name}-${uid}`);
</script>

<template>
    <svg viewBox="0 0 1020 835" fill="none" role="img" :aria-label="$t('common.brand')">
        <defs v-if="!flat">
            <linearGradient :id="id('front')" gradientUnits="userSpaceOnUse"
                x1="2" y1="205" x2="357" y2="752">
                <stop offset="0" stop-color="#4973FC" />
                <stop offset=".33" stop-color="#2D46E2" />
                <stop offset=".67" stop-color="#1523A0" />
                <stop offset="1" stop-color="#030558" />
            </linearGradient>
            <linearGradient :id="id('back')" gradientUnits="userSpaceOnUse"
                x1="1002" y1="254" x2="327" y2="769">
                <stop offset="0" stop-color="#63ABFC" />
                <stop offset=".33" stop-color="#487CFD" />
                <stop offset=".67" stop-color="#3661F5" />
                <stop offset="1" stop-color="#3150ED" />
            </linearGradient>
            <linearGradient :id="id('spark')" gradientUnits="userSpaceOnUse"
                x1="779" y1="113" x2="1018" y2="114">
                <stop offset="0" stop-color="#1A21BE" />
                <stop offset=".33" stop-color="#1930F1" />
                <stop offset=".56" stop-color="#3A6CFC" />
                <stop offset=".80" stop-color="#579EFD" />
                <stop offset="1" stop-color="#4B83FC" />
            </linearGradient>
        </defs>

        <path :fill="flat ? 'currentColor' : `url(#${id('back')})`"
            d="M761 253 L740 257 L718 265 L694 280 L677 296 L657 323 L609 400 L544 497 L516 538 L510 542 L511 544 L473 600 L381 731 L364 748 L354 754 L345 757 L319 758 L327 769 L349 791 L381 813 L418 828 L456 834 L559 834 L579 831 L602 824 L618 816 L636 804 L652 790 L673 765 L840 512 L992 276 L1003 258 L1002 253 Z" />
        <path :fill="flat ? 'currentColor' : `url(#${id('front')})`"
            d="M11 204 L2 205 L1 211 L60 305 L188 529 L293 720 L318 757 L345 757 L354 754 L364 748 L381 731 L449 635 L512 541 L386 334 L328 243 L314 229 L295 216 L276 208 L254 204 Z" />
        <path :fill="flat ? 'currentColor' : `url(#${id('spark')})`"
            d="M892 1 L885 25 L877 43 L866 60 L849 78 L820 97 L781 110 L779 113 L781 116 L818 128 L847 146 L867 167 L877 183 L885 201 L891 224 L899 224 L905 202 L914 182 L929 160 L943 146 L972 128 L992 121 L1017 116 L1019 114 L1018 110 L997 106 L975 99 L959 91 L941 78 L925 61 L915 46 L906 27 L899 1 Z" />
    </svg>
</template>
