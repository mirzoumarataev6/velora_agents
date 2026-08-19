{{--
    "Send my leads to Telegram" for one agent.

    Expects: $agent. Optional: $showAgentName (bool) — the account-wide settings
    page renders one of these per agent and needs to say which is which, while
    the agent's own Settings tab already has that context in the page header.

    The link is completed by the owner tapping Start in the bot; the component
    polls the status endpoint until the webhook records it.
--}}
@php
    $telegramConnected = optional($agent->channels->firstWhere('type', 'telegram'))->status === 'connected';
@endphp
<div class="rounded-2xl border border-black/10 bg-white p-5 transition-shadow hover:shadow-md dark:border-white/10 dark:bg-white/5"
    x-data="leadNotify({
        linkUrl: {{ Js::from(route('agents.notify.telegram.link', $agent)) }},
        statusUrl: {{ Js::from(route('agents.notify.telegram.status', $agent)) }},
        unlinkUrl: {{ Js::from(route('agents.notify.telegram.unlink', $agent)) }},
        telegramConnected: {{ Js::from($telegramConnected) }},
        initial: {{ Js::from(['linked' => $agent->notifiesOwnerViaTelegram(), 'username' => $agent->notify_telegram_username]) }},
        labels: {{ Js::from([
            'linked' => __('leads.notify.toasts.linked'),
            'unlinked' => __('leads.notify.toasts.unlinked'),
            'failed' => __('leads.notify.toasts.failed'),
            'network' => __('leads.notify.toasts.network'),
        ]) }},
    })">
    <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#229ED9]/10 text-[#229ED9]">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.64 6.8-1.667 7.868c-.126.56-.455.698-.922.435l-2.548-1.878-1.23 1.184c-.136.136-.25.25-.512.25l.183-2.6 4.73-4.276c.205-.183-.045-.285-.318-.102l-5.848 3.68-2.52-.788c-.548-.172-.558-.548.114-.81l9.85-3.797c.457-.164.857.11.71.834z"/></svg>
        </div>
        <div class="min-w-0">
            @if ($showAgentName ?? false)
                <h3 class="truncate text-base font-semibold">{{ $agent->name }}</h3>
                <p class="mt-0.5 text-sm text-muted dark:text-night-muted" data-i18n="leads.notify.description">{{ __('leads.notify.description') }}</p>
            @else
                <h3 class="text-base font-semibold" data-i18n="leads.notify.heading">{{ __('leads.notify.heading') }}</h3>
                <p class="mt-0.5 text-sm text-muted dark:text-night-muted" data-i18n="leads.notify.description">{{ __('leads.notify.description') }}</p>
            @endif
        </div>
    </div>

    {{-- Linked state --}}
    <div x-show="linked" x-cloak class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-success/5 px-4 py-3">
        <span class="text-sm font-medium text-success dark:text-success-light">
            <span data-i18n="leads.notify.linked_as">{{ __('leads.notify.linked_as') }}</span> <span x-text="username ? '@' + username : ''"></span>
        </span>

        <div class="flex items-center gap-2">
            <button type="button" x-show="!confirmingUnlink" @click="askUnlink()"
                class="rounded-full border border-danger/30 px-3 py-1.5 text-xs font-semibold text-danger transition-colors hover:bg-danger/5 dark:text-danger-light">
                <span data-i18n="leads.notify.unlink">{{ __('leads.notify.unlink') }}</span>
            </button>

            <template x-if="confirmingUnlink">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-muted dark:text-night-muted" data-i18n="leads.notify.unlink_confirm_inline">{{ __('leads.notify.unlink_confirm_inline') }}</span>
                    <button type="button" @click="unlink()"
                        class="rounded-full bg-danger px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-danger-deep">
                        <span data-i18n="leads.notify.unlink_yes">{{ __('leads.notify.unlink_yes') }}</span>
                    </button>
                    <button type="button" @click="cancelUnlink()"
                        class="rounded-full border border-black/10 px-3 py-1.5 text-xs font-semibold text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/5">
                        <span data-i18n="leads.notify.unlink_no">{{ __('leads.notify.unlink_no') }}</span>
                    </button>
                </div>
            </template>
        </div>
    </div>

    {{-- Unlinked state --}}
    <div x-show="!linked" x-cloak class="mt-4">
        <template x-if="!telegramConnected">
            <p class="rounded-xl bg-warning/10 px-4 py-3 text-sm text-warning dark:text-warning-light" data-i18n="leads.notify.telegram_required">{{ __('leads.notify.telegram_required') }}</p>
        </template>

        <template x-if="telegramConnected">
            <div>
                <button type="button" @click="startLink()" :disabled="linking"
                    class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover disabled:opacity-60">
                    <span x-show="!linking" data-i18n="leads.notify.link_button">{{ __('leads.notify.link_button') }}</span>
                    <span x-show="linking" class="spinner"></span>
                </button>
                <p x-show="deepLink" x-cloak class="mt-3 text-sm text-muted dark:text-night-muted">
                    <span data-i18n="leads.notify.waiting">{{ __('leads.notify.waiting') }}</span>
                    <a :href="deepLink" target="_blank" class="font-medium text-brand hover:underline" data-i18n="leads.notify.open_telegram">{{ __('leads.notify.open_telegram') }}</a>
                </p>
            </div>
        </template>
    </div>
</div>

@include('partials.lead-notify-script')
