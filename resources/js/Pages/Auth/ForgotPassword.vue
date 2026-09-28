<script setup>
/**
 * Request a password-reset link.
 *
 * Unlike Login and Register, this one IS a full Inertia submit: it redirects
 * back to itself with a status flash, and this page is already an Inertia page,
 * so there is no Blade document at the end of the round trip to choke on.
 *
 * That makes it the pilot's real test of the stack — useForm posts, errors
 * arrive as props without a reload, and the success flash renders in place.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { IconMail } from '@tabler/icons-vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import Button from '../../Components/ui/Button.vue';
import Input from '../../Components/ui/Input.vue';

const form = useForm({ email: '' });

function submit() {
    form.post(route('password.email'), {
        preserveScroll: true,
        onSuccess: () => form.reset('email'),
    });
}
</script>

<template>
    <AuthLayout :title="$t('auth.forgot.title')" :subtitle="$t('auth.forgot.subtitle')">
        <form class="mt-6 space-y-1" novalidate @submit.prevent="submit">
            <Input
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                required
                autofocus
                :icon="IconMail"
                :label="$t('auth.forgot.email_label')"
                :error="form.errors.email"
            />

            <Button type="submit" block :loading="form.processing">
                {{ $t('auth.forgot.submit') }}
            </Button>
        </form>

        <template #below>
            <p class="mt-5 text-center text-body-sm">
                <Link
                    :href="route('login', { locale: $page.props.locale.current })"
                    class="font-medium text-primary-text hover:underline"
                >
                    {{ $t('auth.forgot.back_to_login') }}
                </Link>
            </p>
        </template>
    </AuthLayout>
</template>
