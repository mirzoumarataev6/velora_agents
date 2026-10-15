@extends('layouts.dashboard')

@section('title', __('settings.index.title'))

@section('content')
{{--
    Account settings.

    Four independent forms on one page, each with its own named error bag, so a
    wrong password never lights up the profile inputs. Every form is a plain
    POST: they keep working without JS, and validation errors come back inline
    where the field is. Alpine only handles what is genuinely interactive —
    which section is showing, and the delete confirmation modal.
--}}
@php
    // Come back to the section the error happened in, rather than dropping the
    // owner on Profile wondering why nothing saved.
    $initialTab = match (true) {
        $errors->getBag('password')->any(), $errors->getBag('sessions')->any() => 'security',
        $errors->getBag('preferences')->any() => 'preferences',
        $errors->getBag('team')->any() => 'team',
        $errors->getBag('account')->any() => 'account',
        default => session('settings_tab', 'profile'),
    };
    $tabs = ['profile', 'security', 'preferences', 'team', 'account'];
@endphp

{{-- Narrower than the 1600px shell on purpose: this page is a column of
     forms, and a form field 1500px wide is unusable. --}}
<div class="mx-auto max-w-5xl" x-data="settingsPage({
        initialTab: {{ Js::from($initialTab) }},
        deleteOpen: {{ Js::from($errors->getBag('account')->any()) }},
        flash: {{ Js::from(session('status')) }},
    {{-- Alpine calls this object's own init() itself; see the note in
         conversations/partials/inbox.blade.php. Spelling it out in x-init
         ran init() twice against a single destroy() — here that meant
         a duplicate success toast and a double scrollIntoView on every visit. --}}
    })">

    <div class="page-header">
        <div>
            <h1 class="page-title" data-i18n="settings.index.heading">{{ __('settings.index.heading') }}</h1>
            <p class="page-sub" data-i18n="settings.index.subheading">{{ __('settings.index.subheading') }}</p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,13rem)_minmax(0,1fr)] lg:items-start">

        {{-- Section nav: a sticky rail on desktop, a scrollable pill row on mobile --}}
        <div class="flex min-w-0 items-start gap-1 lg:block">
        <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand lg:hidden"
            @click="$refs.settingsTabs.scrollBy({ left: -180, behavior: 'smooth' })" aria-label="{{ __('agents.show.previous_tabs') }}">‹</button>
        <nav x-ref="settingsTabs" class="-mx-1 flex min-w-0 flex-1 gap-2 overflow-x-auto px-1 pb-1 lg:mx-0 lg:sticky lg:top-6 lg:flex-col lg:overflow-visible lg:px-0"
            aria-label="{{ __('settings.index.heading') }}">
            @foreach ($tabs as $key)
                <button type="button" x-ref="settingsTab_{{ $key }}" @click="go('{{ $key }}')"
                    :aria-current="tab === '{{ $key }}' ? 'page' : 'false'"
                    :class="tab === '{{ $key }}'
                        ? 'bg-brand-circle text-brand-strong'
                        : 'text-muted hover:bg-black/5 dark:text-night-muted dark:hover:bg-white/5'"
                    class="shrink-0 whitespace-nowrap rounded-xl px-4 py-2.5 text-left text-sm font-medium transition-colors">
                    <span data-i18n="settings.tabs.{{ $key }}">{{ __("settings.tabs.$key") }}</span>
                </button>
            @endforeach
        </nav>
        <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand lg:hidden"
            @click="$refs.settingsTabs.scrollBy({ left: 180, behavior: 'smooth' })" aria-label="{{ __('agents.show.next_tabs') }}">›</button>
        </div>

        <div class="min-w-0 space-y-6">

            {{-- ══════════════ PROFILE ══════════════ --}}
            <section x-show="tab === 'profile'" x-cloak class="space-y-6">
                <div class="card p-5 sm:p-6">
                    <h2 class="font-display text-lg font-semibold" data-i18n="settings.profile.heading">{{ __('settings.profile.heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.profile.description">{{ __('settings.profile.description') }}</p>

                    <div class="mt-5 flex items-center gap-4">
                        <div class="icon-circle icon-circle-lg shrink-0 text-xl font-semibold">
                            {{ $user->initials() }}
                        </div>
                        <p class="text-sm text-muted dark:text-night-muted" data-i18n="settings.profile.avatar_hint">{{ __('settings.profile.avatar_hint') }}</p>
                    </div>

                    <form method="POST" action="{{ route('settings.profile') }}" class="mt-6 space-y-4">
                        @csrf
                        @method('PATCH')

                        <div>
                            <label for="settings-name" class="block text-sm font-medium" data-i18n="settings.profile.name">{{ __('settings.profile.name') }}</label>
                            <input id="settings-name" name="name" type="text" required autocomplete="name"
                                value="{{ old('name', $user->name) }}"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                            @error('name', 'profile')
                                <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="settings-email" class="block text-sm font-medium" data-i18n="settings.profile.email">{{ __('settings.profile.email') }}</label>
                            <input id="settings-email" name="email" type="email" required autocomplete="email"
                                value="{{ old('email', $user->email) }}"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                            @error('email', 'profile')
                                <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                            @enderror
                            <p class="mt-1.5 text-xs text-muted dark:text-night-muted" data-i18n="settings.profile.email_hint">{{ __('settings.profile.email_hint') }}</p>
                        </div>

                        <div class="flex items-center gap-3 pt-1">
                            <button type="submit"
                                class="btn btn-primary">
                                <span data-i18n="settings.actions.save">{{ __('settings.actions.save') }}</span>
                            </button>
                            @if ($user->email_verified_at === null)
                                <span class="badge badge-warning" data-i18n="settings.profile.unverified">{{ __('settings.profile.unverified') }}</span>
                            @endif
                        </div>
                    </form>
                </div>
            </section>

            {{-- ══════════════ SECURITY ══════════════ --}}
            <section x-show="tab === 'security'" x-cloak class="space-y-6">
                <div class="card p-5 sm:p-6">
                    <h2 class="font-display text-lg font-semibold" data-i18n="settings.security.heading">{{ __('settings.security.heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.security.description">{{ __('settings.security.description') }}</p>

                    @if ($user->google_id)
                        <p class="mt-4 rounded-xl bg-brand-soft/60 px-4 py-3 text-sm text-ink dark:bg-night-raised dark:text-night-ink" data-i18n="settings.security.google_hint">{{ __('settings.security.google_hint') }}</p>
                    @endif

                    <form method="POST" action="{{ route('settings.password') }}" class="mt-5 space-y-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <label for="current-password" class="block text-sm font-medium" data-i18n="settings.security.current_password">{{ __('settings.security.current_password') }}</label>
                            <input id="current-password" name="current_password" type="password" required autocomplete="current-password"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                            @error('current_password', 'password')
                                <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="new-password" class="block text-sm font-medium" data-i18n="settings.security.new_password">{{ __('settings.security.new_password') }}</label>
                            <input id="new-password" name="password" type="password" required autocomplete="new-password"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                            @error('password', 'password')
                                <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="new-password-confirm" class="block text-sm font-medium" data-i18n="settings.security.confirm_password">{{ __('settings.security.confirm_password') }}</label>
                            <input id="new-password-confirm" name="password_confirmation" type="password" required autocomplete="new-password"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                        </div>

                        <button type="submit"
                            class="btn btn-primary">
                            <span data-i18n="settings.security.change_password">{{ __('settings.security.change_password') }}</span>
                        </button>
                    </form>
                </div>

                {{-- Active sessions --}}
                <div class="card p-5 sm:p-6">
                    <h2 class="font-display text-lg font-semibold" data-i18n="settings.security.sessions_heading">{{ __('settings.security.sessions_heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.security.sessions_description">{{ __('settings.security.sessions_description') }}</p>

                    @if ($sessionsSupported && count($sessions) > 0)
                        <ul class="mt-4 divide-y divide-black/5 dark:divide-white/10">
                            @foreach ($sessions as $session)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium">
                                            {{ $session['device'] }}
                                            @if ($session['current'])
                                                <span class="badge badge-success ml-2" data-i18n="settings.security.this_device">{{ __('settings.security.this_device') }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-muted dark:text-night-muted">
                                            {{ $session['ip'] }} · {{ \Illuminate\Support\Carbon::createFromTimestamp($session['last_active'])->diffForHumans() }}
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (! $sessionsSupported)
                        <p class="mt-4 rounded-xl bg-sunk px-4 py-3 text-sm text-muted dark:bg-night-raised dark:text-night-muted" data-i18n="settings.security.sessions_unavailable">{{ __('settings.security.sessions_unavailable') }}</p>
                    @endif

                    <form method="POST" action="{{ route('settings.sessions') }}" class="mt-5 space-y-4 border-t border-hairline pt-5 dark:border-white/10">
                        @csrf
                        @method('DELETE')

                        <div class="max-w-sm">
                            <label for="sessions-password" class="block text-sm font-medium" data-i18n="settings.security.confirm_with_password">{{ __('settings.security.confirm_with_password') }}</label>
                            <input id="sessions-password" name="password" type="password" required autocomplete="current-password"
                                class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                            @error('password', 'sessions')
                                <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit"
                            class="rounded-xl px-5 py-2.5 text-sm font-semibold transition-colors hover:bg-sunk dark:hover:bg-night-raised">
                            <span data-i18n="settings.security.logout_others">{{ __('settings.security.logout_others') }}</span>
                        </button>
                    </form>
                </div>
            </section>

            {{-- ══════════════ PREFERENCES ══════════════ --}}
            <section x-show="tab === 'preferences'" x-cloak class="space-y-6">
                <form method="POST" action="{{ route('settings.preferences') }}" @submit="cacheTheme()"
                    class="card p-5 sm:p-6">
                    @csrf
                    @method('PATCH')

                    <h2 class="font-display text-lg font-semibold" data-i18n="settings.preferences.heading">{{ __('settings.preferences.heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.preferences.description">{{ __('settings.preferences.description') }}</p>

                    {{-- Language --}}
                    <fieldset class="mt-5">
                        <legend class="text-sm font-medium" data-i18n="settings.preferences.language">{{ __('settings.preferences.language') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (\App\Http\Middleware\SetLocale::SUPPORTED_LOCALES as $loc)
                                <label class="cursor-pointer">
                                    <input type="radio" name="locale" value="{{ $loc }}" class="peer sr-only"
                                        @checked(old('locale', $user->locale ?? app()->getLocale()) === $loc) />
                                    <span class="block rounded-xl border border-transparent px-4 py-2 text-sm font-medium transition-colors peer-checked:border-brand peer-checked:bg-brand/10 peer-checked:text-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand">
                                        {{ __("settings.locales.$loc") }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('locale', 'preferences')
                            <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    {{-- Theme --}}
                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium" data-i18n="settings.preferences.theme">{{ __('settings.preferences.theme') }}</legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (['system', 'light', 'dark'] as $themeOption)
                                <label class="cursor-pointer">
                                    <input type="radio" name="theme" value="{{ $themeOption }}" class="peer sr-only"
                                        @checked(old('theme', $user->theme ?? 'system') === $themeOption)
                                        @change="previewTheme('{{ $themeOption }}')" />
                                    <span class="block rounded-xl border border-transparent px-4 py-2 text-sm font-medium transition-colors peer-checked:border-brand peer-checked:bg-brand/10 peer-checked:text-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand">
                                        <span data-i18n="settings.themes.{{ $themeOption }}">{{ __("settings.themes.$themeOption") }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('theme', 'preferences')
                            <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    {{-- Notifications --}}
                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium" data-i18n="settings.preferences.notifications">{{ __('settings.preferences.notifications') }}</legend>
                        <label class="mt-2 flex cursor-pointer items-start gap-3 rounded-xl p-4 transition-colors hover:bg-black/[0.02] dark:hover:bg-night-raised">
                            <input type="checkbox" name="lead_notifications_enabled" value="1" class="mt-0.5 h-4 w-4 shrink-0 rounded text-brand-strong focus:ring-brand"
                                @checked(old('lead_notifications_enabled', $user->lead_notifications_enabled)) />
                            <span>
                                <span class="block text-sm font-medium" data-i18n="settings.preferences.lead_alerts">{{ __('settings.preferences.lead_alerts') }}</span>
                                <span class="mt-0.5 block text-sm text-muted dark:text-night-muted" data-i18n="settings.preferences.lead_alerts_hint">{{ __('settings.preferences.lead_alerts_hint') }}</span>
                            </span>
                        </label>
                    </fieldset>

                    <button type="submit"
                        class="btn btn-primary mt-6">
                        <span data-i18n="settings.actions.save">{{ __('settings.actions.save') }}</span>
                    </button>
                </form>

                {{-- Telegram lead alerts, one card per agent — the link is made
                     through that agent's own bot, so it cannot be account-wide. --}}
                <div>
                    <h2 class="font-display text-lg font-semibold" data-i18n="settings.preferences.telegram_heading">{{ __('settings.preferences.telegram_heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.preferences.telegram_description">{{ __('settings.preferences.telegram_description') }}</p>

                    @if ($agents->isEmpty())
                        <p class="mt-4 rounded-2xl px-4 py-6 text-center text-sm text-muted dark:text-night-muted" data-i18n="settings.preferences.telegram_no_agents">{{ __('settings.preferences.telegram_no_agents') }}</p>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($agents as $notifyAgent)
                                @include('partials.lead-notify-card', ['agent' => $notifyAgent, 'showAgentName' => true])
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            {{-- Team work lives on its own page so account preferences stay about this person. --}}
            <section x-show="tab === 'team'" x-cloak class="space-y-6">
                <div class="card p-5 sm:p-6">
                    <h2 class="font-display text-lg font-semibold">{{ __('settings.team.heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted">{{ __('settings.team.description') }}</p>
                    <a href="{{ route('teams.index') }}" class="btn btn-primary mt-5">{{ __('settings.team.open_section') }}</a>
                </div>
            </section>

            {{-- ══════════════ ACCOUNT ══════════════ --}}
            <section x-show="tab === 'account'" x-cloak class="space-y-6">
                <div class="card p-5 sm:p-6">
                    <h2 class="font-display text-lg font-semibold" data-i18n="settings.account.heading">{{ __('settings.account.heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.account.description">{{ __('settings.account.description') }}</p>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3">
                        <div class="flex items-center gap-3">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.6 12.23c0-.68-.06-1.34-.18-1.96H12v3.71h5.38a4.6 4.6 0 01-2 3.02v2.5h3.23c1.89-1.74 2.99-4.3 2.99-7.27z"/><path fill="#34A853" d="M12 22c2.7 0 4.96-.9 6.61-2.42l-3.23-2.5c-.9.6-2.04.95-3.38.95-2.6 0-4.8-1.75-5.59-4.1H3.08v2.58A10 10 0 0012 22z"/><path fill="#FBBC05" d="M6.41 13.93a6 6 0 010-3.86V7.49H3.08a10 10 0 000 9.02l3.33-2.58z"/><path fill="#EA4335" d="M12 5.98c1.47 0 2.79.51 3.83 1.5l2.87-2.87C16.95 2.99 14.7 2 12 2a10 10 0 00-8.92 5.49l3.33 2.58C7.2 7.73 9.4 5.98 12 5.98z"/></svg>
                            <div>
                                <p class="text-sm font-medium" data-i18n="settings.account.google">{{ __('settings.account.google') }}</p>
                                <p class="text-xs text-muted dark:text-night-muted">{{ $user->email }}</p>
                            </div>
                        </div>
                        @if ($user->google_id)
                            <span class="badge badge-success" data-i18n="settings.account.google_connected">{{ __('settings.account.google_connected') }}</span>
                        @else
                            <span class="badge badge-neutral" data-i18n="settings.account.google_not_connected">{{ __('settings.account.google_not_connected') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Danger zone --}}
                <div class="rounded-2xl border-danger/20 bg-danger/[0.03] p-5 sm:p-6">
                    <h2 class="font-display text-lg font-semibold text-danger dark:text-danger-light" data-i18n="settings.account.delete_heading">{{ __('settings.account.delete_heading') }}</h2>
                    <p class="mt-1 text-sm text-muted dark:text-night-muted" data-i18n="settings.account.delete_description">{{ __('settings.account.delete_description') }}</p>

                    <ul class="mt-3 space-y-1 text-sm text-muted dark:text-night-muted">
                        @foreach (['agents', 'knowledge', 'conversations', 'leads'] as $item)
                            <li class="flex items-start gap-2">
                                <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-danger/60"></span>
                                <span data-i18n="settings.account.delete_item_{{ $item }}">{{ __("settings.account.delete_item_$item") }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <button type="button" @click="openDelete()"
                        class="btn btn-danger mt-5">
                        <span data-i18n="settings.account.delete_button">{{ __('settings.account.delete_button') }}</span>
                    </button>
                </div>
            </section>
        </div>
    </div>

    {{-- ══════════════ DELETE CONFIRMATION MODAL ══════════════ --}}
    <div x-show="deleteOpen" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
        role="dialog" aria-modal="true" aria-labelledby="delete-modal-title"
        @keydown.escape.window="closeDelete()">

        <div x-show="deleteOpen" x-transition.opacity @click="closeDelete()" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

        <div x-show="deleteOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            class="card relative w-full max-w-md p-6">

            <h3 id="delete-modal-title" class="font-display text-lg font-semibold text-danger dark:text-danger-light" data-i18n="settings.account.modal_title">{{ __('settings.account.modal_title') }}</h3>
            <p class="mt-2 text-sm text-muted dark:text-night-muted" data-i18n="settings.account.modal_description">{{ __('settings.account.modal_description') }}</p>

            <form method="POST" action="{{ route('settings.account.destroy') }}" class="mt-5">
                @csrf
                @method('DELETE')

                <label for="delete-confirmation" class="block text-sm font-medium">
                    {{ __('settings.account.modal_prompt', ['email' => $user->email]) }}
                </label>
                <input id="delete-confirmation" name="confirmation" type="text" required autocomplete="off"
                    x-ref="confirmInput" x-model="confirmation"
                    placeholder="{{ $user->email }}"
                    class="field mt-1.5 w-full px-4 py-2.5 text-sm" />
                @error('confirmation', 'account')
                    <p class="mt-1.5 text-sm text-danger dark:text-danger-light">{{ $message }}</p>
                @enderror

                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" @click="closeDelete()"
                        class="rounded-xl px-5 py-2.5 text-sm font-semibold transition-colors hover:bg-sunk dark:hover:bg-night-raised">
                        <span data-i18n="settings.actions.cancel">{{ __('settings.actions.cancel') }}</span>
                    </button>
                    <button type="submit" :disabled="!canDelete()"
                        class="btn btn-danger disabled:cursor-not-allowed disabled:opacity-50">
                        <span data-i18n="settings.account.modal_confirm">{{ __('settings.account.modal_confirm') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function settingsPage({ initialTab, deleteOpen, flash }) {
        return {
            tab: initialTab,
            deleteOpen,
            confirmation: '',
            // The email is compared case-insensitively here to match the server,
            // which is the check that actually counts.
            email: {{ Js::from($user->email) }},

            init() {
                // A hash keeps a section linkable and survives a reload.
                const fromHash = window.location.hash.replace('#', '');
                if (fromHash && {{ Js::from($tabs) }}.includes(fromHash) && initialTab === 'profile') {
                    this.tab = fromHash;
                }
                this.$nextTick(() => this.$refs['settingsTab_' + this.tab]?.scrollIntoView({ block: 'nearest', inline: 'center' }));
                if (flash) window.showToast?.(flash, 'success');
                if (this.deleteOpen) this.$nextTick(() => this.$refs.confirmInput?.focus());
            },

            go(tab) {
                this.tab = tab;
                window.history.replaceState({}, '', '#' + tab);
                this.$nextTick(() => this.$refs['settingsTab_' + tab]?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }));
            },

            openDelete() {
                this.deleteOpen = true;
                this.$nextTick(() => this.$refs.confirmInput?.focus());
            },

            closeDelete() {
                this.deleteOpen = false;
                this.confirmation = '';
            },

            canDelete() {
                const typed = this.confirmation.trim();
                return typed.toLowerCase() === this.email.toLowerCase() || typed === 'DELETE';
            },

            // Immediate feedback while choosing, so the swatch you picked is the
            // one you see. The saved value is what the server writes on submit.
            previewTheme(theme) {
                const isDark = theme === 'dark'
                    || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', isDark);
            },

            /**
             * Keep the pre-paint cache in step with what is being saved. The
             * server now stores "system" verbatim rather than folding it into
             * null (see SettingsController::THEMES), so this caches it
             * verbatim too — the layout's boot script treats a cached
             * "system" as "follow the OS" and treats the key's absence as
             * "never chose, default light", and those two have to stay
             * distinguishable here as much as they do server-side.
             */
            cacheTheme() {
                const theme = document.querySelector('input[name="theme"]:checked')?.value;
                if (!theme) return;

                localStorage.setItem('theme', theme);
            },
        };
    }
</script>
@endpush
@endsection
