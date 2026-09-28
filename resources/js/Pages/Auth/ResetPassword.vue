<script setup>
/**
 * Choose a new password, from the emailed link.
 *
 * A full Inertia submit, like ForgotPassword: success redirects to the sign-in
 * page, which is itself an Inertia page.
 *
 * The token and the email come from the signed link and are posted back
 * unchanged. They are hidden inputs in spirit but plain form state here —
 * useForm sends the whole object, so there is nothing to hide in the DOM.
 */
import { useForm } from '@inertiajs/vue3';
import { IconLock, IconMail } from '@tabler/icons-vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import Button from '../../Components/ui/Button.vue';
import Input from '../../Components/ui/Input.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthLayout :title="$t('auth.reset.title')" :subtitle="$t('auth.reset.subtitle')">
        <form class="mt-6 space-y-1" novalidate @submit.prevent="submit">
            <Input
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="email"
                required
                :icon="IconMail"
                :label="$t('auth.reset.email_label')"
                :error="form.errors.email"
            />

            <Input
                id="password"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                required
                autofocus
                :icon="IconLock"
                :label="$t('auth.reset.password_label')"
                :error="form.errors.password"
            />

            <Input
                id="password_confirmation"
                v-model="form.password_confirmation"
                type="password"
                autocomplete="new-password"
                required
                :icon="IconLock"
                :label="$t('auth.reset.confirm_label')"
                :error="form.errors.password_confirmation"
            />

            <Button type="submit" block :loading="form.processing">
                {{ $t('auth.reset.submit') }}
            </Button>
        </form>
    </AuthLayout>
</template>
