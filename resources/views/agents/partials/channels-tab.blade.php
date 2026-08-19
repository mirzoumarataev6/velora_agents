@php
    $telegram = $agent->channels->firstWhere('type', 'telegram');
    $webhookUrl = $telegram ? ($telegram->settings['webhook_url'] ?? route('webhooks.telegram', $telegram)) : null;
    $whatsapp = $agent->channels->firstWhere('type', 'whatsapp');
    $instagram = $agent->channels->firstWhere('type', 'instagram');
@endphp

<div class="max-w-2xl space-y-6">
    {{-- Telegram card --}}
    <div class="rounded-2xl border border-black/10 bg-white p-5 transition-shadow hover:shadow-md dark:border-white/10 dark:bg-white/5"
        x-data="telegramChannel({
            connectUrl: '{{ route('agents.channels.telegram.connect', $agent) }}',
            disconnectUrlBase: '{{ route('agents.channels.telegram.disconnect', ['agent' => $agent, 'channel' => '__ID__']) }}',
            initial: {{ Js::from($telegram ? [
                'id' => $telegram->id,
                'status' => $telegram->status,
                'bot_username' => $telegram->settings['bot_username'] ?? null,
                'webhook_url' => $webhookUrl,
            ] : null) }},
        })">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#229ED9]/10 text-[#229ED9]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.64 6.8-1.667 7.868c-.126.56-.455.698-.922.435l-2.548-1.878-1.23 1.184c-.136.136-.25.25-.512.25l.183-2.6 4.73-4.276c.205-.183-.045-.285-.318-.102l-5.848 3.68-2.52-.788c-.548-.172-.558-.548.114-.81l9.85-3.797c.457-.164.857.11.71.834z"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold">{{ __('agents.channels.telegram.name') }}</h3>
                    <p class="text-xs text-muted dark:text-night-muted">
                        <template x-if="channel?.status === 'connected'">
                            <span>{{ __('agents.channels.telegram.connected') }}<span x-text="channel.bot_username"></span></span>
                        </template>
                        <template x-if="channel?.status === 'error'">
                            <span>{{ __('agents.channels.telegram.error') }}</span>
                        </template>
                        <template x-if="!channel || channel.status === 'disconnected'">
                            <span>{{ __('agents.channels.telegram.disconnected') }}</span>
                        </template>
                    </p>
                </div>
            </div>
            <span class="h-2.5 w-2.5 shrink-0 rounded-full"
                :class="channel?.status === 'connected' ? 'bg-success' : (channel?.status === 'error' ? 'bg-danger' : 'bg-black/20 dark:bg-white/20')"></span>
        </div>

        <div x-show="channel?.status === 'connected'" class="mt-4 space-y-3">
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.webhook_url_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="channel?.webhook_url"></code>
                    <button type="button" @click="navigator.clipboard.writeText(channel.webhook_url); window.showToast?.(@json(__('agents.channels.shared.copied')), 'success')"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>

            <button type="button" @click="disconnect()" :disabled="disconnecting"
                class="rounded-full border border-danger/30 px-4 py-2 text-sm font-semibold text-danger transition-all hover:-translate-y-0.5 hover:bg-danger/5 disabled:opacity-60 dark:text-danger-light">
                <span x-show="!disconnecting">{{ __('agents.channels.shared.disconnect') }}</span>
                <span x-show="disconnecting" class="spinner"></span>
            </button>
        </div>

        <div x-show="channel?.status !== 'connected'">
            <form @submit.prevent="connect()" class="mt-4 space-y-2">
                <label for="bot_token" class="text-sm font-medium">{{ __('agents.channels.telegram.token_label') }}</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input id="bot_token" x-model="botToken" type="text" required placeholder="{{ __('agents.channels.telegram.token_placeholder') }}"
 class="field flex-1 rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <button type="submit" :disabled="connecting" class="shrink-0 rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover disabled:opacity-60">
                        <span x-show="!connecting">{{ __('agents.channels.shared.connect') }}</span>
                        <span x-show="connecting" class="spinner"></span>
                    </button>
                </div>
                <p x-show="errors.bot_token" x-text="errors.bot_token?.[0]" class="text-xs text-danger"></p>
            </form>

            <button type="button" @click="showInstructions = !showInstructions" class="mt-3 flex items-center gap-1 text-xs font-medium text-muted hover:text-brand dark:text-night-muted">
                <svg class="h-3 w-3 transition-transform" :class="showInstructions ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('agents.channels.telegram.instructions_toggle') }}
            </button>
            <ol x-show="showInstructions" x-cloak x-transition.opacity.duration.200ms class="mt-2 list-decimal space-y-1 pl-5 text-xs text-muted dark:text-night-muted">
                @foreach (__('agents.channels.telegram.steps') as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- WhatsApp card --}}
    <div class="rounded-2xl border border-black/10 bg-white p-5 transition-shadow hover:shadow-md dark:border-white/10 dark:bg-white/5"
        x-data="metaChannel({
            connectUrl: '{{ route('agents.channels.whatsapp.connect', $agent) }}',
            disconnectUrlBase: '{{ route('agents.channels.whatsapp.disconnect', ['agent' => $agent, 'channel' => '__ID__']) }}',
            fields: ['phone_number_id', 'access_token', 'verify_token'],
            fallbackWebhookUrl: '{{ route('webhooks.whatsapp', ['channel' => $whatsapp?->uuid ?? '__UUID__']) }}',
            initial: {{ Js::from($whatsapp ? [
                'id' => $whatsapp->id,
                'status' => $whatsapp->status,
                'display_phone_number' => $whatsapp->settings['display_phone_number'] ?? null,
                'verified_name' => $whatsapp->settings['verified_name'] ?? null,
                'webhook_url' => $whatsapp->settings['webhook_url'] ?? route('webhooks.whatsapp', $whatsapp),
                'verify_token' => $whatsapp->credentials['verify_token'] ?? null,
            ] : null) }},
        })">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#25D366]/10 text-[#25D366]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.938-1.396A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18a7.94 7.94 0 01-4.06-1.115l-.29-.172-3.017.853.867-2.94-.19-.303A7.947 7.947 0 014 12c0-4.411 3.589-8 8-8s8 3.589 8 8-3.589 8-8 8zm4.36-5.99c-.24-.12-1.42-.7-1.64-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06-.24-.12-1.01-.372-1.925-1.188-.712-.635-1.192-1.42-1.332-1.66-.14-.24-.015-.37.105-.49.108-.108.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.195-.468-.393-.405-.54-.412-.14-.007-.3-.008-.46-.008-.16 0-.42.06-.64.3-.22.24-.84.82-.84 2s.86 2.32.98 2.48c.12.16 1.694 2.586 4.104 3.626.573.247 1.02.395 1.369.505.575.183 1.098.157 1.512.095.461-.069 1.42-.58 1.62-1.14.2-.56.2-1.04.14-1.14-.06-.1-.22-.16-.46-.28z"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold">{{ __('agents.channels.whatsapp.name') }}</h3>
                    <p class="text-xs text-muted dark:text-night-muted">
                        <template x-if="channel?.status === 'connected'">
                            <span>{{ __('agents.channels.whatsapp.connected') }}<template x-if="channel.display_phone_number"> · <span x-text="channel.display_phone_number"></span></template></span>
                        </template>
                        <template x-if="channel?.status === 'error'">
                            <span>{{ __('agents.channels.whatsapp.error') }}</span>
                        </template>
                        <template x-if="!channel || channel.status === 'disconnected'">
                            <span>{{ __('agents.channels.whatsapp.disconnected') }}</span>
                        </template>
                    </p>
                </div>
            </div>
            <span class="h-2.5 w-2.5 shrink-0 rounded-full"
                :class="channel?.status === 'connected' ? 'bg-success' : (channel?.status === 'error' ? 'bg-danger' : 'bg-black/20 dark:bg-white/20')"></span>
        </div>

        <div x-show="channel?.status === 'connected'" class="mt-4 space-y-3">
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.callback_url_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="channel?.webhook_url"></code>
                    <button type="button" @click="copy(channel.webhook_url)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.verify_token_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="channel?.verify_token"></code>
                    <button type="button" @click="copy(channel.verify_token)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>

            <button type="button" @click="disconnect()" :disabled="disconnecting"
                class="rounded-full border border-danger/30 px-4 py-2 text-sm font-semibold text-danger transition-all hover:-translate-y-0.5 hover:bg-danger/5 disabled:opacity-60 dark:text-danger-light">
                <span x-show="!disconnecting">{{ __('agents.channels.shared.disconnect') }}</span>
                <span x-show="disconnecting" class="spinner"></span>
            </button>
        </div>

        <div x-show="channel?.status !== 'connected'">
            <form @submit.prevent="connect()" class="mt-4 space-y-3">
                <div>
                    <label for="wa_phone_number_id" class="text-sm font-medium">{{ __('agents.channels.whatsapp.phone_id_label') }}</label>
                    <input id="wa_phone_number_id" x-model="form.phone_number_id" type="text" required placeholder="123456789012345"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <p x-show="errors.phone_number_id" x-text="errors.phone_number_id?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div>
                    <label for="wa_access_token" class="text-sm font-medium">{{ __('agents.channels.whatsapp.access_token_label') }}</label>
                    <input id="wa_access_token" x-model="form.access_token" type="text" required placeholder="EAAG..."
 class="field mt-1 w-full rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <p x-show="errors.access_token" x-text="errors.access_token?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div>
                    <label for="wa_verify_token" class="text-sm font-medium">{{ __('agents.channels.shared.verify_token_label') }}</label>
                    <input id="wa_verify_token" x-model="form.verify_token" type="text" required
 class="field mt-1 w-full rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.channels.shared.verify_token_help') }}</p>
                    <p x-show="errors.verify_token" x-text="errors.verify_token?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <button type="submit" :disabled="connecting" class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover disabled:opacity-60">
                    <span x-show="!connecting">{{ __('agents.channels.shared.connect') }}</span>
                    <span x-show="connecting" class="spinner"></span>
                </button>
            </form>

            <div class="mt-3">
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.callback_meta_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="fallbackWebhookUrl"></code>
                    <button type="button" @click="copy(fallbackWebhookUrl)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>

            <button type="button" @click="showInstructions = !showInstructions" class="mt-3 flex items-center gap-1 text-xs font-medium text-muted hover:text-brand dark:text-night-muted">
                <svg class="h-3 w-3 transition-transform" :class="showInstructions ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('agents.channels.whatsapp.instructions_toggle') }}
            </button>
            <ol x-show="showInstructions" x-cloak x-transition.opacity.duration.200ms class="mt-2 list-decimal space-y-1 pl-5 text-xs text-muted dark:text-night-muted">
                @foreach (__('agents.channels.whatsapp.steps') as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- Instagram card --}}
    <div class="rounded-2xl border border-black/10 bg-white p-5 transition-shadow hover:shadow-md dark:border-white/10 dark:bg-white/5"
        x-data="metaChannel({
            connectUrl: '{{ route('agents.channels.instagram.connect', $agent) }}',
            disconnectUrlBase: '{{ route('agents.channels.instagram.disconnect', ['agent' => $agent, 'channel' => '__ID__']) }}',
            fields: ['ig_business_account_id', 'page_access_token', 'verify_token'],
            fallbackWebhookUrl: '{{ route('webhooks.instagram', ['channel' => $instagram?->uuid ?? '__UUID__']) }}',
            initial: {{ Js::from($instagram ? [
                'id' => $instagram->id,
                'status' => $instagram->status,
                'ig_username' => $instagram->settings['ig_username'] ?? null,
                'ig_name' => $instagram->settings['ig_name'] ?? null,
                'webhook_url' => $instagram->settings['webhook_url'] ?? route('webhooks.instagram', $instagram),
                'verify_token' => $instagram->credentials['verify_token'] ?? null,
            ] : null) }},
        })">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-[#FEDA75] via-[#D62976] to-[#4F5BD5] text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2c2.717 0 3.056.01 4.122.06 1.065.05 1.79.217 2.428.465a4.9 4.9 0 011.772 1.153 4.9 4.9 0 011.153 1.772c.248.637.415 1.363.465 2.428.05 1.066.06 1.405.06 4.122 0 2.717-.01 3.056-.06 4.122-.05 1.065-.217 1.79-.465 2.428a4.9 4.9 0 01-1.153 1.772 4.9 4.9 0 01-1.772 1.153c-.637.248-1.363.415-2.428.465-1.066.05-1.405.06-4.122.06-2.717 0-3.056-.01-4.122-.06-1.065-.05-1.79-.217-2.428-.465a4.9 4.9 0 01-1.772-1.153 4.9 4.9 0 01-1.153-1.772c-.248-.637-.415-1.363-.465-2.428C2.01 15.056 2 14.717 2 12c0-2.717.01-3.056.06-4.122.05-1.065.217-1.79.465-2.428a4.9 4.9 0 011.153-1.772A4.9 4.9 0 015.45 2.525c.637-.248 1.363-.415 2.428-.465C8.944 2.01 9.283 2 12 2zm0 1.802c-2.67 0-2.986.01-4.04.059-.976.045-1.505.207-1.858.344-.466.181-.8.399-1.15.748-.35.35-.567.684-.748 1.15-.137.353-.3.882-.344 1.857-.05 1.055-.06 1.37-.06 4.04 0 2.67.01 2.986.06 4.04.045.976.207 1.505.344 1.858.181.466.399.8.748 1.15.35.35.684.567 1.15.748.353.137.882.3 1.857.344 1.054.05 1.37.06 4.041.06 2.67 0 2.987-.01 4.04-.06.976-.045 1.505-.207 1.858-.344.466-.181.8-.398 1.15-.748.35-.35.567-.684.748-1.15.137-.353.3-.882.344-1.857.05-1.055.06-1.37.06-4.041 0-2.67-.01-2.986-.06-4.04-.045-.976-.207-1.505-.344-1.858a3.1 3.1 0 00-.748-1.15 3.1 3.1 0 00-1.15-.748c-.353-.137-.882-.3-1.857-.344-1.055-.05-1.37-.06-4.041-.06zm0 3.063a5.135 5.135 0 110 10.27 5.135 5.135 0 010-10.27zm0 1.802a3.333 3.333 0 100 6.666 3.333 3.333 0 000-6.666zm5.338-1.6a1.2 1.2 0 11-2.4 0 1.2 1.2 0 012.4 0z"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold">{{ __('agents.channels.instagram.name') }}</h3>
                    <p class="text-xs text-muted dark:text-night-muted">
                        <template x-if="channel?.status === 'connected'">
                            <span>{{ __('agents.channels.instagram.connected') }}<template x-if="channel.ig_username"> · @<span x-text="channel.ig_username"></span></template></span>
                        </template>
                        <template x-if="channel?.status === 'error'">
                            <span>{{ __('agents.channels.instagram.error') }}</span>
                        </template>
                        <template x-if="!channel || channel.status === 'disconnected'">
                            <span>{{ __('agents.channels.instagram.disconnected') }}</span>
                        </template>
                    </p>
                </div>
            </div>
            <span class="h-2.5 w-2.5 shrink-0 rounded-full"
                :class="channel?.status === 'connected' ? 'bg-success' : (channel?.status === 'error' ? 'bg-danger' : 'bg-black/20 dark:bg-white/20')"></span>
        </div>

        <div x-show="channel?.status === 'connected'" class="mt-4 space-y-3">
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.callback_url_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="channel?.webhook_url"></code>
                    <button type="button" @click="copy(channel.webhook_url)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>
            <div>
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.verify_token_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="channel?.verify_token"></code>
                    <button type="button" @click="copy(channel.verify_token)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>

            <button type="button" @click="disconnect()" :disabled="disconnecting"
                class="rounded-full border border-danger/30 px-4 py-2 text-sm font-semibold text-danger transition-all hover:-translate-y-0.5 hover:bg-danger/5 disabled:opacity-60 dark:text-danger-light">
                <span x-show="!disconnecting">{{ __('agents.channels.shared.disconnect') }}</span>
                <span x-show="disconnecting" class="spinner"></span>
            </button>
        </div>

        <div x-show="channel?.status !== 'connected'">
            <form @submit.prevent="connect()" class="mt-4 space-y-3">
                <div>
                    <label for="ig_business_account_id" class="text-sm font-medium">{{ __('agents.channels.instagram.business_id_label') }}</label>
                    <input id="ig_business_account_id" x-model="form.ig_business_account_id" type="text" required placeholder="17841400000000000"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <p x-show="errors.ig_business_account_id" x-text="errors.ig_business_account_id?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div>
                    <label for="ig_page_access_token" class="text-sm font-medium">{{ __('agents.channels.instagram.access_token_label') }}</label>
                    <input id="ig_page_access_token" x-model="form.page_access_token" type="text" required placeholder="{{ __('agents.channels.instagram.access_token_placeholder') }}"
 class="field mt-1 w-full rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <p x-show="errors.page_access_token" x-text="errors.page_access_token?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <div>
                    <label for="ig_verify_token" class="text-sm font-medium">{{ __('agents.channels.shared.verify_token_label') }}</label>
                    <input id="ig_verify_token" x-model="form.verify_token" type="text" required
 class="field mt-1 w-full rounded-xl px-4 py-2.5 font-mono text-sm" />
                    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('agents.channels.shared.verify_token_help') }}</p>
                    <p x-show="errors.verify_token" x-text="errors.verify_token?.[0]" class="mt-1 text-xs text-danger"></p>
                </div>
                <button type="submit" :disabled="connecting" class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-on-accent transition-all hover:-translate-y-0.5 hover:bg-brand-hover disabled:opacity-60">
                    <span x-show="!connecting">{{ __('agents.channels.shared.connect') }}</span>
                    <span x-show="connecting" class="spinner"></span>
                </button>
            </form>

            <div class="mt-3">
                <label class="text-xs font-medium uppercase tracking-wide text-muted dark:text-night-muted">{{ __('agents.channels.shared.callback_meta_label') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <code class="flex-1 truncate rounded-lg bg-black/5 px-3 py-2 text-xs dark:bg-white/10" x-text="fallbackWebhookUrl"></code>
                    <button type="button" @click="copy(fallbackWebhookUrl)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-black/10 text-muted transition-colors hover:bg-black/5 dark:border-white/10 dark:text-night-muted dark:hover:bg-white/10">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
            </div>

            <button type="button" @click="showInstructions = !showInstructions" class="mt-3 flex items-center gap-1 text-xs font-medium text-muted hover:text-brand dark:text-night-muted">
                <svg class="h-3 w-3 transition-transform" :class="showInstructions ? 'rotate-90' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                {{ __('agents.channels.instagram.instructions_toggle') }}
            </button>
            <ol x-show="showInstructions" x-cloak x-transition.opacity.duration.200ms class="mt-2 list-decimal space-y-1 pl-5 text-xs text-muted dark:text-night-muted">
                @foreach (__('agents.channels.instagram.steps') as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
    function telegramChannel({ connectUrl, disconnectUrlBase, initial }) {
        return {
            channel: initial,
            botToken: '',
            errors: {},
            connecting: false,
            disconnecting: false,
            showInstructions: !initial || initial.status !== 'connected',
            csrfHeaders(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },
            async connect() {
                this.connecting = true;
                this.errors = {};
                try {
                    const res = await fetch(connectUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify({ bot_token: this.botToken }),
                    });
                    if (res.status === 422) {
                        const data = await res.json();
                        this.errors = data.errors || {};
                        return;
                    }
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.channels.toasts.connect_bot_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.channel = data.channel;
                    this.botToken = '';
                    window.showToast?.(@json(__('agents.channels.toasts.bot_connected')).replace(':username', data.channel.bot_username), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.channels.toasts.network_error')), 'error');
                } finally {
                    this.connecting = false;
                }
            },
            async disconnect() {
                if (!confirm(@json(__('agents.channels.toasts.disconnect_confirm_telegram')))) return;
                this.disconnecting = true;
                try {
                    const res = await fetch(disconnectUrlBase.replace('__ID__', this.channel.id), {
                        method: 'DELETE',
                        headers: this.csrfHeaders(),
                    });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.channels.toasts.bot_disconnect_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.channel = data.channel;
                    this.showInstructions = true;
                    window.showToast?.(@json(__('agents.channels.toasts.telegram_disconnected')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.channels.toasts.network_error')), 'error');
                } finally {
                    this.disconnecting = false;
                }
            },
        };
    }

    function randomToken(length = 32) {
        const bytes = new Uint8Array(length);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, (b) => b.toString(36).padStart(2, '0')).join('').slice(0, length);
    }

    // Shared by the WhatsApp and Instagram cards — both are Meta platforms with
    // an identical connect/disconnect/copy-webhook-details flow, differing only
    // in which credential fields they collect.
    function metaChannel({ connectUrl, disconnectUrlBase, fields, fallbackWebhookUrl, initial }) {
        return {
            channel: initial,
            form: Object.fromEntries(fields.map((f) => [f, f === 'verify_token' ? randomToken() : ''])),
            errors: {},
            connecting: false,
            disconnecting: false,
            showInstructions: !initial || initial.status !== 'connected',
            fallbackWebhookUrl,
            csrfHeaders(extra = {}) {
                return {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    ...extra,
                };
            },
            copy(text) {
                if (!text) return;
                navigator.clipboard.writeText(text);
                window.showToast?.(@json(__('agents.channels.shared.copied')), 'success');
            },
            async connect() {
                this.connecting = true;
                this.errors = {};
                try {
                    const res = await fetch(connectUrl, {
                        method: 'POST',
                        headers: this.csrfHeaders({ 'Content-Type': 'application/json' }),
                        body: JSON.stringify(this.form),
                    });
                    if (res.status === 422) {
                        const data = await res.json();
                        this.errors = data.errors || {};
                        return;
                    }
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.channels.toasts.connect_channel_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.channel = data.channel;
                    window.showToast?.(@json(__('agents.channels.toasts.channel_connected')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.channels.toasts.network_error')), 'error');
                } finally {
                    this.connecting = false;
                }
            },
            async disconnect() {
                if (!confirm(@json(__('agents.channels.toasts.disconnect_confirm_channel')))) return;
                this.disconnecting = true;
                try {
                    const res = await fetch(disconnectUrlBase.replace('__ID__', this.channel.id), {
                        method: 'DELETE',
                        headers: this.csrfHeaders(),
                    });
                    if (!res.ok) {
                        window.showToast?.(@json(__('agents.channels.toasts.channel_disconnect_failed')), 'error');
                        return;
                    }
                    const data = await res.json();
                    this.channel = data.channel;
                    this.showInstructions = true;
                    this.form.verify_token = randomToken();
                    window.showToast?.(@json(__('agents.channels.toasts.channel_disconnected')), 'success');
                } catch (e) {
                    window.showToast?.(@json(__('agents.channels.toasts.network_error')), 'error');
                } finally {
                    this.disconnecting = false;
                }
            },
        };
    }
</script>
@endpush
@endonce
