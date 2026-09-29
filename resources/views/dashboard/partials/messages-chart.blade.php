{{--
    Messages per day.

    WHAT THIS REPLACED: seven ~90px-wide blue slabs on a mostly empty 160px
    field. Stacked bars made sense when the card had to carry a channel
    breakdown as well as a volume, but at seven points on a two-thirds-width
    card they were simply the loudest object on the page carrying the least
    information per pixel.

    Now: a smooth area chart for the shape, and the channel breakdown moved to
    a row of shares underneath, where it reads as a ranked list rather than as
    colour-matching against a legend. The card also gained the thing it most
    needed — a sentence saying what the chart MEANS, computed from the same
    series rather than written in.

    Expects: $metrics.
--}}
@include('partials.chart-helpers')

@php
    $series = $metrics['series'];
    $range = $metrics['range'];
    $channels = $metrics['channels'];
    $periodTotal = array_sum(array_column($series, 'total'));
    $values = array_column($series, 'total');

    $trend = velora_trend($values);

    /* THE INSIGHT LINE, computed three ways in priority order — whichever is
       true gets said. Nothing here is invented: every number comes out of the
       same $series the chart is drawn from.

         1. The busiest channel, when more than one is connected. This is the
            fact an owner acts on — it tells them where their customers
            actually are.
         2. The busiest single day, when only one channel is connected and
            there is a clear peak.
         3. The plain total, when neither of the above says anything. */
    $byChannel = [];
    foreach ($series as $day) {
        foreach ($day['by_channel'] ?? [] as $channel => $count) {
            $byChannel[$channel] = ($byChannel[$channel] ?? 0) + $count;
        }
    }
    arsort($byChannel);

    $insight = null;

    if (count($byChannel) > 1 && $periodTotal > 0) {
        $topChannel = array_key_first($byChannel);
        $insight = __('dashboard.messages_chart.insight_channel', [
            'channel' => __('dashboard.channels.'.$topChannel),
            'percent' => (int) round($byChannel[$topChannel] / $periodTotal * 100),
        ]);
    } elseif ($periodTotal > 0) {
        $peakDay = collect($series)->sortByDesc('total')->first();

        // Only worth saying when the peak actually stands out; on a flat week
        // "the busiest day was Tuesday" is noise dressed as a finding.
        if ($peakDay && $peakDay['total'] > 0 && $peakDay['total'] >= $periodTotal / count($series) * 1.5) {
            $insight = __('dashboard.messages_chart.insight_peak', [
                'day' => $peakDay['label'],
                'count' => $peakDay['total'],
            ]);
        }
    }
@endphp

<div class="card card-lift flex h-full flex-col p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="section-head-title">{{ __('dashboard.messages_chart.title') }}</h2>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <span class="kpi-value kpi-value-sm" data-countup="{{ $periodTotal }}">{{ number_format($periodTotal, 0, '.', ' ') }}</span>
                @if ($trend !== null)
                    <span class="{{ $trend['direction'] === 'up' ? 'trend-up trend-good' : ($trend['direction'] === 'down' ? 'trend-down trend-bad' : 'trend-flat') }}"
                        aria-label="{{ __('dashboard.trend.'.$trend['direction'], ['percent' => $trend['percent']]) }}">
                        {{ $trend['percent'] }}%
                    </span>
                @endif
                <span class="text-xs text-muted dark:text-night-muted">
                    {{ __('dashboard.messages_chart.range_'.$range) }}
                </span>
            </div>
        </div>
    </div>

    {{-- The insight. Deliberately above the chart, not below it: it is the
         answer, and the chart is the working. --}}
    @if ($insight)
        <p class="mt-2 text-sm text-muted dark:text-night-muted">{{ $insight }}</p>
    @endif

    @if ($periodTotal === 0)
        <div class="mt-4 flex-1">
            <div class="empty-inline">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>
                <span>{{ __('dashboard.messages_chart.empty') }}</span>
            </div>
        </div>
    @else
        <div class="mt-4 flex-1">
            <x-area-chart
                :points="array_map(fn ($day) => ['label' => $day['label'], 'value' => $day['total']], $series)"
                :height="200"
                :aria-label="__('dashboard.messages_chart.title')" />
        </div>

        {{-- The channel split, as ranked shares rather than as a stack. A
             reader gets the ordering and the proportion without having to
             match a colour to a legend entry. --}}
        @if (count($byChannel) > 1)
            <div class="rule mt-4 space-y-2 pt-4">
                @foreach (array_slice($byChannel, 0, 4, true) as $channel => $count)
                    @php $share = (int) round($count / max(1, $periodTotal) * 100); @endphp
                    <div class="flex items-center gap-3">
                        <span class="w-28 shrink-0 truncate text-xs text-muted dark:text-night-muted">{{ __('dashboard.channels.'.$channel) }}</span>
                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunk dark:bg-night-raised">
                            {{-- Tints off the one accent rather than new hues:
                                 the palette has a single accent by design, and
                                 a second colour here would be inventing one. --}}
                            <span class="block h-full rounded-full bg-brand transition-[width] duration-slow ease-out"
                                style="width: {{ max(2, $share) }}%; opacity: {{ max(0.35, 1 - $loop->index * 0.2) }}"></span>
                        </span>
                        <span class="tnum w-14 shrink-0 text-right text-xs text-muted dark:text-night-muted">{{ $count }} · {{ $share }}%</span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
