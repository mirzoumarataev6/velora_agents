@php
    $seoTitle = __('auth.login.title');
    $seoDescription = __('auth.login.subtitle');
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="auth.login.title">{{ __('auth.login.title') }}</h1>
    <p class="mt-1 text-center text-sm text-muted dark:text-night-muted" data-i18n="auth.login.subtitle">{{ __('auth.login.subtitle') }}</p>

    <a id="google-auth-btn" href="{{ route('auth.google') }}"
        class="btn btn-neutral btn-block btn-lg mt-6 gap-3">
        <svg viewBox="0 0 48 48" class="h-5 w-5" aria-hidden="true">
            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.9 32.5 29.4 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20c11 0 19.7-8 19.7-20 0-1.3-.1-2.3-.3-3.5z"/>
            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.1 18.9 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
            <path fill="#4CAF50" d="M24 44c5.3 0 10.1-2 13.7-5.4l-6.3-5.3C29.4 35 26.8 36 24 36c-5.4 0-9.9-3.5-11.3-8.3l-6.6 5.1C9.6 39.6 16.2 44 24 44z"/>
            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.4-2.3 4.4-4.3 5.8l6.3 5.3C39.9 37.1 44 31.9 44 24c0-1.3-.1-2.3-.3-3.5z"/>
        </svg>
        <span data-i18n="auth.login.google_cta">{{ __('auth.login.google_cta') }}</span>
    </a>

    <div class="my-4 flex items-center gap-3 text-xs font-medium uppercase tracking-widest text-muted dark:text-night-muted/70">
        <span class="h-px flex-1 bg-hairline dark:bg-night-raised"></span>
        <span data-i18n="auth.login.divider">{{ __('auth.login.divider') }}</span>
        <span class="h-px flex-1 bg-hairline dark:bg-night-raised"></span>
    </div>

    <form id="loginForm" method="POST" action="/login" class="space-y-3" novalidate aria-label="{{ __('auth.login.title') }}">
        <div>
            <label for="email" class="text-sm font-medium" data-i18n="auth.login.email_label">{{ __('auth.login.email_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <input id="email" name="email" type="email" required autofocus autocomplete="email"
                    aria-required="true" aria-describedby="email-error"
 class="field peer w-full px-4 py-2.5 pl-11 text-sm" />
            </div>
            <p id="email-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <div>
            <label for="password" class="text-sm font-medium" data-i18n="auth.login.password_label">{{ __('auth.login.password_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    aria-required="true" aria-describedby="password-error"
 class="field peer w-full px-4 py-2.5 pl-11 pr-11 text-sm" />
                <button type="button" data-toggle-password="password" aria-label="{{ __('auth.login.show_password') }}" aria-pressed="false"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted transition-colors hover:text-brand-strong">
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
            class="btn btn-primary btn-block disabled:pointer-events-none disabled:opacity-70">
            <span class="btn-label" data-i18n="auth.login.submit">{{ __('auth.login.submit') }}</span>
        </button>
    </form>

    <p class="mt-4 text-center text-sm text-muted dark:text-night-muted">
        <span data-i18n="auth.login.no_account">{{ __('auth.login.no_account') }}</span>
        <a href="{{ route('register') }}" data-transition class="font-semibold text-brand-strong hover:underline" data-i18n="auth.login.register_link">{{ __('auth.login.register_link') }}</a>
    </p>
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
