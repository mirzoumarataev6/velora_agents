@php
    $seoTitle = __('auth.login.title');
    $seoDescription = __('auth.login.subtitle');

    $__loginTermsLink = '<a href="'.route('legal.terms', ['locale' => app()->getLocale()]).'" target="_blank" rel="noopener" class="font-medium text-brand-strong hover:underline">'.e(__('legal.nav.terms_link')).'</a>';
    $__loginPrivacyLink = '<a href="'.route('legal.privacy', ['locale' => app()->getLocale()]).'" target="_blank" rel="noopener" class="font-medium text-brand-strong hover:underline">'.e(__('legal.nav.privacy_link')).'</a>';
    $__loginLegalNotice = str_replace([':terms', ':privacy'], [$__loginTermsLink, $__loginPrivacyLink], e(__('auth.login.legal_notice')));
@endphp
@extends('layouts.auth')

@section('card')
    <div class="auth-intro">
        <span class="auth-kicker"><span class="auth-kicker-dot" aria-hidden="true"></span>{{ __('common.brand') }}</span>
        <h1 class="mt-4 font-display text-3xl font-semibold tracking-tight sm:text-4xl" data-i18n="auth.login.title">{{ __('auth.login.title') }}</h1>
        <p class="mt-2 max-w-sm text-sm leading-6 text-muted dark:text-night-muted" data-i18n="auth.login.subtitle">{{ __('auth.login.subtitle') }}</p>
    </div>

    <form id="loginForm" method="POST" action="/login" class="mt-7 space-y-4" novalidate aria-label="{{ __('auth.login.title') }}">
        <div>
            <label for="email" class="text-sm font-medium" data-i18n="auth.login.email_label">{{ __('auth.login.email_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <input id="email" name="email" type="email" required autofocus autocomplete="email"
                    aria-required="true" aria-describedby="email-error"
 class="field peer w-full px-4 py-2.5 pl-11 text-sm" />
            </div>
            <p id="email-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <div>
            <label for="password" class="text-sm font-medium" data-i18n="auth.login.password_label">{{ __('auth.login.password_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted dark:text-night-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    aria-required="true" aria-describedby="password-error"
 class="field peer w-full px-4 py-2.5 pl-11 pr-11 text-sm" />
                <button type="button" data-toggle-password="password" aria-label="{{ __('auth.login.show_password') }}" aria-pressed="false"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted transition-colors hover:text-brand-strong dark:text-night-muted dark:hover:text-brand-light">
                    <svg class="icon-eye h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg class="icon-eye-off hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>
            <p id="password-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <div class="flex items-center justify-between">
            {{-- value="1" makes the posted value explicit rather than the browser default "on". --}}
            <label class="flex items-center gap-2 text-sm text-muted dark:text-night-muted">
                <input type="checkbox" name="remember" id="remember" value="1" class="field-check h-4 w-4" />
                {{ __('auth.login.remember') }}
            </label>
            <a href="{{ route('password.request') }}" data-transition class="text-sm font-medium text-brand-strong hover:underline" data-i18n="auth.login.forgot">{{ __('auth.login.forgot') }}</a>
        </div>

        <button type="submit"
            class="btn btn-primary auth-submit btn-block relative disabled:pointer-events-none disabled:opacity-70">
            <span class="btn-label" data-i18n="auth.login.submit">{{ __('auth.login.submit') }}</span>
            <svg class="pointer-events-none absolute right-4 h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </button>
    </form>

    <p class="mt-7 border-t border-hairline pt-5 text-center text-sm text-muted dark:border-night-raised dark:text-night-muted">
        <span data-i18n="auth.login.no_account">{{ __('auth.login.no_account') }}</span>
        <a href="{{ route('register') }}" data-transition class="font-semibold text-brand-strong hover:underline" data-i18n="auth.login.register_link">{{ __('auth.login.register_link') }}</a>
    </p>

    <p class="mt-3 text-center text-xs leading-relaxed text-muted dark:text-night-muted">{!! $__loginLegalNotice !!}</p>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const emailInput = document.getElementById('email');
        emailInput?.addEventListener('blur', () => {
            if (emailInput.value && !isValidEmail(emailInput.value)) {
                markInvalid(emailInput, @json(__('auth.validation.email_invalid')));
            }
        });
        emailInput?.addEventListener('input', () => {
            if (isValidEmail(emailInput.value)) clearInvalid(emailInput);
        });

        const passwordInput = document.getElementById('password');
        passwordInput?.addEventListener('blur', () => {
            if (passwordInput.value && passwordInput.value.length < 8) {
                markInvalid(passwordInput, @json(__('auth.validation.password_min')));
            }
        });
        passwordInput?.addEventListener('input', () => {
            if (passwordInput.value.length >= 8) clearInvalid(passwordInput);
        });

        const loginForm = document.getElementById('loginForm');
        loginForm?.addEventListener('submit', (event) => handleAuthSubmit(event, {
            form: loginForm,
            validator: validateLogin,
        }));
    });

    function validateLogin() {
        const form = document.getElementById('loginForm');
        const email = form.querySelector('#email');
        const password = form.querySelector('#password');
        [email, password].forEach(clearInvalid);

        let firstError = null;
        if (!isValidEmail(email.value)) {
            markInvalid(email, @json(__('auth.validation.email_invalid')));
            firstError = firstError || @json(__('auth.validation.email_invalid'));
        }
        if (password.value.length < 8) {
            markInvalid(password, @json(__('auth.validation.password_min')));
            firstError = firstError || @json(__('auth.validation.password_min'));
        }
        return firstError;
    }
</script>
@endpush
