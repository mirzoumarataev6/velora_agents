{{--
    Conversations → заявки → confirmed, as tapering bars.

    Each bar is drawn as a share of the FIRST stage, not of the one above it, so
    the taper is honest: a stage that keeps 90% of a stage that kept 10% should
    still look small. The drop-off percentage beside it is the stage-to-stage
    number, which is the one worth acting on.

    Expects: $metrics.
--}}
@php
    $funnel = $metrics['funnel'];
    $labels = [
        'conversations' => __('dashboard.funnel.conversations'),
        'leads' => __('dashboard.funnel.leads'),
        'confirmed' => __('dashboard.funnel.confirmed'),
    ];
    $tints = ['conversations' => 'bg-brand', 'leads' => 'bg-brand/70', 'confirmed' => 'bg-brand/45'];

    // Every stage carries its own definition now, not just the last one. The
    // widget's clarity problem was never the bars — it was that "Воронка" and
    // three nouns assume the reader already knows what is being counted.
    $hints = [
        'conversations' => __('dashboard.funnel.conversations_hint'),
        'leads' => __('dashboard.funnel.leads_hint'),
        'confirmed' => __('dashboard.funnel.confirmed_hint'),
    ];
@endphp

<div class="card p-5">
    <h2 class="section-head-title">{{ __('dashboard.funnel.title') }}</h2>
    <p class="mt-1 text-xs text-muted dark:text-night-muted">{{ __('dashboard.funnel.description') }}</p>

    <div class="mt-5 space-y-4">
        @foreach ($funnel as $stage)
            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-sm font-medium">
                        {{ $labels[$stage['key']] }}
                        <span class="ml-1 cursor-help text-xs font-normal text-muted dark:text-night-muted" title="{{ $hints[$stage['key']] }}">ⓘ</span>
                    </span>
                    <span class="flex items-baseline gap-2">
                        {{-- Neutral analytical framing, not a loss: a customer
                             not converting at this step is what happened, not
                             a failure on the business's part. --}}
                        @if ($stage['conversion'] !== null)
                            <span class="text-xs text-muted dark:text-night-muted">{{ __('dashboard.funnel.conversion', ['percent' => $stage['conversion']]) }}</span>
                        @endif
                        <span class="font-display text-xl leading-none">{{ $stage['value'] }}</span>
                    </span>
                </div>

                <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-sunk dark:bg-night-raised">
                    <div class="h-full rounded-full {{ $tints[$stage['key']] }} transition-all duration-slow ease-out"
                        style="width: {{ $stage['value'] === 0 ? 0 : max(2, $stage['share']) }}%"></div>
                </div>

                <p class="mt-1 text-[11px] text-muted dark:text-night-muted">
                    {{ __('dashboard.funnel.of_all', ['percent' => $stage['share']]) }}
                </p>
            </div>
        @endforeach
    </div>
</div>
