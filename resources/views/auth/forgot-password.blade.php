@php
    $seoTitle = __('auth.forgot.title');
    $seoDescription = __('auth.forgot.subtitle');
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="auth.forgot.title">{{ __('auth.forgot.title') }}</h1>
    <p class="mt-1 text-center text-sm text-muted dark:text-night-muted" data-i18n="auth.forgot.subtitle">{{ __('auth.forgot.subtitle') }}</p>

    {{-- Plain form post rather than the AJAX path used by login/register: this
         flow's whole result is a flashed status message, which a normal redirect
         renders for free. --}}
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-3" aria-label="{{ __('auth.forgot.title') }}">
        @csrf
        <div>
            <label for="email" class="text-sm font-medium" data-i18n="auth.forgot.email_label">{{ __('auth.forgot.email_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <input id="email" name="email" type="email" required autofocus autocomplete="email"
                    value="{{ old('email') }}" aria-required="true"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                    class="field w-full rounded-xl px-4 py-2.5 pl-11 text-sm @error('email') !border-danger @enderror" />
            </div>
            @error('email')
                <p id="email-error" class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
            class="flex w-full items-center justify-center gap-2 rounded-full bg-brand px-4 py-2.5 text-sm font-semibold text-on-accent shadow-glow-ember transition-all duration-200 hover:-translate-y-0.5 hover:bg-brand-hover">
            <span class="btn-label" data-i18n="auth.forgot.submit">{{ __('auth.forgot.submit') }}</span>
        </button>
    </form>

    <p class="mt-4 text-center text-sm text-muted dark:text-night-muted">
        <a href="{{ route('login') }}" data-transition class="font-semibold text-brand hover:underline" data-i18n="auth.forgot.back_to_login">{{ __('auth.forgot.back_to_login') }}</a>
    </p>
@endsection
