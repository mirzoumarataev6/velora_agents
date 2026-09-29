@php
    $seoTitle = __('auth.register.title');
    $seoDescription = __('auth.register.subtitle');
@endphp
@extends('layouts.auth')

@section('card')
    <h1 class="text-center font-display text-2xl font-semibold" data-i18n="auth.register.title">{{ __('auth.register.title') }}</h1>
    <p class="mt-1 text-center text-sm text-muted dark:text-night-muted" data-i18n="auth.register.subtitle">{{ __('auth.register.subtitle') }}</p>

    <a id="google-auth-btn" href="{{ route('auth.google') }}"
        class="btn btn-neutral btn-block btn-lg mt-6 gap-3">
        <svg viewBox="0 0 48 48" class="h-5 w-5" aria-hidden="true">
            <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.9 32.5 29.4 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20c11 0 19.7-8 19.7-20 0-1.3-.1-2.3-.3-3.5z"/>
            <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.1 18.9 12 24 12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.6 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
            <path fill="#4CAF50" d="M24 44c5.3 0 10.1-2 13.7-5.4l-6.3-5.3C29.4 35 26.8 36 24 36c-5.4 0-9.9-3.5-11.3-8.3l-6.6 5.1C9.6 39.6 16.2 44 24 44z"/>
            <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.4-2.3 4.4-4.3 5.8l6.3 5.3C39.9 37.1 44 31.9 44 24c0-1.3-.1-2.3-.3-3.5z"/>
        </svg>
        <span data-i18n="auth.register.google_cta">{{ __('auth.register.google_cta') }}</span>
    </a>

    <div class="my-4 flex items-center gap-3 text-xs font-medium uppercase tracking-widest text-muted dark:text-night-muted/70">
        <span class="h-px flex-1 bg-hairline dark:bg-night-raised"></span>
        <span data-i18n="auth.register.divider">{{ __('auth.register.divider') }}</span>
        <span class="h-px flex-1 bg-hairline dark:bg-night-raised"></span>
    </div>

    <form id="registerForm" method="POST" action="/register" class="space-y-3" novalidate aria-label="{{ __('auth.register.title') }}">
        <div>
            <label for="name" class="text-sm font-medium" data-i18n="auth.register.name_label">{{ __('auth.register.name_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                <input id="name" name="name" type="text" required autofocus autocomplete="name"
                    aria-required="true" aria-describedby="name-error"
 class="field peer w-full px-4 py-2.5 pl-11 text-sm" />
            </div>
            <p id="name-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <div>
            <label for="email" class="text-sm font-medium" data-i18n="auth.register.email_label">{{ __('auth.register.email_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <input id="email" name="email" type="email" required autocomplete="email"
                    aria-required="true" aria-describedby="email-error"
 class="field peer w-full px-4 py-2.5 pl-11 text-sm" />
            </div>
            <p id="email-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <div>
            <label for="password" class="text-sm font-medium" data-i18n="auth.register.password_label">{{ __('auth.register.password_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                <input id="password" name="password" type="password" required autocomplete="new-password"
                    aria-required="true" aria-describedby="password-error strength-label"
 class="field peer w-full px-4 py-2.5 pl-11 pr-11 text-sm" />
                <button type="button" data-toggle-password="password" aria-label="{{ __('auth.register.show_password') }}" aria-pressed="false"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted transition-colors hover:text-brand-strong">
                    <svg class="icon-eye h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg class="icon-eye-off hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>
            <div id="strength-meter" class="mt-2">
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-sunk dark:bg-night-raised">
                    <div id="strength-bar" class="h-full w-0 rounded-full transition-all duration-300"></div>
                </div>
                <p id="strength-label" class="mt-1 text-xs text-muted dark:text-night-muted" aria-live="polite"></p>
            </div>
            <p id="password-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <div>
            <label for="password_confirmation" class="text-sm font-medium" data-i18n="auth.register.confirm_label">{{ __('auth.register.confirm_label') }}</label>
            <div class="relative mt-1">
                <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                    aria-required="true" aria-describedby="password_confirmation-error"
 class="field peer w-full px-4 py-2.5 pl-11 pr-11 text-sm" />
                <button type="button" data-toggle-password="password_confirmation" aria-label="{{ __('auth.register.show_password') }}" aria-pressed="false"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted transition-colors hover:text-brand-strong">
                    <svg class="icon-eye h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <svg class="icon-eye-off hidden h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
            </div>
            <p id="password_confirmation-error" class="mt-1 min-h-[1rem] text-xs text-danger" aria-live="polite"></p>
        </div>

        <button type="submit"
            class="btn btn-primary btn-block disabled:pointer-events-none disabled:opacity-70">
            <span class="btn-label" data-i18n="auth.register.submit">{{ __('auth.register.submit') }}</span>
        </button>
    </form>

    <p class="mt-3 text-center text-xs leading-relaxed text-muted/80 dark:text-night-muted/70" data-i18n="auth.register.terms_notice">{{ __('auth.register.terms_notice') }}</p>

    <p class="mt-4 text-center text-sm text-muted dark:text-night-muted">
        <span data-i18n="auth.register.have_account">{{ __('auth.register.have_account') }}</span>
        <a href="{{ route('login') }}" data-transition class="font-semibold text-brand-strong hover:underline" data-i18n="auth.register.login_link">{{ __('auth.register.login_link') }}</a>
    </p>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const nameInput = document.getElementById('name');
        nameInput?.addEventListener('blur', () => {
            if (!nameInput.value.trim()) markInvalid(nameInput, @json(__('auth.validation.name_required')));
        });
        nameInput?.addEventListener('input', () => {
            if (nameInput.value.trim()) clearInvalid(nameInput);
        });

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
        const confirmInput = document.getElementById('password_confirmation');
        const strengthBar = document.getElementById('strength-bar');
        const strengthLabel = document.getElementById('strength-label');

        passwordInput?.addEventListener('input', () => {
            if (!passwordInput.value) {
                strengthBar.style.width = '0%';
                strengthLabel.textContent = '';
            } else {
                const { percent, label, color } = passwordStrength(passwordInput.value);
                strengthBar.style.width = percent + '%';
                strengthBar.style.backgroundColor = color;
                strengthLabel.textContent = label;
            }
            if (passwordInput.value.length >= 8) clearInvalid(passwordInput);
            if (confirmInput.value) checkPasswordsMatch();
        });
        passwordInput?.addEventListener('blur', () => {
            if (passwordInput.value && passwordInput.value.length < 8) {
                markInvalid(passwordInput, @json(__('auth.validation.password_min')));
            }
        });

        confirmInput?.addEventListener('input', checkPasswordsMatch);

        function checkPasswordsMatch() {
            const errorEl = document.getElementById('password_confirmation-error');
            if (!confirmInput.value) {
                confirmInput.classList.remove('!border-danger', '!border-brand');
                errorEl.textContent = '';
                return;
            }
            if (confirmInput.value === passwordInput.value) {
                confirmInput.classList.remove('!border-danger');
                confirmInput.classList.add('!border-brand');
                confirmInput.setAttribute('aria-invalid', 'false');
                errorEl.textContent = @json(__('auth.register.password_match'));
                errorEl.classList.remove('text-danger');
                errorEl.classList.add('text-brand');
            } else {
                confirmInput.classList.remove('!border-brand');
                confirmInput.classList.add('!border-danger');
                confirmInput.setAttribute('aria-invalid', 'true');
                errorEl.textContent = @json(__('auth.register.password_mismatch'));
                errorEl.classList.remove('text-brand');
                errorEl.classList.add('text-danger');
            }
        }

        const registerForm = document.getElementById('registerForm');
        registerForm?.addEventListener('submit', (event) => handleAuthSubmit(event, {
            form: registerForm,
            validator: validateRegister,
        }));
    });

    function passwordStrength(value) {
        let score = 0;
        if (value.length >= 8) score++;
        if (value.length >= 12) score++;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
        if (/\d/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;

        // Read the ramp from the palette rather than hard-coding hexes, so the
        // meter re-skins with everything else. "Good" uses the brand orange,
        // which sits naturally between the warning amber and the success green.
        const swatch = (token) => getComputedStyle(document.documentElement).getPropertyValue(token).trim();

        if (score <= 1) return { percent: 25, label: @json(__('auth.register.strength_weak')), color: swatch('--color-danger') };
        if (score === 2) return { percent: 50, label: @json(__('auth.register.strength_fair')), color: swatch('--color-warning') };
        if (score === 3) return { percent: 75, label: @json(__('auth.register.strength_good')), color: swatch('--color-brand') };
        return { percent: 100, label: @json(__('auth.register.strength_strong')), color: swatch('--color-success') };
    }

    function validateRegister() {
        const form = document.getElementById('registerForm');
        const name = form.querySelector('#name');
        const email = form.querySelector('#email');
        const password = form.querySelector('#password');
        const confirmation = form.querySelector('#password_confirmation');
        [name, email, password, confirmation].forEach(clearInvalid);

        let firstError = null;
        if (!name.value.trim()) {
            markInvalid(name, @json(__('auth.validation.name_required')));
            firstError = firstError || @json(__('auth.validation.name_required'));
        }
        if (!isValidEmail(email.value)) {
            markInvalid(email, @json(__('auth.validation.email_invalid')));
            firstError = firstError || @json(__('auth.validation.email_invalid'));
        }
        if (password.value.length < 8) {
            markInvalid(password, @json(__('auth.validation.password_min')));
            firstError = firstError || @json(__('auth.validation.password_min'));
        }
        if (confirmation.value !== password.value) {
            markInvalid(confirmation, @json(__('auth.validation.password_mismatch')));
            firstError = firstError || @json(__('auth.validation.password_mismatch'));
        }
        return firstError;
    }
</script>
@endpush
