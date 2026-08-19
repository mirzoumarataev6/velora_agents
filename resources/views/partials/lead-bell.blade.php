{{--
    In-app lead notification bell. Rendered inside the dashboardShell() Alpine
    scope (see layouts/dashboard.blade.php), so it shares the polled `count`,
    `recent`, and `bellOpen` state. Included in both the sidebar and the mobile
    header — both instances read the same reactive state.
--}}
<div class="relative">
    <button type="button" @click="toggleBell()" aria-label="{{ __('leads.bell.label') }}"
        class="relative flex h-9 w-9 items-center justify-center rounded-full text-muted transition-colors hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/10">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        <span x-show="count > 0" x-cloak x-text="count > 99 ? '99+' : count"
            class="absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-brand px-1 text-center text-[10px] font-bold leading-[18px] text-on-accent"></span>
    </button>

    <div x-show="bellOpen" x-cloak @click.outside="bellOpen = false"
        x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
        class="absolute left-0 top-full z-50 mt-2 w-72 overflow-hidden rounded-2xl border border-black/10 bg-white shadow-xl dark:border-white/10 dark:bg-night">
        <div class="flex items-center justify-between border-b border-black/10 px-4 py-3 dark:border-white/10">
            <span class="text-sm font-semibold">{{ __('leads.bell.title') }}</span>
            <button type="button" @click="markSeen()" x-show="count > 0"
                class="text-xs font-medium text-brand hover:underline">{{ __('leads.bell.mark_seen') }}</button>
        </div>

        <div class="max-h-80 overflow-y-auto">
            <template x-if="recent.length === 0 && escalations.length === 0">
                <p class="px-4 py-6 text-center text-sm text-muted dark:text-night-muted">{{ __('leads.bell.empty') }}</p>
            </template>

            {{-- Escalations first: an angry customer waiting is more urgent than
                 a new lead, and it is the thing that decays if left. --}}
            <template x-for="item in escalations" :key="'esc-' + item.id">
                <a :href="needsHumanUrl"
                    class="flex flex-col gap-0.5 border-b border-black/5 bg-warning/5 px-4 py-2.5 transition-colors hover:bg-warning/10 dark:border-white/5">
                    <span class="flex items-center gap-2">
                        <svg class="h-3.5 w-3.5 shrink-0 text-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        <span class="truncate text-sm font-medium" x-text="item.customer"></span>
                    </span>
                    <span class="truncate text-xs text-warning dark:text-warning-light"
                        x-text="item.summary || (labels.signals[item.signal] ?? labels.needsHuman)"></span>
                </a>
            </template>

            <template x-for="lead in recent" :key="lead.id">
                <a :href="leadsUrl"
                    class="flex flex-col gap-0.5 border-b border-black/5 px-4 py-2.5 transition-colors hover:bg-black/5 dark:border-white/5 dark:hover:bg-white/5"
                    :class="!lead.seen ? 'bg-brand/5' : ''">
                    <span class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand" x-show="!lead.seen"></span>
                        <span class="truncate text-sm font-medium" x-text="lead.customer_name || lead.contact || @js(__('leads.bell.no_name'))"></span>
                    </span>
                    <span class="truncate text-xs text-muted dark:text-night-muted" x-text="lead.items || lead.agent_name || ''"></span>
                </a>
            </template>
        </div>

        <a :href="leadsUrl" class="block border-t border-black/10 px-4 py-2.5 text-center text-sm font-medium text-brand hover:bg-brand/5 dark:border-white/10">
            {{ __('leads.bell.view_all') }}
        </a>
    </div>
</div>
