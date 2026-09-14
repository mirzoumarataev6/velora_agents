{{--
    The inbox: conversation list, transcript, filters, operator reply.

    Rendered in two places from this one file — the global Диалоги page, and an
    agent's own Диалоги tab, where it is pre-scoped to that agent. The scoped
    version is not a second implementation: it passes the same agent id the
    global page's agent dropdown would set, and locks it.

    Expects: $filters, $channelTypes. Optional: $agents (global only),
    $scopeAgent (an Agent — when present the inbox is pinned to it).
--}}
@php
    $scopeAgent = $scopeAgent ?? null;
    $inboxAgents = $agents ?? collect();
@endphp
<div x-data="conversationsInbox({
        listUrl: '{{ route('conversations.list') }}',
        showUrlBase: '{{ route('conversations.show', ['conversation' => '__ID__']) }}',
        replyUrlBase: '{{ route('conversations.reply', ['conversation' => '__ID__']) }}',
        statusUrlBase: '{{ route('conversations.status', ['conversation' => '__ID__']) }}',
        leadsUrl: '{{ route('leads.index') }}',
        agentUrlBase: '{{ route('agents.show', ['agent' => '__SLUG__']) }}',
        initialFilters: {{ Js::from($filters) }},
        {{-- Non-null pins every request, the reset button and the poll to this
             agent, so the tab can never surface another agent's conversation. --}}
        lockedAgent: {{ Js::from($scopeAgent?->id) }},
        scoped: {{ Js::from((bool) $scopeAgent) }},
        labels: {{ Js::from([
            'sent' => __('conversations.toasts.sent'),
            'takenOver' => __('conversations.toasts.taken_over'),
            'handedBack' => __('conversations.toasts.handed_back'),
            'closed' => __('conversations.toasts.closed'),
            'reopened' => __('conversations.toasts.reopened'),
            'failed' => __('conversations.toasts.failed'),
            'network' => __('conversations.toasts.network'),
            'roles' => __('conversations.roles'),
            'status' => __('conversations.status'),
            'knowledgeUnconfirmed' => __('conversations.thread.knowledge_unconfirmed'),
            'matchesInChat' => __('conversations.search.matches_in_chat'),
            'ratingUp' => __('conversations.thread.rating_up'),
            'ratingDown' => __('conversations.thread.rating_down'),
            'ratingUpHint' => __('conversations.thread.rating_up_hint'),
            'ratingDownHint' => __('conversations.thread.rating_down_hint'),
        ]) }},
    })" x-init="init()" @keydown.escape.window="openId && close()">

    {{-- Filters: chips, search and both dates on a SINGLE line.

         Everything is shrink-0 and the strip never wraps — only the search
         flexes, down to a still-usable min width. Past that the strip scrolls
         sideways instead of spilling onto a second row, which is what used to
         cost the inbox a whole band of vertical space. The negative margin lets
         it bleed to the edge while scrolling rather than clipping mid-chip. --}}
    <div data-filter-bar class="{{ $scopeAgent ? '' : 'mt-6' }} -mx-1 flex flex-nowrap items-center gap-1.5 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach (['all', 'unread', 'needs_human', 'has_lead', 'rated_down', 'closed'] as $key)
            <button type="button" @click="setStatus('{{ $key }}')"
                :class="filters.status === '{{ $key }}'
                    ? 'border-brand bg-brand/10 text-brand'
                    : 'border-black/10 text-muted hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/5'"
                class="shrink-0 whitespace-nowrap rounded-full border px-2.5 py-1.5 text-xs font-medium transition-colors">
                <span data-i18n="conversations.filters.{{ $key }}">{{ __("conversations.filters.$key") }}</span>
            </button>
        @endforeach

        {{-- Separates "which conversations" from "narrow them down". --}}
        <span aria-hidden="true" class="mx-0.5 h-5 w-px shrink-0 bg-black/10 dark:bg-white/10"></span>

        <div class="relative min-w-[7.5rem] flex-1 basis-40 sm:max-w-xs">
            <svg x-show="!searching" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
            <span x-show="searching" x-cloak class="spinner absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2"></span>
            {{-- The native ::-webkit-search-cancel-button is suppressed: it drew
                 a second × next to ours, and it only blanks the input, where the
                 button below also drops the matches and the auto-open key. --}}
            <input type="search" x-model="filters.q" @input.debounce.150ms="reload()" x-ref="search"
                placeholder="{{ __('conversations.index.search_placeholder') }}" data-i18n-ph="conversations.index.search_placeholder"
                class="field w-full rounded-xl py-1.5 pl-8 pr-7 text-xs [&::-webkit-search-cancel-button]:hidden [&::-webkit-search-decoration]:hidden" />
            <button type="button" x-show="filters.q" x-cloak @click="clearSearch()"
                aria-label="{{ __('conversations.search.clear') }}" data-i18n-al="conversations.search.clear"
                class="absolute right-2 top-1/2 flex h-4 w-4 -translate-y-1/2 items-center justify-center rounded-full text-muted transition-colors hover:text-brand dark:text-night-muted">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        {{-- Pointless when the whole view is already one agent. --}}
        @if (! $scopeAgent && $inboxAgents->count() > 1)
            <select x-model="filters.agent" @change="reload()" class="field shrink-0 rounded-xl px-2 py-1.5 text-xs">
                <option value="">{{ __('conversations.index.all_agents') }}</option>
                @foreach ($inboxAgents as $agentOption)
                    <option value="{{ $agentOption->id }}">{{ $agentOption->name }}</option>
                @endforeach
            </select>
        @endif

        @if ($channelTypes->count() > 1)
            <select x-model="filters.channel" @change="reload()" class="field shrink-0 rounded-xl px-2 py-1.5 text-xs">
                <option value="">{{ __('conversations.index.all_channels') }}</option>
                @foreach ($channelTypes as $type)
                    <option value="{{ $type }}">{{ __("agents.channels.$type.name") }}</option>
                @endforeach
            </select>
        @endif

        {{-- Native date inputs carry a fixed intrinsic width, so they are the
             widest things here; an explicit width keeps the pair predictable. --}}
        <input type="date" x-model="filters.from" @change="reload()" aria-label="{{ __('conversations.index.date_from') }}" data-i18n-al="conversations.index.date_from"
            class="field w-[8.25rem] shrink-0 rounded-xl px-2 py-1.5 text-xs" />
        <input type="date" x-model="filters.to" @change="reload()" aria-label="{{ __('conversations.index.date_to') }}" data-i18n-al="conversations.index.date_to"
            class="field w-[8.25rem] shrink-0 rounded-xl px-2 py-1.5 text-xs" />

        <button type="button" x-show="hasFilters()" x-cloak @click="resetFilters()"
            class="shrink-0 whitespace-nowrap rounded-xl px-2 py-1.5 text-xs font-medium text-muted transition-colors hover:text-brand dark:text-night-muted">
            <span data-i18n="conversations.index.reset_filters">{{ __('conversations.index.reset_filters') }}</span>
        </button>

        {{-- Scoped to this agent when the tab is, since the count comes from
             the same filtered query the list does. --}}
        <span x-show="unreadTotal > 0" x-cloak
            class="shrink-0 whitespace-nowrap rounded-full bg-brand/10 px-2.5 py-1 text-xs font-semibold text-brand">
            <span x-text="unreadTotal"></span> <span data-i18n="conversations.index.unread_badge">{{ __('conversations.index.unread_badge') }}</span>
        </span>
    </div>

    {{-- Inbox.

         The two panes are sized from --list-w, which the drag handle below
         writes. The variable is set unconditionally but only consumed inside
         the lg: grid-template, so the single-column mobile layout is untouched
         by whatever width the owner last dragged to on a desktop. --}}
    <div class="mt-4 grid gap-3 lg:grid-cols-[var(--list-w)_0.75rem_minmax(0,1fr)] lg:gap-0"
        :style="`--list-w: ${listWidth}px`">

        {{-- List pane --}}
        <div :class="openId && 'hidden lg:block'" class="min-w-0">
            <div class="flex {{ $scopeAgent ? 'h-[calc(100vh_-_20rem)]' : 'h-[calc(100vh_-_11.5rem)]' }} min-h-[26rem] flex-col overflow-hidden rounded-2xl border border-black/10 bg-white dark:border-white/10 dark:bg-white/5">

                {{-- Skeletons: only for the very first load, so a poll never blanks the list --}}
                <template x-if="loading && conversations.length === 0">
                    <div class="divide-y divide-black/5 dark:divide-white/10">
                        <template x-for="i in 6" :key="i">
                            <div class="flex animate-pulse gap-3 p-4">
                                <div class="h-9 w-9 shrink-0 rounded-full bg-black/5 dark:bg-white/10"></div>
                                <div class="min-w-0 flex-1 space-y-2">
                                    <div class="h-3 w-1/3 rounded bg-black/5 dark:bg-white/10"></div>
                                    <div class="h-3 w-2/3 rounded bg-black/5 dark:bg-white/10"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loading && conversations.length === 0">
                    <div class="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand/10 text-brand">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.06 0-2.08-.16-3.02-.46L3 21l1.5-4.5C3.55 15.1 3 13.6 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </div>
                        {{-- A failed search says what was looked for; a failed
                             filter says something else entirely. --}}
                        <h2 class="mt-4 text-base font-semibold"
                            x-text="filters.q ? @js(__('conversations.search.empty_title'))
                                : (hasFilters() ? @js(__('conversations.empty.filtered_title')) : @js(__('conversations.empty.title')))"></h2>
                        <p class="mt-1.5 max-w-xs text-sm text-muted dark:text-night-muted"
                            x-text="filters.q ? @js(__('conversations.search.empty_description')).replace(':term', filters.q)
                                : (hasFilters() ? @js(__('conversations.empty.filtered_description')) : @js(__('conversations.empty.description')))"></p>
                    </div>
                </template>

                <div class="min-h-0 flex-1 divide-y divide-black/5 overflow-y-auto dark:divide-white/10" @scroll.debounce.150ms="maybeLoadMore($event.target)">
                    <template x-for="c in conversations" :key="c.id">
                        <button type="button" @click="open(c.id)"
                            :class="openId === c.id ? 'bg-brand/5' : 'hover:bg-black/[0.03] dark:hover:bg-white/5'"
                            class="flex w-full gap-3 p-4 text-left transition-colors">
                            {{-- Channel avatar --}}
                            <div class="relative shrink-0">
                                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand/10 text-sm font-semibold uppercase text-brand"
                                    x-text="(c.customer || '?').slice(0, 1)"></div>
                                <span class="absolute -bottom-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-white ring-2 ring-white dark:bg-night dark:ring-night">
                                    <span x-html="channelIcon(c.channel)" class="flex h-3 w-3 items-center justify-center"></span>
                                </span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-sm font-semibold" :class="c.unread && 'text-ink dark:text-night-ink'" x-text="c.customer"></span>
                                    <span class="shrink-0 text-[11px] tnum text-muted dark:text-night-muted" x-text="shortTime(c.last_message_at)"></span>
                                </div>
                                {{-- While searching the row shows the message that
                                     matched, with the term marked, rather than
                                     whatever happened to be said last. --}}
                                <p class="mt-0.5 truncate text-xs text-muted dark:text-night-muted">
                                    <template x-if="!c.match_excerpt">
                                        <span>
                                            <span x-show="c.preview_role && c.preview_role !== 'user'" x-text="rolePrefix(c.preview_role)" class="font-medium"></span>
                                            <span x-text="c.preview"></span>
                                        </span>
                                    </template>
                                    <template x-if="c.match_excerpt">
                                        <span x-html="highlight(c.match_excerpt, filters.q)"></span>
                                    </template>
                                </p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    {{-- Which agent answered is the whole point in the
                                         global inbox and pure noise in an agent's own tab. --}}
                                    @unless ($scopeAgent)
                                        <span class="rounded-full bg-black/5 px-2 py-0.5 text-[10px] font-medium text-muted dark:bg-white/10 dark:text-night-muted" x-text="c.agent.name"></span>
                                    @endunless
                                    <span x-show="c.status === 'human_takeover'" x-cloak class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-semibold text-warning" x-text="labels.status.human_takeover"></span>
                                    <span x-show="c.status === 'closed'" x-cloak class="rounded-full bg-black/5 px-2 py-0.5 text-[10px] font-semibold text-muted dark:bg-white/10 dark:text-night-muted" x-text="labels.status.closed"></span>
                                    <span x-show="c.leads_count > 0" x-cloak class="flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-semibold text-success">
                                        <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        <span x-text="c.leads_count"></span>
                                    </span>
                                    <span x-show="c.match_count > 1" x-cloak
                                        class="rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-semibold text-brand"
                                        x-text="labels.matchesInChat.replace(':count', c.match_count)"></span>
                                    <span x-show="c.unread" x-cloak class="ml-auto h-2 w-2 shrink-0 rounded-full bg-brand"></span>
                                </div>
                            </div>
                        </button>
                    </template>

                    <div x-show="loadingMore" x-cloak class="flex justify-center p-4">
                        <span class="spinner h-5 w-5"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Drag handle. Desktop only: the mobile layout is one pane at a time,
             so there is nothing to divide. --}}
        <div class="group hidden lg:flex lg:cursor-col-resize lg:items-center lg:justify-center"
            role="separator" aria-orientation="vertical"
            :aria-valuenow="listWidth" aria-label="{{ __('conversations.index.resize_handle') }}"
            @mousedown.prevent="startResize($event)"
            @dblclick="resetWidth()">
            {{-- The whole conditional class list is swapped rather than layering
                 an !important on top, so the dark-mode rule can't out-specify
                 the active state. --}}
            <div class="h-10 w-1 rounded-full transition-colors"
                :class="resizing ? 'bg-brand' : 'bg-black/10 group-hover:bg-brand/50 dark:bg-white/15'"></div>
        </div>

        {{-- Thread pane --}}
        <div :class="!openId && 'hidden lg:block'" class="min-w-0">
            <div class="flex {{ $scopeAgent ? 'h-[calc(100vh_-_20rem)]' : 'h-[calc(100vh_-_11.5rem)]' }} min-h-[26rem] flex-col overflow-hidden rounded-2xl border border-black/10 bg-white dark:border-white/10 dark:bg-white/5">

                <template x-if="!openId">
                    <div class="flex flex-1 flex-col items-center justify-center px-6 text-center">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-black/5 text-muted dark:bg-white/10 dark:text-night-muted">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l1.586-1.586z"/></svg>
                        </div>
                        <h2 class="mt-4 text-base font-semibold" data-i18n="conversations.thread.placeholder_title">{{ __('conversations.thread.placeholder_title') }}</h2>
                        <p class="mt-1.5 max-w-xs text-sm text-muted dark:text-night-muted" data-i18n="conversations.thread.placeholder_description">{{ __('conversations.thread.placeholder_description') }}</p>
                    </div>
                </template>

                <template x-if="openId">
                    <div class="flex min-h-0 flex-1 flex-col">
                        {{-- Thread header --}}
                        <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-black/10 p-3 dark:border-white/10">
                            {{-- Visible at every size: on mobile it goes back to the
                                 list, on desktop it deselects the thread — which is
                                 also what hands the nav rail its width back. --}}
                            <button type="button" @click="close()" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-muted transition-colors hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/10" aria-label="{{ __('conversations.thread.back') }}" data-i18n-al="conversations.thread.back">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold" x-text="thread?.customer"></p>
                                <p class="truncate text-xs text-muted dark:text-night-muted">
                                    @unless ($scopeAgent)
                                        <span x-text="thread?.agent?.name"></span>
                                    @endunless
                                    <span x-show="thread?.channel">@unless ($scopeAgent) · @endunless<span x-text="channelName(thread?.channel)"></span></span>
                                </p>
                            </div>

                            {{-- Match stepper: only while a search is actually
                                 hitting something in this transcript. --}}
                            <div x-show="matchIds.length > 0" x-cloak class="flex shrink-0 items-center gap-0.5 rounded-lg bg-brand/10 px-1 py-0.5">
                                <button type="button" @click="prevMatch()"
                                    aria-label="{{ __('conversations.search.prev_match') }}" data-i18n-al="conversations.search.prev_match"
                                    class="flex h-6 w-6 items-center justify-center rounded-md text-brand transition-colors hover:bg-brand/15">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 15l-6-6-6 6"/></svg>
                                </button>
                                <span class="tnum px-1 text-[11px] font-semibold text-brand"
                                    x-text="(matchIndex + 1) + '/' + matchIds.length"></span>
                                <button type="button" @click="nextMatch()"
                                    aria-label="{{ __('conversations.search.next_match') }}" data-i18n-al="conversations.search.next_match"
                                    class="flex h-6 w-6 items-center justify-center rounded-md text-brand transition-colors hover:bg-brand/15">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                                </button>
                            </div>

                            <div class="flex items-center gap-1.5">
                                {{-- Already on the agent's page when scoped. --}}
                                @unless ($scopeAgent)
                                    <a :href="agentUrl(thread?.agent?.slug)" class="hidden rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted transition-colors hover:text-brand dark:text-night-muted sm:block"
                                        data-i18n="conversations.thread.open_agent">{{ __('conversations.thread.open_agent') }}</a>
                                @endunless

                                <button type="button" x-show="thread?.status !== 'human_takeover'" @click="setStatusFor('human_takeover')"
                                    class="rounded-lg border border-black/10 px-2.5 py-1.5 text-xs font-semibold transition-colors hover:border-brand/40 hover:text-brand dark:border-white/10"
                                    data-i18n="conversations.thread.take_over">{{ __('conversations.thread.take_over') }}</button>

                                <button type="button" x-show="thread?.status === 'human_takeover'" x-cloak @click="setStatusFor('ai')"
                                    class="rounded-lg border border-black/10 px-2.5 py-1.5 text-xs font-semibold transition-colors hover:border-brand/40 hover:text-brand dark:border-white/10"
                                    data-i18n="conversations.thread.hand_back">{{ __('conversations.thread.hand_back') }}</button>

                                <button type="button" x-show="thread?.status !== 'closed'" @click="setStatusFor('closed')"
                                    class="hidden rounded-lg border border-black/10 px-2.5 py-1.5 text-xs font-semibold text-muted transition-colors hover:border-danger/40 hover:text-danger dark:border-white/10 dark:text-night-muted sm:block"
                                    data-i18n="conversations.thread.close">{{ __('conversations.thread.close') }}</button>

                                <button type="button" x-show="thread?.status === 'closed'" x-cloak @click="setStatusFor('ai')"
                                    class="rounded-lg border border-black/10 px-2.5 py-1.5 text-xs font-semibold transition-colors hover:border-brand/40 hover:text-brand dark:border-white/10"
                                    data-i18n="conversations.thread.reopen">{{ __('conversations.thread.reopen') }}</button>
                            </div>
                        </div>

                        {{-- Captured leads --}}
                        <template x-if="leads.length > 0">
                            <div class="shrink-0 border-b border-black/10 bg-success/5 px-4 py-2.5 dark:border-white/10">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold text-success" data-i18n="conversations.thread.leads_heading">{{ __('conversations.thread.leads_heading') }}</span>
                                    <template x-for="lead in leads" :key="lead.id">
                                        <span class="rounded-full bg-white px-2.5 py-0.5 text-[11px] font-medium dark:bg-white/10">
                                            <span x-text="lead.customer_name || lead.contact || '—'"></span>
                                            <span x-show="lead.items" class="text-muted dark:text-night-muted"> · <span x-text="lead.items"></span></span>
                                        </span>
                                    </template>
                                    <a :href="leadsUrl" class="ml-auto text-[11px] font-semibold text-success hover:underline"
                                        data-i18n="conversations.thread.view_in_leads">{{ __('conversations.thread.view_in_leads') }}</a>
                                </div>
                            </div>
                        </template>

                        {{-- Transcript --}}
                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4" x-ref="transcript">
                            <template x-if="threadLoading">
                                <div class="space-y-3">
                                    <template x-for="i in 4" :key="i">
                                        <div class="animate-pulse rounded-2xl bg-black/5 p-4 dark:bg-white/10" :class="i % 2 === 0 ? 'ml-12' : 'mr-12'">
                                            <div class="h-3 w-2/3 rounded bg-black/10 dark:bg-white/10"></div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-for="m in messages" :key="m.id">
                                <div :class="m.role === 'user' ? 'items-start' : 'items-end'" class="flex flex-col" :data-message-id="m.id">
                                    {{-- w-fit so a short reply is a short bubble; the
                                         max-width is capped in rem as well as in %,
                                         because at a dragged-wide panel 85% is a
                                         line far too long to read comfortably. --}}
                                    {{-- x-html, so the search term can be wrapped in
                                         <mark>. highlight() escapes every segment
                                         itself — message content is whatever a
                                         stranger typed and is never trusted. --}}
                                    <div :class="{
                                            'bg-black/[0.04] dark:bg-white/10': m.role === 'user',
                                            'bg-brand/10': m.role === 'assistant',
                                            'bg-brand text-on-accent': m.role === 'operator',
                                            'bg-warning/10 text-warning': m.role === 'system',
                                            'ring-2 ring-brand ring-offset-2 ring-offset-white dark:ring-offset-night': isActiveMatch(m.id),
                                        }"
                                        {{-- pre-LINE, not pre-WRAP: a real line break the
                                             customer typed is kept, but runs of spaces are
                                             collapsed the way every messenger collapses them.

                                             It sits on the SPAN, and the span is glued to the
                                             div's tags with no whitespace between them. Any
                                             newline-preserving rule on the container would
                                             also preserve the newline+indent of the MARKUP —
                                             which rendered as a blank line above the text and
                                             was the real source of the "gap inside the bubble".
                                             Do not pretty-print inside this element. --}}
                                        class="w-fit max-w-[min(75%,34rem)] break-words rounded-2xl px-3.5 py-2 text-left text-sm leading-snug transition-shadow"><span class="whitespace-pre-line" x-html="highlight(m.content, filters.q)"></span></div>
                                    {{-- The customer spoke this rather than typing it. The
                                         bubble above already holds the transcript, so this
                                         only has to say where the words came from. --}}
                                    <div x-show="m.source_type === 'voice'" x-cloak
                                        class="mt-1 flex w-fit items-center gap-1 rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-medium text-brand">
                                        <span aria-hidden="true">🎤</span>
                                        <span data-i18n="conversations.thread.voice_note">{{ __('conversations.thread.voice_note') }}</span>
                                        <span x-show="m.voice_duration" x-text="'· ' + voiceLength(m.voice_duration)" class="tnum"></span>
                                    </div>

                                    {{-- The customer's verdict on this reply, in the
                                         place the verdict is about. A thumbs-down on
                                         the dashboard is a number; here it sits under
                                         the answer that earned it, with the question
                                         still visible above — which is the difference
                                         between knowing the agent was wrong and
                                         knowing what it was wrong about. --}}
                                    <div x-show="m.rating" x-cloak
                                        class="mt-1 flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                        :class="m.rating === 'thumbs_up'
                                            ? 'bg-success/10 text-success'
                                            : 'bg-danger/10 text-danger'"
                                        :title="m.rating === 'thumbs_up' ? labels.ratingUpHint : labels.ratingDownHint">
                                        <span aria-hidden="true" x-text="m.rating === 'thumbs_up' ? labels.ratingUp : labels.ratingDown"></span>
                                        <span x-text="m.rating === 'thumbs_up' ? labels.ratingUpHint : labels.ratingDownHint"></span>
                                    </div>

                                    <div class="mt-1 flex items-center gap-2 px-1 text-[11px] text-muted dark:text-night-muted">
                                        <span x-text="rolePrefix(m.role)"></span>
                                        <span x-text="fullTime(m.created_at)" class="tnum"></span>
                                        <button type="button" x-show="m.chunks && m.chunks.length" x-cloak
                                            @click="expanded === m.id ? expanded = null : expanded = m.id"
                                            class="font-medium text-brand hover:underline">
                                            <span data-i18n="conversations.thread.knowledge_used">{{ __('conversations.thread.knowledge_used') }}</span>
                                            (<span x-text="m.chunks.length"></span>)
                                        </button>
                                    </div>

                                    {{-- Which knowledge chunks fed this answer --}}
                                    <div x-show="expanded === m.id" x-cloak x-transition.opacity.duration.150ms class="mt-1.5 w-full max-w-[85%] space-y-1.5">
                                        <template x-for="chunk in m.chunks" :key="chunk.source + chunk.score">
                                            <div class="rounded-xl border border-black/10 bg-paper p-2.5 text-[11px] dark:border-white/10 dark:bg-white/5">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="truncate font-semibold" x-text="chunk.source || '—'"></span>
                                                    <span class="flex shrink-0 items-center gap-1.5">
                                                        <span x-show="!chunk.confident" x-cloak class="rounded-full bg-warning/10 px-1.5 text-[10px] font-medium text-warning" x-text="labels.knowledgeUnconfirmed"></span>
                                                        <span class="tnum text-muted dark:text-night-muted" x-text="chunk.score"></span>
                                                    </span>
                                                </div>
                                                <p class="mt-1 text-muted dark:text-night-muted" x-text="chunk.excerpt"></p>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Composer --}}
                        <div class="shrink-0 border-t border-black/10 p-3 dark:border-white/10">
                            <template x-if="thread && !thread.can_reply">
                                <p class="px-1 py-2 text-xs text-muted dark:text-night-muted" data-i18n="conversations.thread.no_channel">{{ __('conversations.thread.no_channel') }}</p>
                            </template>
                            <template x-if="thread && thread.can_reply">
                                <form @submit.prevent="send()" class="flex items-end gap-2">
                                    <textarea x-model="draft" rows="1" @keydown.enter.exact.prevent="send()"
                                        @input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'"
                                        placeholder="{{ __('conversations.thread.reply_placeholder') }}" data-i18n-ph="conversations.thread.reply_placeholder"
                                        class="field max-h-40 min-h-[2.5rem] flex-1 resize-none rounded-xl px-3 py-2 text-sm"></textarea>
                                    <button type="submit" :disabled="sending || !draft.trim()"
                                        class="flex h-10 shrink-0 items-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0">
                                        <span x-show="!sending" data-i18n="conversations.thread.send">{{ __('conversations.thread.send') }}</span>
                                        <span x-show="sending" x-cloak class="spinner h-4 w-4"></span>
                                    </button>
                                </form>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
    function conversationsInbox({ listUrl, showUrlBase, replyUrlBase, statusUrlBase, leadsUrl, agentUrlBase, initialFilters, lockedAgent, scoped, labels }) {
        return {
            conversations: [],
            openId: null,
            thread: null,
            messages: [],
            leads: [],
            expanded: null,
            draft: '',
            sending: false,
            loading: true,
            loadingMore: false,
            threadLoading: false,
            page: 1,
            lastPage: 1,
            unreadTotal: 0,
            leadsUrl,
            labels,
            // Width of the list pane in px. Remembered, because re-dragging it
            // on every visit would make the setting worse than useless.
            listWidth: 352,
            resizing: false,

            // --- Message search -------------------------------------------------
            searching: false,
            // Ids of the messages in the OPEN transcript that match the term,
            // oldest first, and which of them is currently focused.
            matchIds: [],
            matchIndex: -1,
            // The term whose top result has already been auto-opened, so typing
            // one more character re-targets but merely polling does not yank the
            // reader back to the top hit.
            autoOpenedFor: null,
            _searchSeq: 0,
            _searchAbort: null,
            lockedAgent,
            scoped,
            filters: {
                status: initialFilters.status || 'all',
                // A locked agent always wins: this is what scopes the agent tab,
                // and it must not be overridable from the query string.
                agent: lockedAgent || initialFilters.agent || '',
                channel: initialFilters.channel || '',
                q: initialFilters.q || '',
                from: initialFilters.from || '',
                to: initialFilters.to || '',
            },

            /** Keeps the pane usable at both ends: never a slit, never the whole page. */
            clampWidth(px) {
                const max = Math.min(560, Math.max(280, window.innerWidth - 520));
                return Math.round(Math.max(280, Math.min(px, max)));
            },

            initWidth() {
                const saved = parseInt(localStorage.getItem('inbox:list-width') || '', 10);
                if (!Number.isNaN(saved)) this.listWidth = this.clampWidth(saved);

                // A window that shrank since the last visit could otherwise leave
                // the thread pane with almost nothing.
                const onResize = () => { this.listWidth = this.clampWidth(this.listWidth); };
                window.addEventListener('resize', onResize);
                this._abortWidthListener = () => window.removeEventListener('resize', onResize);
            },

            startResize(event) {
                this.resizing = true;
                const startX = event.clientX;
                const startWidth = this.listWidth;

                const onMove = (e) => {
                    this.listWidth = this.clampWidth(startWidth + (e.clientX - startX));
                };
                const onUp = () => {
                    this.resizing = false;
                    localStorage.setItem('inbox:list-width', String(this.listWidth));
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    document.body.style.userSelect = '';
                    document.body.style.cursor = '';
                };

                // Without these a drag selects the conversation list as text and
                // the cursor flickers back to a caret between frames.
                document.body.style.userSelect = 'none';
                document.body.style.cursor = 'col-resize';
                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
            },

            resetWidth() {
                this.listWidth = 352;
                localStorage.setItem('inbox:list-width', String(this.listWidth));
            },

            /**
             * Ask the dashboard chrome to narrow (or restore) the nav rail.
             * The sidebar lives in the layout, outside this component's scope,
             * so the two talk through an event rather than shared state.
             */
            setRail(collapsed) {
                window.dispatchEvent(new CustomEvent('inbox-focus', { detail: { collapsed } }));
            },

            // ---------------------------------------------------------------
            // Search: highlighting and jump-to-message
            // ---------------------------------------------------------------

            escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            },

            /**
             * Message text with every occurrence of the term wrapped in <mark>.
             *
             * Each slice is escaped separately rather than escaping the whole
             * string first: escaping first would let a search for "amp" light up
             * the middle of an &amp; entity that the reader never typed.
             */
            highlight(content, term) {
                const text = this.bubbleText(content);
                const needle = (term || '').trim();
                if (!needle) return this.escapeHtml(text);

                const pattern = needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const re = new RegExp(pattern, 'gi');
                const mark = '<mark class="rounded bg-brand/30 px-0.5 text-inherit">';

                let out = '';
                let last = 0;
                let hit;
                while ((hit = re.exec(text)) !== null) {
                    out += this.escapeHtml(text.slice(last, hit.index));
                    out += mark + this.escapeHtml(hit[0]) + '</mark>';
                    last = hit.index + hit[0].length;
                    if (hit[0].length === 0) re.lastIndex++; // never loop on an empty match
                }
                return out + this.escapeHtml(text.slice(last));
            },

            /**
             * Which loaded messages contain the term. The transcript is already
             * fully loaded, so this needs no extra request — and it stays in
             * step automatically when a poll appends new messages.
             */
            locateMatches() {
                const needle = (this.filters.q || '').trim().toLowerCase();
                if (!needle) {
                    this.matchIds = [];
                    this.matchIndex = -1;
                    return;
                }

                this.matchIds = this.messages
                    .filter((m) => String(m.content ?? '').toLowerCase().includes(needle))
                    .map((m) => m.id);
                // Land on the most recent hit — the one the list ordered by.
                this.matchIndex = this.matchIds.length ? this.matchIds.length - 1 : -1;
            },

            /** Seconds as m:ss — a voice note's length, beside its transcript. */
            voiceLength(seconds) {
                const total = Math.max(0, parseInt(seconds, 10) || 0);

                return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
            },

            isActiveMatch(id) {
                return this.matchIndex >= 0 && this.matchIds[this.matchIndex] === id;
            },

            scrollToMatch() {
                if (this.matchIndex < 0) return;
                const id = this.matchIds[this.matchIndex];
                this.$nextTick(() => {
                    const el = this.$refs.transcript?.querySelector(`[data-message-id="${id}"]`);
                    // Centred, not at the top edge: the messages around a hit are
                    // the context that makes it readable.
                    el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                });
            },

            nextMatch() {
                if (!this.matchIds.length) return;
                this.matchIndex = (this.matchIndex + 1) % this.matchIds.length;
                this.scrollToMatch();
            },

            prevMatch() {
                if (!this.matchIds.length) return;
                this.matchIndex = (this.matchIndex - 1 + this.matchIds.length) % this.matchIds.length;
                this.scrollToMatch();
            },

            clearSearch() {
                this.filters.q = '';
                this.autoOpenedFor = null;
                this.matchIds = [];
                this.matchIndex = -1;
                this.reload();
                // Back to the normal newest-at-bottom reading position.
                if (this.openId) this.scrollToLatest();
                this.$refs.search?.focus();
            },

            /**
             * Open the top hit as soon as results land, the way Telegram drops
             * you on the best match while you are still typing. Guarded by the
             * term so the 15s poll can't drag the reader off what they opened.
             */
            autoOpenTopMatch() {
                const term = (this.filters.q || '').trim();

                if (!term) {
                    this.autoOpenedFor = null;
                    return;
                }
                if (this.autoOpenedFor === term || this.conversations.length === 0) return;

                this.autoOpenedFor = term;
                this.open(this.conversations[0].id);
            },

            /**
             * Model replies arrive with the paragraph breaks the model chose,
             * which after splitting can leave runs of blank lines that render as
             * dead space inside the bubble. Collapse them to a single blank line
             * and trim the ends; genuine paragraph breaks survive.
             */
            bubbleText(content) {
                return String(content ?? '')
                    .replace(/\r\n/g, '\n')
                    // Non-breaking and other exotic spaces render as a visible
                    // gap and never wrap; models emit them more often than you
                    // would think, and they are invisible in the raw text.
                    .replace(/[  -​  　﻿]/g, ' ')
                    .replace(/[ \t]+\n/g, '\n')
                    // Runs of spaces mid-sentence: the other half of the
                    // stretched-gap problem.
                    .replace(/[ \t]{2,}/g, ' ')
                    .replace(/\n{3,}/g, '\n\n')
                    .trim();
            },

            init() {
                this.initWidth();
                this.reload();

                // Deep link: /conversations?conversation=123 opens that thread
                // straight away. The dashboard's 👎 list links here, and the
                // point of that list is landing on the reply that earned the
                // thumbs-down — an inbox that merely opened would make the
                // owner hunt for it. open() fetches the thread itself, so it
                // does not matter that reload() above has not returned yet.
                const deepLink = Number(new URLSearchParams(window.location.search).get('conversation'));
                if (Number.isInteger(deepLink) && deepLink > 0) this.open(deepLink);

                // Same cadence as the lead bell, and paused while the tab is
                // hidden so a backgrounded inbox costs nothing.
                this._pollTimer = setInterval(() => {
                    if (document.visibilityState === 'visible') this.poll();
                }, 15000);
                this._onVisible = () => {
                    if (document.visibilityState === 'visible') this.poll();
                };
                document.addEventListener('visibilitychange', this._onVisible);
            },

            /**
             * Alpine calls this when the component is torn down — which happens
             * on the agent page, where the tab only mounts while it is selected.
             * Without it, every visit to the tab would leave another poll running.
             */
            destroy() {
                clearInterval(this._pollTimer);
                document.removeEventListener('visibilitychange', this._onVisible);
                this._abortWidthListener?.();
            },

            headers() {
                return {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                };
            },

            queryString(page) {
                const params = new URLSearchParams();
                Object.entries(this.filters).forEach(([key, value]) => {
                    if (value) params.set(key, value);
                });
                if (page > 1) params.set('page', page);
                return params.toString();
            },

            hasFilters() {
                // The locked agent is the view's identity, not a filter the
                // owner chose, so it must not light up "reset filters".
                const agentFiltered = !this.lockedAgent && !!this.filters.agent;
                return this.filters.status !== 'all' || agentFiltered || !!this.filters.channel
                    || !!this.filters.q || !!this.filters.from || !!this.filters.to;
            },

            setStatus(status) {
                this.filters.status = status;
                this.reload();
            },

            resetFilters() {
                this.filters = {
                    status: 'all',
                    agent: this.lockedAgent || '',
                    channel: '', q: '', from: '', to: '',
                };
                this.autoOpenedFor = null;
                this.matchIds = [];
                this.matchIndex = -1;
                this.reload();
            },

            async reload() {
                this.loading = true;
                this.searching = !!(this.filters.q || '').trim();
                this.page = 1;

                // Each keystroke supersedes the one before it: cancel the request
                // in flight and ignore anything that arrives out of order, so a
                // slow early response can never overwrite a newer list.
                const seq = ++this._searchSeq;
                this._searchAbort?.abort();
                this._searchAbort = new AbortController();

                const data = await this.fetchPage(1, this._searchAbort.signal);
                if (seq !== this._searchSeq) return;

                this.conversations = data?.data ?? [];
                this.loading = false;
                this.searching = false;

                // Re-mark hits in an already-open transcript as the term changes.
                this.locateMatches();
                this.autoOpenTopMatch();

                // Only the standalone page owns its address bar. On the agent
                // tab the URL carries ?tab=, which these filters would wipe out.
                if (this.scoped) return;

                // Keep the URL shareable/reloadable without a navigation.
                const qs = this.queryString(1);
                window.history.replaceState({}, '', qs ? `?${qs}` : window.location.pathname);
            },

            async fetchPage(page, signal = undefined) {
                try {
                    const res = await fetch(`${listUrl}?${this.queryString(page)}`, {
                        headers: { Accept: 'application/json' },
                        signal,
                    });
                    if (!res.ok) return null;
                    const data = await res.json();
                    this.lastPage = data.last_page;
                    this.unreadTotal = data.unread_total;
                    return data;
                } catch (e) {
                    return null; // aborted or transient — the next keystroke retries
                }
            },

            async maybeLoadMore(el) {
                if (this.loadingMore || this.page >= this.lastPage) return;
                if (el.scrollTop + el.clientHeight < el.scrollHeight - 200) return;

                this.loadingMore = true;
                const data = await this.fetchPage(this.page + 1);
                if (data) {
                    this.page = data.current_page;
                    const seen = new Set(this.conversations.map((c) => c.id));
                    this.conversations.push(...data.data.filter((c) => !seen.has(c.id)));
                }
                this.loadingMore = false;
            },

            /**
             * Refresh the head of the list in place: new conversations appear,
             * existing rows update their preview/unread state, and anything
             * already scrolled past is left alone.
             */
            async poll() {
                const data = await this.fetchPage(1);
                if (!data) return;

                const byId = new Map(this.conversations.map((c) => [c.id, c]));
                data.data.forEach((fresh) => {
                    // The open thread is being read right now — don't flip it
                    // back to unread underneath the reader.
                    if (fresh.id === this.openId) fresh.unread = false;
                    byId.set(fresh.id, fresh);
                });

                this.conversations = Array.from(byId.values())
                    .sort((a, b) => new Date(b.last_message_at) - new Date(a.last_message_at));

                if (this.openId) this.refreshOpenThread();
            },

            async refreshOpenThread() {
                const id = this.openId;
                try {
                    const res = await fetch(showUrlBase.replace('__ID__', id), { headers: { Accept: 'application/json' } });
                    if (!res.ok || this.openId !== id) return;
                    const data = await res.json();
                    if (data.messages.length !== this.messages.length) {
                        this.messages = data.messages;
                        this.leads = data.leads;
                        this.locateMatches();
                        // A new message arriving must not yank the reader away
                        // from the hit they are reading.
                        if (!this.matchIds.length) this.scrollToLatest();
                    }
                    this.thread = data.conversation;
                } catch (e) { /* transient */ }
            },

            async open(id) {
                this.openId = id;
                this.setRail(true);
                this.threadLoading = true;
                this.messages = [];
                this.leads = [];
                this.expanded = null;

                try {
                    const res = await fetch(showUrlBase.replace('__ID__', id), { headers: { Accept: 'application/json' } });
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    const data = await res.json();
                    this.thread = data.conversation;
                    this.messages = data.messages;
                    this.leads = data.leads;

                    // Reading it clears the unread mark here and in the badge.
                    const row = this.conversations.find((c) => c.id === id);
                    if (row?.unread) {
                        row.unread = false;
                        this.unreadTotal = Math.max(0, this.unreadTotal - 1);
                    }
                    // Searching? Land on the matched message and highlight it,
                    // rather than at the bottom where the hit may be far above.
                    this.locateMatches();
                    if (this.matchIds.length) {
                        this.scrollToMatch();
                    } else {
                        this.scrollToLatest();
                    }
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                } finally {
                    this.threadLoading = false;
                }
            },

            close() {
                this.openId = null;
                this.thread = null;
                this.messages = [];
                this.matchIds = [];
                this.matchIndex = -1;
                this.setRail(false);
            },

            scrollToLatest() {
                this.$nextTick(() => {
                    const el = this.$refs.transcript;
                    if (el) el.scrollTop = el.scrollHeight;
                });
            },

            async send() {
                const content = this.draft.trim();
                if (!content || this.sending) return;

                this.sending = true;
                try {
                    const res = await fetch(replyUrlBase.replace('__ID__', this.openId), {
                        method: 'POST',
                        headers: this.headers(),
                        body: JSON.stringify({ content }),
                    });

                    if (!res.ok) {
                        const body = await res.json().catch(() => ({}));
                        window.showToast?.(body.message || labels.failed, 'error');
                        return;
                    }

                    const data = await res.json();
                    this.messages.push(data.message);
                    this.draft = '';
                    if (this.thread) this.thread.status = data.status;

                    const row = this.conversations.find((c) => c.id === this.openId);
                    if (row) {
                        row.status = data.status;
                        row.preview = data.message.content.slice(0, 120);
                        row.preview_role = 'operator';
                        row.last_message_at = data.message.created_at;
                    }

                    this.scrollToLatest();
                    window.showToast?.(labels.sent, 'success');
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                } finally {
                    this.sending = false;
                }
            },

            async setStatusFor(status) {
                try {
                    const res = await fetch(statusUrlBase.replace('__ID__', this.openId), {
                        method: 'PATCH',
                        headers: this.headers(),
                        body: JSON.stringify({ status }),
                    });
                    if (!res.ok) {
                        window.showToast?.(labels.failed, 'error');
                        return;
                    }
                    const data = await res.json();
                    if (this.thread) this.thread.status = data.status;
                    const row = this.conversations.find((c) => c.id === this.openId);
                    if (row) row.status = data.status;

                    const toast = { human_takeover: labels.takenOver, ai: labels.handedBack, closed: labels.closed }[status];
                    window.showToast?.(toast, 'success');
                } catch (e) {
                    window.showToast?.(labels.network, 'error');
                }
            },

            agentUrl(slug) {
                return slug ? agentUrlBase.replace('__SLUG__', slug) : '#';
            },

            rolePrefix(role) {
                return labels.roles[role] ?? '';
            },

            channelName(type) {
                return { telegram: 'Telegram', whatsapp: 'WhatsApp', greenapi: 'WhatsApp', instagram: 'Instagram' }[type] ?? '';
            },

            channelIcon(type) {
                const icons = {
                    telegram: '<svg viewBox="0 0 24 24" fill="#229ED9" class="h-3 w-3"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.568 8.16l-1.61 7.59c-.12.539-.44.672-.888.418l-2.456-1.81-1.185 1.14c-.131.131-.24.24-.492.24l.175-2.49 4.53-4.093c.197-.175-.043-.273-.305-.098l-5.6 3.526-2.412-.755c-.524-.164-.535-.524.11-.776l9.42-3.63c.437-.16.82.098.677.777z"/></svg>',
                    whatsapp: '<svg viewBox="0 0 24 24" fill="#25D366" class="h-3 w-3"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 016.988 2.898 9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>',
                    instagram: '<svg viewBox="0 0 24 24" fill="#D62976" class="h-3 w-3"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
                };
                return icons[type] ?? (type === 'greenapi' ? icons.whatsapp : '');
            },

            shortTime(iso) {
                if (!iso) return '';
                const date = new Date(iso);
                const now = new Date();
                const sameDay = date.toDateString() === now.toDateString();
                return sameDay
                    ? date.toLocaleTimeString(document.documentElement.lang || 'ru', { hour: '2-digit', minute: '2-digit' })
                    : date.toLocaleDateString(document.documentElement.lang || 'ru', { day: '2-digit', month: '2-digit' });
            },

            fullTime(iso) {
                if (!iso) return '';
                return new Date(iso).toLocaleString(document.documentElement.lang || 'ru', {
                    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
                });
            },
        };
    }
</script>
@endpush
@endonce
