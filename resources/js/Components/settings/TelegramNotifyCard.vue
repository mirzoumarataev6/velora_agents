<script setup>
/**
 * Link the owner's personal Telegram to ONE agent's bot, for lead alerts.
 *
 * Per-agent because the link is made through that agent's own bot — there is
 * no product-wide Telegram connection to hang it off, and pretending otherwise
 * would mean silently picking an agent for the owner.
 *
 * Linking cannot be a request/response: the owner has to go and press Start in
 * Telegram, and only then does the bot learn their chat id. So the flow is
 * open-the-bot, then poll — with an explicit ceiling, because an owner who got
 * distracted should not leave a tab polling all afternoon.
 */
import { onBeforeUnmount, ref } from 'vue';
import { IconBrandTelegram } from '@tabler/icons-vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import { translate } from '../../i18n';
import { toast } from '../../toast';

const props = defineProps({
    agent: { type: Object, required: true },
});

const linked = ref(props.agent.linked);
const username = ref(props.agent.username);
const waiting = ref(false);
const busy = ref(false);

let poll = null;

const headers = () => ({
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
});

function stopPolling() {
    clearInterval(poll);
    poll = null;
    waiting.value = false;
}

onBeforeUnmount(stopPolling);

async function link() {
    busy.value = true;

    try {
        const response = await fetch(route('agents.notify.telegram.link', props.agent.slug), {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
        });

        if (response.status === 422) {
            // The agent has no Telegram bot yet — there is nothing to link to.
            toast(translate('leads.notify.telegram_required'), { type: 'danger' });

            return;
        }
        if (!response.ok) {
            toast(translate('leads.notify.toasts.failed'), { type: 'danger' });

            return;
        }

        const data = await response.json();
        window.open(data.url, '_blank', 'noopener');
        startPolling();
    } catch (error) {
        toast(translate('leads.notify.toasts.network'), { type: 'danger' });
    } finally {
        busy.value = false;
    }
}

/** ~3 minutes, then give up rather than poll forever. */
function startPolling() {
    waiting.value = true;
    let attempts = 0;

    poll = setInterval(async () => {
        if (++attempts > 60) {
            stopPolling();

            return;
        }

        try {
            const response = await fetch(route('agents.notify.telegram.status', props.agent.slug), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            const data = await response.json();
            if (data.linked) {
                linked.value = true;
                username.value = data.username;
                stopPolling();
                toast(translate('leads.notify.toasts.linked'), { type: 'success' });
            }
        } catch (error) {
            /* keep polling */
        }
    }, 3000);
}

async function unlink() {
    if (!window.confirm(translate('leads.notify.unlink_confirm'))) return;

    busy.value = true;

    try {
        const response = await fetch(route('agents.notify.telegram.unlink', props.agent.slug), {
            method: 'DELETE',
            headers: headers(),
            credentials: 'same-origin',
        });
        if (!response.ok) {
            toast(translate('leads.notify.toasts.failed'), { type: 'danger' });

            return;
        }

        const data = await response.json();
        linked.value = data.linked;
        username.value = data.username;
        stopPolling();
        toast(translate('leads.notify.toasts.unlinked'), { type: 'success' });
    } catch (error) {
        toast(translate('leads.notify.toasts.network'), { type: 'danger' });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface-2 px-4 py-3">
        <div class="min-w-0">
            <p class="text-body-sm font-medium text-text">{{ agent.name }}</p>
            <p v-if="linked" class="text-caption text-muted">
                {{ $t('leads.notify.linked_as') }}
                <span class="font-medium text-text">{{ username ? '@' + username : '—' }}</span>
            </p>
            <p v-else-if="waiting" class="text-caption text-muted">{{ $t('leads.notify.waiting') }}</p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <Badge v-if="linked" variant="success">{{ $t('settings.account.google_connected') }}</Badge>

            <Button v-if="linked" variant="secondary" size="sm" :disabled="busy" @click="unlink">
                {{ $t('leads.notify.unlink') }}
            </Button>
            <Button v-else size="sm" :loading="busy || waiting" @click="link">
                <IconBrandTelegram :size="15" :stroke-width="1.75" aria-hidden="true" />
                {{ $t('leads.notify.link_button') }}
            </Button>
        </div>
    </div>
</template>
