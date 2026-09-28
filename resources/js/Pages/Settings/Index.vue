<script setup>
/**
 * Account settings: profile, security, preferences, account.
 *
 * Four tabs rather than one long form. They are genuinely different KINDS of
 * change — a name, a password, an interface preference, and deleting
 * everything — and putting the last of those at the bottom of a scroll with
 * the first is how someone lands on it by accident.
 *
 * Every form posts to its own endpoint (the controller already had one each),
 * so a failed password change cannot roll back a saved name.
 *
 * Saving shows a toast rather than a banner: the banner would push the whole
 * form down on every save, moving the field under the cursor.
 */
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { IconAlertTriangle, IconDeviceDesktop, IconLock, IconMail, IconUser } from '@tabler/icons-vue';

import AppLayout from '../../Layouts/AppLayout.vue';
import Badge from '../../Components/ui/Badge.vue';
import Button from '../../Components/ui/Button.vue';
import Card from '../../Components/ui/Card.vue';
import Input from '../../Components/ui/Input.vue';
import Modal from '../../Components/ui/Modal.vue';
import PageHeader from '../../Components/ui/PageHeader.vue';
import Select from '../../Components/ui/Select.vue';
import Switch from '../../Components/ui/Switch.vue';
import Tabs from '../../Components/ui/Tabs.vue';
import TelegramNotifyCard from '../../Components/settings/TelegramNotifyCard.vue';
import { translate } from '../../i18n';
import { toast } from '../../toast';

const props = defineProps({
    user: { type: Object, required: true },
    agents: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    sessionsSupported: { type: Boolean, default: false },
});

const page = usePage();
const tab = ref('profile');

const tabs = computed(() =>
    ['profile', 'security', 'preferences', 'account'].map((value) => ({
        value,
        label: translate(`settings.tabs.${value}`),
    }))
);

/* A save is a small, local confirmation — see the note above. */
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast(status, { type: 'success' });
    },
    { immediate: true }
);

/* --- Profile ------------------------------------------------------------- */
const profile = useForm({ name: props.user.name, email: props.user.email });

/* --- Security ------------------------------------------------------------ */
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const sessionsForm = useForm({ password: '' });

/* --- Preferences --------------------------------------------------------- */
const preferences = useForm({
    locale: props.user.locale ?? page.props.locale.current,
    theme: props.user.theme ?? 'system',
    lead_notifications_enabled: props.user.lead_alerts,
});

const localeOptions = computed(() =>
    page.props.locale.supported.map((code) => ({ value: code, label: translate(`settings.locales.${code}`) }))
);
const themeOptions = computed(() =>
    ['system', 'light', 'dark'].map((value) => ({ value, label: translate(`settings.themes.${value}`) }))
);

/* --- Account ------------------------------------------------------------- */
const deleteOpen = ref(false);
const deletion = useForm({ confirmation: '' });

function destroyAccount() {
    deletion.delete(route('settings.account.destroy'), {
        onError: () => toast(translate('settings.account.delete_confirmation_wrong'), { type: 'danger' }),
    });
}
</script>

<template>
    <AppLayout :title="$t('settings.index.heading')">
        <PageHeader :title="$t('settings.index.heading')" :description="$t('settings.index.subheading')" />

        <Tabs v-model="tab" :tabs="tabs" class="mb-5" />

        <!-- ================= PROFILE ================= -->
        <Card v-show="tab === 'profile'">
            <template #header>
                <h2 class="text-h4 text-text">{{ $t('settings.profile.heading') }}</h2>
                <p class="mt-0.5 text-caption text-muted">{{ $t('settings.profile.description') }}</p>
            </template>

            <form class="max-w-md space-y-1" @submit.prevent="profile.patch(route('settings.profile'))">
                <div class="mb-4 flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-primary-tint text-body font-semibold text-primary-text">
                        {{ user.initials }}
                    </span>
                    <p class="text-caption text-muted">{{ $t('settings.profile.avatar_hint') }}</p>
                </div>

                <Input
                    id="name"
                    v-model="profile.name"
                    :label="$t('settings.profile.name')"
                    :icon="IconUser"
                    :error="profile.errors.name"
                    required
                />
                <Input
                    id="email"
                    v-model="profile.email"
                    type="email"
                    :label="$t('settings.profile.email')"
                    :icon="IconMail"
                    :error="profile.errors.email"
                    :hint="$t('settings.profile.email_hint')"
                    required
                />

                <Badge v-if="!user.email_verified" variant="warning" :icon="IconAlertTriangle" class="mb-2">
                    {{ $t('settings.profile.unverified') }}
                </Badge>

                <Button type="submit" :loading="profile.processing">{{ $t('settings.actions.save') }}</Button>
            </form>
        </Card>

        <!-- ================= SECURITY ================= -->
        <div v-show="tab === 'security'" class="space-y-4">
            <Card>
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('settings.security.heading') }}</h2>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('settings.security.description') }}</p>
                </template>

                <p v-if="!user.has_password" class="mb-4 rounded-lg border border-border bg-surface-2 px-3 py-2 text-caption text-muted">
                    {{ $t('settings.security.google_hint') }}
                </p>

                <form class="max-w-md space-y-1" @submit.prevent="password.put(route('settings.password'), { onSuccess: () => password.reset() })">
                    <Input
                        v-if="user.has_password"
                        id="current_password"
                        v-model="password.current_password"
                        type="password"
                        :label="$t('settings.security.current_password')"
                        :icon="IconLock"
                        :error="password.errors.current_password"
                        autocomplete="current-password"
                    />
                    <Input
                        id="new_password"
                        v-model="password.password"
                        name="password"
                        type="password"
                        :label="$t('settings.security.new_password')"
                        :icon="IconLock"
                        :error="password.errors.password"
                        autocomplete="new-password"
                    />
                    <Input
                        id="password_confirmation"
                        v-model="password.password_confirmation"
                        type="password"
                        :label="$t('settings.security.confirm_password')"
                        :icon="IconLock"
                        autocomplete="new-password"
                    />

                    <Button type="submit" :loading="password.processing">
                        {{ $t('settings.security.change_password') }}
                    </Button>
                </form>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('settings.security.sessions_heading') }}</h2>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('settings.security.sessions_description') }}</p>
                </template>

                <p v-if="!sessionsSupported" class="text-body-sm text-muted">
                    {{ $t('settings.security.sessions_unavailable') }}
                </p>

                <template v-else>
                    <ul class="mb-4 divide-y divide-border rounded-lg border border-border">
                        <li v-for="session in sessions" :key="session.id" class="flex items-center gap-3 px-3.5 py-2.5">
                            <IconDeviceDesktop :size="18" :stroke-width="1.75" class="shrink-0 text-muted" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate text-body-sm text-text">
                                {{ session.device || $t('settings.security.unknown_device') }}
                                <span class="text-caption text-muted">· {{ session.ip }}</span>
                            </span>
                            <Badge v-if="session.current" variant="success">{{ $t('settings.security.this_device') }}</Badge>
                        </li>
                    </ul>

                    <!-- Confirmed with a password: this is what actually ejects
                         another browser, so it must not be a one-click action. -->
                    <form
                        class="flex max-w-md flex-wrap items-end gap-2"
                        @submit.prevent="sessionsForm.delete(route('settings.sessions'), { onSuccess: () => sessionsForm.reset() })"
                    >
                        <div class="min-w-48 flex-1">
                            <Input
                                id="sessions_password"
                                v-model="sessionsForm.password"
                                name="password"
                                type="password"
                                :label="$t('settings.security.confirm_with_password')"
                                :icon="IconLock"
                                :error="sessionsForm.errors.password"
                                autocomplete="current-password"
                            />
                        </div>
                        <Button type="submit" variant="secondary" class="mb-6" :loading="sessionsForm.processing">
                            {{ $t('settings.security.logout_others') }}
                        </Button>
                    </form>
                </template>
            </Card>
        </div>

        <!-- ================= PREFERENCES ================= -->
        <div v-show="tab === 'preferences'" class="space-y-4">
            <Card>
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('settings.preferences.heading') }}</h2>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('settings.preferences.description') }}</p>
                </template>

                <form class="max-w-md space-y-4" @submit.prevent="preferences.patch(route('settings.preferences'))">
                    <Select
                        id="locale"
                        v-model="preferences.locale"
                        :label="$t('settings.preferences.language')"
                        :options="localeOptions"
                    />
                    <Select
                        id="theme"
                        v-model="preferences.theme"
                        :label="$t('settings.preferences.theme')"
                        :options="themeOptions"
                    />

                    <Switch
                        id="lead_alerts"
                        v-model="preferences.lead_notifications_enabled"
                        :label="$t('settings.preferences.lead_alerts')"
                        :hint="$t('settings.preferences.lead_alerts_hint')"
                    />

                    <Button type="submit" :loading="preferences.processing">{{ $t('settings.actions.save') }}</Button>
                </form>
            </Card>

            <Card>
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('settings.preferences.telegram_heading') }}</h2>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('settings.preferences.telegram_description') }}</p>
                </template>

                <p v-if="agents.length === 0" class="text-body-sm text-muted">
                    {{ $t('settings.preferences.telegram_no_agents') }}
                </p>

                <div v-else class="space-y-2">
                    <TelegramNotifyCard v-for="agent in agents" :key="agent.id" :agent="agent" />
                </div>
            </Card>
        </div>

        <!-- ================= ACCOUNT ================= -->
        <div v-show="tab === 'account'" class="space-y-4">
            <Card>
                <template #header>
                    <h2 class="text-h4 text-text">{{ $t('settings.account.heading') }}</h2>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('settings.account.description') }}</p>
                </template>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-body-sm text-text">{{ $t('settings.account.google') }}</span>
                    <Badge :variant="user.google_connected ? 'success' : 'neutral'">
                        {{ user.google_connected ? $t('settings.account.google_connected') : $t('settings.account.google_not_connected') }}
                    </Badge>
                </div>
            </Card>

            <!-- Danger, and it looks like it: its own card, a danger border, and
                 a modal that asks the owner to type something rather than click. -->
            <Card class="!border-danger">
                <template #header>
                    <h2 class="text-h4 text-danger">{{ $t('settings.account.delete_heading') }}</h2>
                    <p class="mt-0.5 text-caption text-muted">{{ $t('settings.account.delete_description') }}</p>
                </template>

                <ul class="mb-4 list-disc space-y-1 pl-5 text-body-sm text-muted">
                    <li>{{ $t('settings.account.delete_item_agents') }}</li>
                    <li>{{ $t('settings.account.delete_item_knowledge') }}</li>
                    <li>{{ $t('settings.account.delete_item_conversations') }}</li>
                    <li>{{ $t('settings.account.delete_item_leads') }}</li>
                </ul>

                <Button variant="danger" @click="deleteOpen = true">{{ $t('settings.account.delete_button') }}</Button>
            </Card>
        </div>

        <Modal
            :open="deleteOpen"
            :title="$t('settings.account.modal_title')"
            :description="$t('settings.account.modal_description')"
            @close="deleteOpen = false"
        >
            <Input
                id="confirmation"
                v-model="deletion.confirmation"
                :label="$t('settings.account.modal_prompt', { email: user.email })"
                :error="deletion.errors.confirmation"
            />

            <template #footer>
                <Button variant="secondary" @click="deleteOpen = false">{{ $t('settings.actions.cancel') }}</Button>
                <Button variant="danger" :loading="deletion.processing" @click="destroyAccount">
                    {{ $t('settings.account.modal_confirm') }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
