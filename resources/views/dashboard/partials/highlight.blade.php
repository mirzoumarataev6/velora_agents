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

@php
    /* THE TONE, and the reason this card stopped being a blue slab.

       A full-width accent-gradient bar was the second-loudest dated element on
       the page after the header band: a saturated rectangle that shouted at
       the owner regardless of whether the news was urgent or reassuring, and
       which in the "nothing to do" state shouted a compliment.

       It is a white card now, like everything else, and it earns its emphasis
       from an icon and a hairline in the tone the NEWS deserves:

         amber  — someone is waiting on the owner (a price, an order). This is
                  the only state that is genuinely urgent, and it is the state
                  the brief calls out by name.
         blue   — something to improve, but nobody is blocked.
         green  — all clear.

       Colour is never the only signal: each tone brings its own icon, and the
       eyebrow says which state it is in words. */
    $tone = match (true) {
        $copy === null => 'clear',
        in_array($highlight['kind'], ['price_approvals', 'open_orders'], true) => 'urgent',
        default => 'improve',
    };

    $toneClasses = [
        'urgent' => ['circle' => 'icon-circle-warning', 'accent' => 'text-warning dark:text-warning-light', 'wash' => 'bg-warning/[0.05] dark:bg-warning-light/[0.07]'],
        'improve' => ['circle' => '', 'accent' => 'text-brand-strong dark:text-brand-light', 'wash' => 'bg-azure-50 dark:bg-night-raised/60'],
        'clear' => ['circle' => 'icon-circle-success', 'accent' => 'text-success dark:text-success-light', 'wash' => ''],
    ][$tone];

    $toneIcon = [
        // A clock: someone is waiting.
        'urgent' => 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z',
        // A lightbulb: there is something to learn here.
        'improve' => 'M9.66 18h4.68M10 21h4M12 3a6 6 0 00-3.6 10.8c.42.32.7.8.75 1.32l.05.38h5.6l.05-.38c.05-.52.33-1 .75-1.32A6 6 0 0012 3z',
        // A tick: nothing to do.
        'clear' => 'M5 13l4 4L19 7',
    ][$tone];
@endphp

<div class="card card-lift reveal is-visible mt-4 {{ $toneClasses['wash'] }}">
    <div class="flex flex-wrap items-center gap-x-8 gap-y-4 p-5">
        <span class="icon-circle {{ $toneClasses['circle'] }} shrink-0" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $toneIcon }}" />
            </svg>
        </span>

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
                {{-- The one animated mark on the page allowed to loop, and only
                     when something is actually waiting: a pulse on "all clear"
                     is an alarm with nothing behind it. --}}
                @if ($tone === 'urgent')
                    <span class="relative flex h-2 w-2 shrink-0" aria-hidden="true">
                        <span class="pulse-ring absolute inline-flex h-full w-full rounded-full bg-warning opacity-60"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-warning"></span>
                    </span>
                @endif
                <p class="text-overline {{ $toneClasses['accent'] }}">
                    {{ __('dashboard.highlight.eyebrow') }}
                </p>
            </div>

            @if ($copy === null)
                <h2 class="mt-1.5 text-h4">{{ __('dashboard.highlight.clear_title') }}</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted dark:text-night-muted">{{ __('dashboard.highlight.clear') }}</p>

                {{-- With nothing to fix, the after-hours number finally has the
                     right job: a closing remark on a good week, rather than a
                     hero card of its own. --}}
                @if ($afterHours['messages'] > 0 && $afterHours['share'] !== null)
                    <p class="mt-2 text-xs text-faint dark:text-night-muted">
                        {{ __('dashboard.highlight.after_hours', [
                            'count' => $afterHours['messages'],
                            'percent' => $afterHours['share'],
                        ]) }}
                    </p>
                @endif
            @else
                <h2 class="mt-1.5 max-w-3xl text-h4">{{ $copy['headline'] }}</h2>
                @if ($copy['hint'])
                    <p class="mt-1 max-w-2xl text-sm text-muted dark:text-night-muted">{{ $copy['hint'] }}</p>
                @endif
            @endif
        </div>

        @if ($copy !== null && $highlight['route'])
            <a href="{{ route($highlight['route']) }}" class="btn btn-primary gap-2">
                {{ $copy['action'] }}
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
            </a>
        @endif
    </div>
</div>
