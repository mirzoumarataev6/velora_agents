@php
    /**
     * The first-run checklist, shown above the agents grid.
     *
     * Every step's state is computed fresh by OnboardingService from the
     * account's real data (see AgentController@index) — nothing here is stored
     * progress that could disagree with what the rest of the dashboard shows,
     * and a step completed before this card was ever seen arrives already
     * ticked. Only the skip is persisted.
     *
     * Expects: $onboarding (the OnboardingService::forUser() array).
     */
    $__nextIndex = null;
    foreach ($onboarding['steps'] as $__i => $__step) {
        if (! $__step['done']) {
            $__nextIndex = $__i;
            break;
        }
    }
@endphp

<div id="onboarding" x-data="onboardingCard({ dismissUrl: '{{ route('onboarding.dismiss') }}' })"
    x-show="visible" x-cloak
    class="onboarding-card reveal is-visible mb-6 overflow-hidden rounded-3xl bg-white/70 dark:bg-night-raised"
    :class="leaving && 'is-leaving'">

    @if ($onboarding['celebrate'])
        {{-- Shown exactly once: AgentController stamps onboarding_completed_at
             when this branch renders, so the next load has no card at all. --}}
        <div class="relative px-5 py-8 text-center sm:px-8">
            <div class="pointer-events-none absolute inset-x-0 -top-20 h-40 bg-gradient-to-b from-success/20 via-success/5 to-transparent blur-2xl" aria-hidden="true"></div>

            <div class="relative mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-success/10 text-success">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>

            <h2 data-i18n="onboarding.complete.title" class="relative mt-4 text-lg font-semibold">{{ __('onboarding.complete.title') }}</h2>
            <p data-i18n="onboarding.complete.body" class="relative mx-auto mt-2 max-w-md text-sm text-muted dark:text-night-muted">{{ __('onboarding.complete.body') }}</p>

            <button type="button" @click="collapse()" data-i18n="onboarding.complete.cta"
                class="btn btn-primary relative mt-6">
                {{ __('onboarding.complete.cta') }}
            </button>
        </div>
    @else
        <div class="px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 data-i18n="onboarding.checklist.title" class="text-base font-semibold">{{ __('onboarding.checklist.title') }}</h2>
                    <p data-i18n="onboarding.checklist.subtitle" class="mt-0.5 text-sm text-muted dark:text-night-muted">{{ __('onboarding.checklist.subtitle') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-xs font-semibold tabular-nums text-brand-strong">
                        {{ __('onboarding.checklist.progress', ['done' => $onboarding['completed'], 'total' => $onboarding['total']]) }}
                    </span>
                    <button type="button" @click="skip()" data-i18n="onboarding.checklist.skip"
                        class="rounded-full px-2.5 py-1 text-xs font-medium text-muted transition-colors hover:bg-sunk dark:text-night-muted dark:hover:bg-night-raised">
                        {{ __('onboarding.checklist.skip') }}
                    </button>
                </div>
            </div>

            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-sunk dark:bg-night-raised"
                role="progressbar" aria-valuemin="0" aria-valuemax="{{ $onboarding['total'] }}"
                aria-valuenow="{{ $onboarding['completed'] }}">
                <div class="h-full rounded-full bg-brand transition-[width] duration-500 ease-out" style="width: {{ $onboarding['percent'] }}%"></div>
            </div>

            <ol class="mt-5 space-y-1">
                @foreach ($onboarding['steps'] as $i => $step)
                    @php $__isNext = $i === $__nextIndex; @endphp
                    <li class="flex items-center gap-3 rounded-2xl px-3 py-3 transition-colors {{ $__isNext ? 'bg-brand/5' : '' }}">
                        {{-- Filled disc when done, dashed ring when not: the
                             checklist should be readable at a glance without
                             relying on colour alone. --}}
                        @if ($step['done'])
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success text-white">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span class="sr-only">{{ __('onboarding.checklist.done_label') }}</span>
                            </span>
                        @else
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-dashed text-[11px] font-semibold {{ $__isNext ? 'border-brand text-brand' : 'border-black/15 text-muted dark:border-white/20 dark:text-night-muted' }}">
                                {{ $i + 1 }}
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p data-i18n="onboarding.steps.{{ $step['key'] }}.title"
                                class="truncate text-sm font-medium {{ $step['done'] ? 'text-muted dark:text-night-muted' : '' }}">
                                {{ __("onboarding.steps.{$step['key']}.title") }}
                            </p>
                            @unless ($step['done'])
                                <p data-i18n="onboarding.steps.{{ $step['key'] }}.description"
                                    class="mt-0.5 text-xs text-muted dark:text-night-muted">{{ __("onboarding.steps.{$step['key']}.description") }}</p>
                            @endunless
                        </div>

                        {{-- Only the first incomplete step gets a button, so
                             there is always exactly one obvious next action
                             rather than four competing ones. --}}
                        @if ($__isNext)
                            <a href="{{ $step['cta'] }}" data-i18n="onboarding.steps.{{ $step['key'] }}.cta"
                                class="btn btn-primary shrink-0">
                                {{ __("onboarding.steps.{$step['key']}.cta") }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>

<script>
    window.onboardingCard = function onboardingCard({ dismissUrl }) {
        return {
            visible: true,
            leaving: false,

            /**
             * Collapse without persisting. Used by the congratulations panel,
             * which is already spent server-side — the account's
             * onboarding_completed_at was stamped when it rendered, so there is
             * nothing left to record.
             */
            collapse() {
                this.leaving = true;
                setTimeout(() => { this.visible = false; }, 300);
            },

            /**
             * Skip. Collapses immediately and records the choice in the
             * background: the owner asked for the card to go away, and making
             * them wait on a round-trip to see it happen would be worse than
             * the card briefly reappearing on the next load if the write fails.
             */
            skip() {
                this.collapse();
                fetch(dismissUrl, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    keepalive: true,
                }).catch(() => {});
            },
        };
    };
</script>
