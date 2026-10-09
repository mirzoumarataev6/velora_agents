{{--
    In-app lead notification bell. Reads the `shell` Alpine store (see
    resources/js/app.js) rather than a local x-data scope, so it works
    identically wherever it's included — the sidebar and the mobile header,
    both `data-turbo-permanent` now and no longer sharing a common x-data
    ancestor. Both instances read the same store.
--}}
<div class="relative">
    <button type="button" @click="$store.shell.toggleBell()" aria-label="{{ __('leads.bell.label') }}"
        class="relative flex h-9 w-9 items-center justify-center rounded-full text-muted transition-colors hover:bg-sunk dark:text-night-muted dark:hover:bg-night-raised">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        {{-- A small notification pill, not a button: `.btn` here previously
             rendered as a chunky blue square (button padding/radius on a
             one- or two-character label). Same visual language as the
             sidebar's `.nav-unread-count` — brand-strong fill, white text,
             10px tabular figures — just absolutely positioned over the icon,
             with a ring in the icon's own background colour so it reads as
             cut into the bell rather than floating on top of it. --}}
        <span x-show="$store.shell.count > 0" x-cloak x-text="$store.shell.count > 99 ? '99+' : $store.shell.count"
            class="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-brand-strong px-1 text-[10px] font-bold leading-none tabular-nums text-white ring-2 ring-white dark:ring-night"></span>
    </button>

    <div x-show="$store.shell.bellOpen" x-cloak @click.outside="$store.shell.bellOpen = false"
        x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
        class="card absolute left-0 top-full z-50 mt-2 w-72 overflow-hidden">
        <div class="flex items-center justify-between border-b border-hairline px-4 py-3 dark:border-white/10">
            <span class="text-sm font-semibold">{{ __('leads.bell.title') }}</span>
            <button type="button" @click="$store.shell.markSeen()" x-show="$store.shell.count > 0"
                class="text-xs font-medium text-brand-strong hover:underline">{{ __('leads.bell.mark_seen') }}</button>
        </div>

        <div class="max-h-80 overflow-y-auto">
            <template x-if="$store.shell.recent.length === 0 && $store.shell.escalations.length === 0">
                <p class="px-4 py-6 text-center text-sm text-muted dark:text-night-muted">{{ __('leads.bell.empty') }}</p>
            </template>

            {{-- Escalations first: an angry customer waiting is more urgent than
                 a new lead, and it is the thing that decays if left. --}}
            <template x-for="item in $store.shell.escalations" :key="'esc-' + item.id">
                <a :href="$store.shell.needsHumanUrl"
                    class="flex flex-col gap-0.5 border-b border-hairline bg-warning/5 px-4 py-2.5 transition-colors hover:bg-warning/10 dark:border-white/10">
                    <span class="flex items-center gap-2">
                        <svg class="h-3.5 w-3.5 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        <span class="truncate text-sm font-medium" x-text="item.customer"></span>
                    </span>
                    <span class="truncate text-xs text-warning dark:text-warning-light"
                        x-text="item.summary || ($store.shell.labels.signals[item.signal] ?? $store.shell.labels.needsHuman)"></span>
                </a>
            </template>

            <template x-for="lead in $store.shell.recent" :key="lead.id">
                <a :href="$store.shell.leadsUrl"
                    class="flex flex-col gap-0.5 border-b border-hairline px-4 py-2.5 transition-colors hover:bg-sunk dark:border-white/10 dark:hover:bg-night-raised"
                    :class="!lead.seen ? 'bg-azure-50' : ''">
                    <span class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand" x-show="!lead.seen"></span>
                        <span class="truncate text-sm font-medium" x-text="lead.customer_name || lead.contact || @js(__('leads.bell.no_name'))"></span>
                    </span>
                    <span class="truncate text-xs text-muted dark:text-night-muted" x-text="lead.items || lead.agent_name || ''"></span>
                </a>
            </template>
        </div>

        <a :href="$store.shell.leadsUrl" class="block border-t border-hairline px-4 py-2.5 text-center text-sm font-medium text-brand-strong hover:bg-brand/5 dark:border-white/10">
            {{ __('leads.bell.view_all') }}
        </a>
    </div>
</div>
