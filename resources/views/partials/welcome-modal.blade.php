@php
    /**
     * The one-time "Welcome to Velora" moment, shown on the first dashboard
     * page a newly created account lands on.
     *
     * Show-once is enforced HERE, by stamping welcomed_at in the very request
     * that renders the markup — not by a click handler and not by a session
     * flash. Closing the tab, reloading, a dropped fetch or a redirect landing
     * somewhere unexpected therefore cannot bring it back: by the time the HTML
     * reaches the browser, the account is already recorded as welcomed.
     *
     * Gated by the layout on $user->awaitsWelcome(); the stamp is idempotent so
     * a double include would still only greet once.
     */
    $__welcomeUser = auth()->user();
    $__welcomeUser->markWelcomed();
@endphp

<div x-data="welcomeModal({ agentsUrl: '{{ route('agents.index') }}' })" x-init="open()" x-cloak
    x-show="shown"
    @keydown.escape.window="dismiss()"
    class="fixed inset-0 z-[110] flex items-end justify-center p-4 sm:items-center"
    role="dialog" aria-modal="true" aria-labelledby="welcome-title">

    <div class="welcome-backdrop absolute inset-0 bg-black/40 backdrop-blur-sm" :class="entered && 'show'"
        @click="dismiss()" aria-hidden="true"></div>

    <div x-ref="card" @keydown.tab="trapFocus($event)"
        class="card welcome-card relative w-full max-w-md overflow-hidden"
        :class="entered && 'show'">

        {{-- The accent: a soft brand wash bled in from the top rather than a
             solid colour band, so the card reads as premium rather than as an
             alert. Purely decorative, hence aria-hidden. --}}
        <div class="pointer-events-none absolute inset-x-0 -top-24 h-48 bg-gradient-to-b from-brand/25 via-brand/5 to-transparent blur-2xl" aria-hidden="true"></div>

        <button type="button" @click="dismiss()"
            aria-label="{{ __('onboarding.welcome.close') }}" data-i18n-al="onboarding.welcome.close"
            class="absolute right-4 top-4 z-10 flex h-8 w-8 items-center justify-center rounded-full text-muted transition-colors hover:bg-sunk dark:text-night-muted dark:hover:bg-night-raised">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <div class="relative px-6 pb-6 pt-8 sm:px-8 sm:pb-8">
            <img src="{{ asset('images/brand/gen/mark.webp') }}?v={{ config('agent.brand_mark_version') }}" width="48" height="48"
                alt="" class="h-12 w-12" decoding="async" aria-hidden="true" />

            <p data-i18n="onboarding.welcome.eyebrow"
                class="mt-6 text-xs font-semibold uppercase tracking-[0.14em] text-brand-strong">{{ __('onboarding.welcome.eyebrow') }}</p>

            {{-- Not tagged for the instant locale swap: it interpolates the
                 owner's name, which the swap engine's plain text replacement
                 cannot rebuild. The dashboard's switcher reloads anyway. --}}
            <h2 id="welcome-title" class="mt-2 text-2xl font-semibold leading-snug">
                {{ __('onboarding.welcome.title', ['name' => $__welcomeUser->name]) }}
            </h2>

            <p data-i18n="onboarding.welcome.body"
                class="mt-3 text-sm leading-relaxed text-muted dark:text-night-muted">{{ __('onboarding.welcome.body') }}</p>

            <div class="mt-7 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:gap-3">
                <button type="button" @click="dismiss()" data-i18n="onboarding.welcome.later"
                    class="rounded-full px-4 py-2.5 text-sm font-medium text-muted transition-colors hover:bg-sunk dark:text-night-muted dark:hover:bg-night-raised">
                    {{ __('onboarding.welcome.later') }}
                </button>
                <button type="button" x-ref="primary" @click="start()" data-i18n="onboarding.welcome.cta"
                    class="btn btn-primary flex-1">
                    {{ __('onboarding.welcome.cta') }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window.welcomeModal = function welcomeModal({ agentsUrl }) {
        return {
            shown: true,
            // Separate from `shown` so the element is in the DOM for a frame
            // before the transition classes go on — otherwise the browser has
            // nothing to animate from and the card simply appears.
            entered: false,

            open() {
                document.body.style.overflow = 'hidden';
                requestAnimationFrame(() => {
                    this.entered = true;
                    this.$refs.primary?.focus();
                });
            },

            close() {
                this.entered = false;
                document.body.style.overflow = '';
                // Matches the longest transition on .welcome-card.
                setTimeout(() => { this.shown = false; }, 400);
            },

            dismiss() {
                this.close();
            },

            /**
             * Hand the owner straight to the checklist. On any dashboard page
             * other than Agents there is nothing to scroll to, so this
             * navigates there instead of quietly doing nothing.
             */
            start() {
                const target = document.getElementById('onboarding');
                if (!target) {
                    window.location.assign(agentsUrl + '#onboarding');
                    return;
                }
                this.close();
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            },

            /**
             * Keep Tab inside the dialog. The card holds a known, small set of
             * controls, so querying them on each keypress is cheaper than
             * maintaining a cached list that a re-render could invalidate.
             */
            trapFocus(event) {
                const focusable = this.$refs.card.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            },
        };
    };
</script>
