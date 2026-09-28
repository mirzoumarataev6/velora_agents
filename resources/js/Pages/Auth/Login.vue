<script setup>
/**
 * Sign in.
 *
 * A full Inertia submit. It could not be one until phase (d): a successful
 * sign-in redirects to the dashboard, and while that was still a Blade page an
 * XHR submit would have received plain HTML instead of an Inertia response and
 * thrown. Now that the dashboard is Inertia, the whole flow stays in the SPA —
 * no document reload between the form and the panel.
 *
 * Server-side errors arrive as `form.errors`, the same channel the client-side
 * checks below write to, so a field does not care which produced its message.
 */
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { IconLock, IconMail } from '@tabler/icons-vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import Button from '../../Components/ui/Button.vue';
import Input from '../../Components/ui/Input.vue';
import { translate } from '../../i18n';

const form = useForm({ email: '', password: '', remember: false });

// Client-side messages, kept separate from the server's so that typing into a
// field clears the local complaint without erasing what the server said.
const local = ref({ email: null, password: null });

const emailError = computed(() => local.value.email ?? form.errors.email ?? null);
const passwordError = computed(() => local.value.password ?? form.errors.password ?? null);

const looksLikeEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

function checkEmail() {
    local.value.email = form.email && !looksLikeEmail(form.email)
        ? translate('auth.validation.email_invalid')
        : null;
}

function checkPassword() {
    local.value.password = form.password && form.password.length < 8
        ? translate('auth.validation.password_min')
        : null;
}

/**
 * Validate before submitting — a round trip that can only come back with the
 * same two messages is a round trip worth not making.
 */
function submit() {
    if (!looksLikeEmail(form.email)) {
        local.value.email = translate('auth.validation.email_invalid');
    }
    if (form.password.length < 8) {
        local.value.password = translate('auth.validation.password_min');
    }

    if (local.value.email || local.value.password) return;

    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout :title="$t('auth.login.title')" :subtitle="$t('auth.login.subtitle')">
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
            {{ $t('auth.login.google_cta') }}
        </a>

        <div class="my-5 flex items-center gap-3">
            <span class="h-px flex-1 bg-border" />
            <span class="text-overline uppercase text-muted">{{ $t('auth.login.divider') }}</span>
            <span class="h-px flex-1 bg-border" />
        </div>

        <form novalidate class="space-y-1" @submit.prevent="submit">
            <Input
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                required
                autofocus
                :icon="IconMail"
                :label="$t('auth.login.email_label')"
                :error="emailError"
                @blur="checkEmail"
                @update:model-value="local.email = null"
            />

            <Input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                required
                :icon="IconLock"
                :label="$t('auth.login.password_label')"
                :error="passwordError"
                @blur="checkPassword"
                @update:model-value="local.password = null"
            />

            <div class="flex items-center justify-between pb-1 pt-1">
                <label class="flex items-center gap-2 text-body-sm text-muted">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-input-border text-primary focus:ring-2 focus:ring-primary"
                    />
                    {{ $t('auth.login.remember') }}
                </label>

                <Link
                    :href="route('password.request', { locale: $page.props.locale.current })"
                    class="text-body-sm font-medium text-primary-text hover:underline"
                >
                    {{ $t('auth.login.forgot') }}
                </Link>
            </div>

            <Button type="submit" block :loading="form.processing">
                {{ $t('auth.login.submit') }}
            </Button>
        </form>

        <template #below>
            <p class="mt-5 text-center text-body-sm text-muted">
                {{ $t('auth.login.no_account') }}
                <Link
                    :href="route('register', { locale: $page.props.locale.current })"
                    class="font-medium text-primary-text hover:underline"
                >
                    {{ $t('auth.login.register_link') }}
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
