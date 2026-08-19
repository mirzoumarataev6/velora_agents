{{--
    This agent's conversations — the same inbox the global Диалоги page renders,
    pinned to this agent across all of its channels.

    Mounted with x-if rather than x-show so it only boots when the tab is
    actually opened: otherwise every visit to an agent's page would fire the
    list request and start a 15s poll for a tab nobody looked at.

    Expects: $agent, $conversationFilters, $conversationChannelTypes.
--}}
<template x-if="tab === 'conversations'">
    <div>
        @include('conversations.partials.inbox', [
            'scopeAgent' => $agent,
            'channelTypes' => $conversationChannelTypes,
            'filters' => $conversationFilters,
        ])
    </div>
</template>
