@php
    $seoTitle = __('auth.reset.title');
    $seoDescription = __('auth.reset.subtitle');
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="auth.reset.title">{{ __('auth.reset.title') }}</h1>
    <p class="mt-1 text-center text-sm text-muted dark:text-night-muted" data-i18n="auth.reset.subtitle">{{ __('auth.reset.subtitle') }}</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-3" aria-label="{{ __('auth.reset.title') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}" />

        <div>
            <label for="email" class="text-sm font-medium" data-i18n="auth.reset.email_label">{{ __('auth.reset.email_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <input id="email" name="email" type="email" required autocomplete="email"
                    value="{{ old('email', $email) }}" aria-required="true"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="field w-full rounded-xl px-4 py-2.5 pl-11 text-sm @error('email') !border-danger @enderror" />
            </div>
            @error('email')
                <p id="email-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="text-sm font-medium" data-i18n="auth.reset.password_label">{{ __('auth.reset.password_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
                    aria-required="true"
                    @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                    class="field w-full rounded-xl px-4 py-2.5 pl-11 pr-11 text-sm @error('password') !border-danger @enderror" />
                <button type="button" data-toggle-password="password" aria-label="{{ __('auth.login.show_password') }}" aria-pressed="false"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted transition-colors hover:text-brand">
                    <svg class="icon-eye h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg class="icon-eye-off hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>
            @error('password')
                <p id="password-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="text-sm font-medium" data-i18n="auth.reset.confirm_label">{{ __('auth.reset.confirm_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    aria-required="true"
 class="field w-full rounded-xl px-4 py-2.5 pl-11 pr-11 text-sm" />
                <button type="button" data-toggle-password="password_confirmation" aria-label="{{ __('auth.login.show_password') }}" aria-pressed="false"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted transition-colors hover:text-brand">
                    <svg class="icon-eye h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg class="icon-eye-off hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>
        </div>

        <button type="submit"
            class="flex w-full items-center justify-center gap-2 rounded-full bg-brand px-4 py-2.5 text-sm font-semibold text-on-accent shadow-glow-ember transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-hover">
            <span class="btn-label" data-i18n="auth.reset.submit">{{ __('auth.reset.submit') }}</span>
        </button>
    </form>
@endsection
