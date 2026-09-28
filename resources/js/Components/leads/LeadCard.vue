<script setup>
/**
 * One lead: who they are, how to reach them, what they asked for.
 *
 * A price request gets a 2px primary border and a "waiting" badge, because it
 * is the only kind of lead that BLOCKS — a customer is sitting there waiting
 * for a number. An ordinary order can be read whenever.
 *
 * "Chat only" is a real state, not a missing field: the customer ordered
 * without leaving a phone number, so the owner has to reply in the thread. It
 * gets a badge rather than an empty line, which would read as broken data.
 */
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { IconMessageCircle, IconTrash } from '@tabler/icons-vue';
import Badge from '../ui/Badge.vue';
import Button from '../ui/Button.vue';
import Select from '../ui/Select.vue';
import { translate } from '../../i18n';
import { toast } from '../../toast';

const props = defineProps({
    lead: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
});

const price = ref('');
const busy = ref(false);

const statusChoices = props.statusOptions.map((status) => ({
    value: status,
    label: translate(`leads.filters.${status}`),
}));

/** Every action reloads in place — the list must not jump under the cursor. */
const options = (success) => ({
    preserveScroll: true,
    onSuccess: () => toast(translate(success), { type: 'success' }),
    onError: () => toast(translate('leads.index.action_failed'), { type: 'danger' }),
    onFinish: () => (busy.value = false),
});

function updateStatus(status) {
    router.patch(route('leads.update', props.lead.id), { status }, options('leads.index.updated'));
}

function remove() {
    if (!window.confirm(translate('leads.index.delete_confirm'))) return;

    router.delete(route('leads.destroy', props.lead.id), options('leads.index.deleted'));
}

/**
 * Approving is NOT a status change — it commits the business to a number and
 * sends the customer a message — so it has its own endpoint and its own
 * controls rather than being another option in the status dropdown.
 */
function approve() {
    busy.value = true;
    router.post(
        route('leads.price.approve', props.lead.id),
        { price: price.value || undefined },
        options('leads.haggle.approved')
    );
}

function decline() {
    if (!window.confirm(translate('leads.haggle.decline_confirm'))) return;

    busy.value = true;
    router.post(route('leads.price.decline', props.lead.id), {}, options('leads.haggle.declined'));
}

/** Channels have translated names; anything unrecognised prints as-is. */
function sourceLabel(source) {
    const label = translate(`leads.source.${source}`);

    return label === `leads.source.${source}` ? (source || '—') : label;
}
</script>

<template>
    <article
        class="rounded-xl bg-surface p-4 shadow-e1"
        :class="lead.awaits_price_approval ? 'border-2 border-primary' : 'border border-border'"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-h4 text-text">{{ lead.customer_name || $t('leads.index.no_name') }}</h3>

                <p v-if="lead.phone" class="text-body-sm font-medium text-primary-text">{{ lead.phone }}</p>
                <p
                    v-if="lead.contact && lead.contact !== lead.phone"
                    class="text-body-sm"
                    :class="lead.phone ? 'text-muted' : 'font-medium text-primary-text'"
                >
                    {{ lead.contact }}
                </p>

                <Badge
                    v-if="lead.is_chat_only"
                    :icon="IconMessageCircle"
                    class="mt-1"
                    :title="$t('leads.index.chat_only_hint')"
                >
                    {{ $t('leads.index.chat_only') }}
                </Badge>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Badge v-if="lead.awaits_price_approval" variant="primary">
                    {{ $t('leads.haggle.waiting_badge') }}
                </Badge>
                <Badge>{{ sourceLabel(lead.source) }}</Badge>
                <span class="text-caption text-muted">{{ lead.created_at_human }}</span>
            </div>
        </div>

        <p v-if="lead.items" class="mt-3 text-body-sm text-text">
            <span class="text-muted">{{ $t('leads.index.items_label') }}:</span> {{ lead.items }}
        </p>
        <p v-if="lead.note && !lead.is_haggle" class="mt-1 text-body-sm text-text">
            <span class="text-muted">{{ $t('leads.index.note_label') }}:</span> {{ lead.note }}
        </p>

        <div v-if="lead.is_haggle" class="mt-3 rounded-lg border border-border bg-surface-2 p-3">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <span class="text-body-sm">
                    <span class="text-muted">{{ $t('leads.haggle.requested_label') }}:</span>
                    <span class="ml-1 text-body font-semibold tabular-nums text-primary-text">
                        {{ lead.requested_price ?? '—' }}
                    </span>
                </span>
                <span v-if="lead.listed_price" class="text-caption text-muted">
                    {{ $t('leads.haggle.listed_label') }}:
                    <span class="line-through tabular-nums">{{ lead.listed_price }}</span>
                </span>
                <Badge v-if="lead.discount_percent !== null" variant="warning">
                    −{{ lead.discount_percent }}%
                </Badge>
            </div>

            <p v-if="lead.negotiation_summary" class="mt-2 text-body-sm text-text">{{ lead.negotiation_summary }}</p>
            <p v-if="lead.approved_price" class="mt-2 text-caption font-medium text-success">
                {{ $t('leads.haggle.settled_at', { price: lead.approved_price }) }}
            </p>

            <div v-if="lead.awaits_price_approval" class="mt-3 flex flex-wrap items-center gap-2">
                <input
                    v-model="price"
                    type="text"
                    inputmode="decimal"
                    class="field w-32 px-3 py-2 text-body-sm"
                    :placeholder="$t('leads.haggle.price_placeholder')"
                    :aria-label="$t('leads.haggle.price_placeholder')"
                />
                <Button size="sm" :loading="busy" @click="approve">{{ $t('leads.haggle.approve') }}</Button>
                <Button size="sm" variant="secondary" :disabled="busy" @click="decline">
                    {{ $t('leads.haggle.decline') }}
                </Button>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
            <Link
                v-if="lead.agent"
                :href="route('agents.show', lead.agent.slug)"
                class="text-caption font-medium text-muted transition-colors duration-fast hover:text-primary-text"
            >
                {{ lead.agent.name }}
            </Link>
            <span v-else />

            <div class="flex items-center gap-2">
                <Select
                    :id="`lead-status-${lead.id}`"
                    :model-value="lead.status"
                    :label="$t('leads.filters.all')"
                    :options="statusChoices"
                    class="w-40 [&_label]:sr-only [&_p]:hidden"
                    @update:model-value="updateStatus"
                />
                <Button variant="ghost" size="sm" :aria-label="$t('leads.index.delete')" @click="remove">
                    <IconTrash :size="16" :stroke-width="1.75" />
                </Button>
            </div>
        </div>
    </article>
</template>
