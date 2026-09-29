@php
    /**
     * The sign-up greeting: a standalone card of its own, sitting above the
     * onboarding checklist rather than inside it.
     *
     * Rendering it claims it, which means three things at once: the session key
     * is spent here, so the layout finds nothing to toast; the account is
     * stamped welcomed, so the layout's welcome modal (which greets by name
     * too) does not open on this page or a later one; and the banner therefore
     * lasts exactly one page view, which is what makes reloading or navigating
     * away end it with nothing persisted about having dismissed it.
     *
     * Included only where the checklist is (see agents/index), because that is
     * the rule it implements — one greeting per arrival, the banner where there
     * is a checklist to pin it above, the toast everywhere else.
     *
     * The expectsJson() guard is the same one the layout uses: the auth forms
     * POST with fetch(), which renders this page once into HTML it discards.
     */
    $__greetingName = null;

    if (! request()->expectsJson()
        && session(\App\Http\Controllers\AuthController::GREETING_SESSION_KEY) === \App\Http\Controllers\AuthController::GREETING_WELCOME) {
        session()->forget(\App\Http\Controllers\AuthController::GREETING_SESSION_KEY);
        $__greetingName = auth()->user()->name;
        auth()->user()->markWelcomed();
    }
@endphp

@if ($__greetingName)
    {{-- Purely client-side state: the × hides it for this page view, and there
         is nothing to record because the greeting was already spent server-side
         by the block above. Not tagged for the instant locale swap — it
         interpolates the owner's name, which the swap engine's plain text
         replacement cannot rebuild; the dashboard's switcher reloads. --}}
    <div x-data="{ shown: true }" x-show="shown" x-cloak role="status"
        class="reveal is-visible mb-4 flex items-center gap-3 rounded-3xl border-brand/20 bg-brand-circle px-5 py-4 sm:px-6">
        <p class="min-w-0 flex-1 text-sm font-semibold text-brand-strong">
            {{ __('onboarding.welcome_greeting', ['name' => $__greetingName]) }}
        </p>
        <button type="button" @click="shown = false"
            aria-label="{{ __('onboarding.welcome.close') }}" data-i18n-al="onboarding.welcome.close"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-brand-strong transition-colors hover:bg-brand/15">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
@endif
