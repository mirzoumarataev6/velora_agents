<script setup>
/**
 * Sign up.
 *
 * A full Inertia submit, like Login — possible since phase (d) put the
 * dashboard on Inertia, so the redirect at the end of a successful sign-up
 * lands on an Inertia page rather than a Blade document.
 *
 * The password strength meter is kept from the Blade version, and kept honest:
 * it is length-and-variety, labelled in words, never a bare coloured bar. A bar
 * whose only signal is its colour tells a colourblind reader nothing, and tells
 * a projector audience less than that.
 */
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { IconLock, IconMail, IconUser } from '@tabler/icons-vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import Button from '../../Components/ui/Button.vue';
import Input from '../../Components/ui/Input.vue';
import { translate } from '../../i18n';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

const local = ref({ name: null, email: null, password: null, password_confirmation: null });

const errorFor = (field) => local.value[field] ?? form.errors[field] ?? null;

const looksLikeEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

/**
 * Four bands, scored on length first and variety second — length is what
 * actually resists a guess, so a long lowercase phrase must not score below a
 * short one with a symbol in it.
 */
const strength = computed(() => {
    const value = form.password;
    if (!value) return null;

    let score = 0;
    if (value.length >= 8) score++;
    if (value.length >= 12) score++;
    if (/[^a-zA-Z0-9]/.test(value) || (/[a-z]/.test(value) && /[A-Z]/.test(value))) score++;
    if (/\d/.test(value) && value.length >= 10) score++;

    const bands = [
        { key: 'auth.register.strength_weak', tone: 'text-danger', fill: 'bg-danger', width: 'w-1/4' },
        { key: 'auth.register.strength_fair', tone: 'text-warning', fill: 'bg-warning-fill', width: 'w-2/4' },
        { key: 'auth.register.strength_good', tone: 'text-primary-text', fill: 'bg-primary', width: 'w-3/4' },
        { key: 'auth.register.strength_strong', tone: 'text-success', fill: 'bg-success-fill', width: 'w-full' },
    ];

    return bands[Math.min(score, 4) - 1] ?? bands[0];
});

const matches = computed(() => form.password_confirmation !== '' && form.password_confirmation === form.password);

function checkName() {
    local.value.name = form.name.trim() === '' ? translate('auth.validation.name_required') : null;
}
function checkEmail() {
    local.value.email = form.email && !looksLikeEmail(form.email)
        ? translate('auth.validation.email_invalid') : null;
}
function checkPassword() {
    local.value.password = form.password && form.password.length < 8
        ? translate('auth.validation.password_min') : null;
}
function checkConfirmation() {
    local.value.password_confirmation = form.password_confirmation && form.password_confirmation !== form.password
        ? translate('auth.validation.password_mismatch') : null;
}

function submit() {
    checkName();
    if (!looksLikeEmail(form.email)) local.value.email = translate('auth.validation.email_invalid');
    if (form.password.length < 8) local.value.password = translate('auth.validation.password_min');
    if (form.password_confirmation !== form.password) {
        local.value.password_confirmation = translate('auth.validation.password_mismatch');
    }

    if (Object.values(local.value).some(Boolean)) return;

    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout :title="$t('auth.register.title')" :subtitle="$t('auth.register.subtitle')">
        <a
            :href="route('auth.google')"
            class="mt-6 flex w-full items-center justify-center gap-3 rounded-md border border-border bg-surface px-4 py-2.5 text-body-sm font-medium text-text transition-colors duration-fast hover:bg-surface-2"
        >
            <svg viewBox="0 0 48 48" class="h-5 w-5" aria-hidden="true">
                <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.9 32.5 29.4 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20c11 0 19.7-8 19.7-20 0-1.3-.1-2.3-.3-3.5z" />
                <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.1 18.9 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
                <path fill="#4CAF50" d="M24 44c5.3 0 10.1-2 13.7-5.4l-6.3-5.3C29.4 35 26.8 36 24 36c-5.4 0-9.9-3.5-11.3-8.3l-6.6 5.1C9.6 39.6 16.2 44 24 44z" />
                <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.4-2.3 4.4-4.3 5.8l6.3 5.3C39.9 37.1 44 31.9 44 24c0-1.3-.1-2.3-.3-3.5z" />
            </svg>
            {{ $t('auth.register.google_cta') }}
        </a>

        <div class="my-5 flex items-center gap-3">
            <span class="h-px flex-1 bg-border" />
            <span class="text-overline uppercase text-muted">{{ $t('auth.register.divider') }}</span>
            <span class="h-px flex-1 bg-border" />
        </div>

        <form novalidate class="space-y-1" @submit.prevent="submit">
            <Input
                id="name" v-model="form.name" autocomplete="name" required autofocus
                :icon="IconUser" :label="$t('auth.register.name_label')" :error="errorFor('name')"
                @blur="checkName" @update:model-value="local.name = null"
            />

            <Input
                id="email" v-model="form.email" type="email" autocomplete="email" required
                :icon="IconMail" :label="$t('auth.register.email_label')" :error="errorFor('email')"
                @blur="checkEmail" @update:model-value="local.email = null"
            />

            <Input
                id="password" v-model="form.password" type="password" autocomplete="new-password" required
                :icon="IconLock" :label="$t('auth.register.password_label')"
                :error="errorFor('password')" :hint="$t('auth.register.password_hint')"
                @blur="checkPassword" @update:model-value="local.password = null"
            />

            <!-- Strength: a bar AND a word. The word is what carries the
                 meaning; the bar only makes it quicker to read. -->
            <div v-if="strength" class="-mt-1 mb-1 flex items-center gap-2">
                <span class="h-1 flex-1 overflow-hidden rounded-pill bg-surface-2">
                    <span class="block h-full rounded-pill transition-all duration-base" :class="[strength.fill, strength.width]" />
                </span>
                <span class="text-caption font-medium" :class="strength.tone">{{ $t(strength.key) }}</span>
            </div>

            <Input
                id="password_confirmation" v-model="form.password_confirmation" type="password"
                autocomplete="new-password" required
                :icon="IconLock" :label="$t('auth.register.confirm_label')"
                :error="errorFor('password_confirmation')"
                :hint="matches ? $t('auth.register.password_match') : null"
                @blur="checkConfirmation" @update:model-value="local.password_confirmation = null"
            />

            <Button type="submit" block :loading="form.processing">
                {{ $t('auth.register.submit') }}
            </Button>

            <p class="pt-2 text-center text-caption text-muted">{{ $t('auth.register.terms_notice') }}</p>
        </form>

        <template #below>
            <p class="mt-5 text-center text-body-sm text-muted">
                {{ $t('auth.register.have_account') }}
                <Link
                    :href="route('login', { locale: $page.props.locale.current })"
                    class="font-medium text-primary-text hover:underline"
                >
                    {{ $t('auth.register.login_link') }}
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
