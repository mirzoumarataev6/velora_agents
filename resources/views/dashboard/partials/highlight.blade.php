{{--
    "Что сделать сейчас" — the one thing on this page that asks for a decision
    rather than reporting one.

    Replaces the old "работает, пока вы спите" card, which had the same visual
    weight and no call to action: it reported a number that was true, mildly
    flattering, and impossible to do anything about. This carries the same
    weight because the same weight is deserved — but every state of it ends in
    a link to the screen where the thing gets fixed.

    The insight is picked in DashboardMetrics::highlight(), which ranks the
    findings the rest of the page already made. Nothing is measured twice.

    Expects: $metrics.
--}}
@php
    $highlight = $metrics['highlight'];
    $afterHours = $metrics['after_hours'];

    // Each kind names its own sentence, its own follow-up line and its own
    // button. The card is one shape; only the words change.
    $copy = $highlight === null ? null : [
        'headline' => __('dashboard.highlight.'.$highlight['kind'], [
            'count' => $highlight['count'],
            'subject' => (string) $highlight['subject'],
        ]),
        'hint' => trans()->has('dashboard.highlight.'.$highlight['kind'].'_hint')
            ? __('dashboard.highlight.'.$highlight['kind'].'_hint')
            : null,
        'action' => __('dashboard.highlight.'.$highlight['kind'].'_action'),
    ];
@endphp

<div class="reveal is-visible mt-4 overflow-hidden rounded-3xl bg-gradient-to-br from-grad-from via-grad-via to-grad-to text-on-accent shadow-glow-ember">
    <div class="flex flex-wrap items-center gap-x-10 gap-y-6 p-6 sm:p-7">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2.5">
                {{-- The one animated mark on the page that is allowed to loop:
                     this card is the thing the owner is meant to look at first,
                     and the pulse is what earns it that half-second. --}}
                <span class="relative flex h-2.5 w-2.5 shrink-0" aria-hidden="true">
                    <span class="pulse-ring absolute inline-flex h-full w-full rounded-full bg-on-accent opacity-70"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-on-accent"></span>
                </span>
                <p class="text-xs font-semibold uppercase tracking-wide opacity-80">
                    {{ __('dashboard.highlight.eyebrow') }}
                </p>
            </div>

            @if ($copy === null)
                <h2 class="mt-3 font-display text-2xl leading-tight">{{ __('dashboard.highlight.clear_title') }}</h2>
                <p class="mt-2 max-w-xl text-sm opacity-90">{{ __('dashboard.highlight.clear') }}</p>

                {{-- With nothing to fix, the after-hours number finally has the
                     right job: a closing remark on a good week, rather than a
                     hero card of its own. --}}
                @if ($afterHours['messages'] > 0 && $afterHours['share'] !== null)
                    <p class="mt-3 text-xs opacity-75">
                        {{ __('dashboard.highlight.after_hours', [
                            'count' => $afterHours['messages'],
                            'percent' => $afterHours['share'],
                        ]) }}
                    </p>
                @endif
            @else
                <h2 class="mt-3 max-w-2xl font-display text-2xl leading-tight">{{ $copy['headline'] }}</h2>
                @if ($copy['hint'])
                    <p class="mt-2 max-w-xl text-sm opacity-90">{{ $copy['hint'] }}</p>
                @endif
            @endif
        </div>

        @if ($copy !== null && $highlight['route'])
            <a href="{{ route($highlight['route']) }}"
                class="flex items-center gap-2 rounded-full bg-on-accent/15 px-5 py-2.5 text-sm font-semibold ring-1 ring-inset ring-on-accent/25 transition-all hover:-translate-y-0.5 hover:bg-on-accent/25">
                {{ $copy['action'] }}
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </a>
        @endif
    </div>
</div>
